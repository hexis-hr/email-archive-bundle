# Hexis Email Archive Bundle

A Symfony bundle that archives every email dispatched through the Mailer component, to a structured directory on the
local disk or in a [Flysystem](https://flysystem.thephpleague.com/) filesystem (S3, ...).

Requires PHP 8.1+ and Symfony 6.2, 7 or 8.

## Installation

```bash
composer require hexis-hr/email-archive-bundle
```

The bundle relies on Symfony's auto-discovery, so no manual registration is required.

## Configuration

Create `config/packages/email_archive.yaml` and adjust the options as needed:

```yaml
email_archive:
  enabled: '%env(bool:EMAIL_ARCHIVE_ENABLED)%'
  archive_root: '%kernel.project_dir%/var/email'

  # Optional configuration
  ignore_rules: '%env(json:EMAIL_ARCHIVE_IGNORE_RULES)%'
  max_preview_bytes: '%env(int:EMAIL_ARCHIVE_MAX_PREVIEW_BYTES)%'
  max_attachment_bytes: '%env(int:EMAIL_ARCHIVE_MAX_ATTACHMENT_BYTES)%'
```

### Options

| Option                 | Default                          | Description                                                                       |
|------------------------|----------------------------------|-----------------------------------------------------------------------------------|
| `enabled`              | `true`                           | Toggles the archive without removing the event subscriber.                        |
| `archive_root`         | see below                        | Local directory, or the prefix inside `storage.service`.                          |
| `storage.service`      | `null`                           | Id of a `League\Flysystem\FilesystemOperator` service; `null` keeps the local disk. |
| `max_preview_bytes`    | `2_000_000`                      | Maximum number of bytes to persist for generated previews before truncation.      |
| `max_attachment_bytes` | `50_000_000`                     | Maximum number of bytes that will be stored for any single attachment.            |
| `ignore_rules`         | see below                        | Define senders, recipients, subjects, or templates that should never be archived. |

`archive_root` defaults to `%kernel.project_dir%/var/email` on the local disk and to `email` inside a Flysystem
storage.

### Ignore rules

`ignore_rules` accepts a structure with the following keys:

```yaml
ignore_rules:
  from: [ 'no-reply@example.com' ]
  to: [ 'alerts@example.com' ]
  subject_regex: [ '/^\[Debug\]/i', 'preview only' ]
  templates: [ 'transactional/invoice' ]
```

All comparisons are case-insensitive. `subject_regex` entries may contain full regular expressions (e.g. `/pattern/i`)
or plain substrings. When supplied through an environment variable, encode the structure as JSON. For example:

```dotenv
EMAIL_ARCHIVE_IGNORE_RULES="{\"from\":[\"no-reply@example.com\"],\"templates\":[\"transactional/invoice\"]}"
```

## Storage

### Local disk (default)

Without `storage.service`, archives are written below `archive_root` on the local disk. Each file is written to a
temporary file and renamed, so a reader never sees a partial file. The service also makes sure the root contains a
`.gitignore` (to prevent accidental VCS commits) and an `index` directory.

The local disk suits one server, or several servers that share one filesystem with working file locks. Several
servers that each have their own disk each hold only the e-mails they sent.

### Flysystem (S3, ...)

Point `storage.service` at any `League\Flysystem\FilesystemOperator` service; `archive_root` becomes the prefix inside
it. The option needs `league/flysystem` (3.x): `composer require league/flysystem`. Configuring it without the package
fails when the container is built.

```yaml
email_archive:
  archive_root: 'email'
  storage:
    service: 'League\Flysystem\FilesystemOperator'
```

Object storage has no directories, so no `.gitignore` and no empty `attachments/` directory are written there.

### The daily index

Every archive adds one NDJSON line (archive id, message id, subject, sender, recipients, sent at, size, path,
attachment counts) to the index of its day. The index must be safe when several Messenger workers or servers archive
at the same time:

- **Local disk:** the line is appended to `index/YYYY-MM-DD.ndjson` under an exclusive lock (`LOCK_EX`), so
  concurrent writers never interleave lines.
- **Flysystem:** appending is not atomic on object storage (it is a read, modify and write of the whole object, and two
  workers would lose each other's lines). Every archive therefore writes its own single-line file,
  `index/YYYY-MM-DD/<archiveId>.ndjson`. A new key per archive cannot race; concatenating a day's files gives the same
  NDJSON as the local index.

## What gets archived?

When an email is sent, the bundle listens to `SentMessageEvent`, captures the sent message, and writes the following
payloads beneath `${archive_root}/YYYY/MM/DD/<archiveId>/`:

- `message.eml` - the raw MIME message.
- `preview.html` or `preview.txt` - the first HTML or text body found in the message, truncated to `max_preview_bytes`
  and annotated with `<!-- truncated -->` when shortened.
- `attachments/` - decoded attachments saved with numeric prefixes, truncated to `max_attachment_bytes` if necessary.
- `meta.json` - metadata describing sender, recipients, subject, transport, hashes, attachment details, and the archive
  path relative to the archive root.

The files are written in this order, then the index entry: an archive with a `meta.json` and an index entry is
complete.

## Error isolation

`SentMessageEvent` is dispatched after the transport delivered the message. An archive failure (disk full, missing
permissions, storage unavailable, a failing `EmailArchivedEvent` listener) is therefore never thrown: the subscriber
logs it as an error with the exception and the message id, and the email stays sent. Without that, the caller would
see an error for a delivered email, and a Messenger retry would send it again. The archive service itself is only
built when an email is sent and the archive is enabled, inside the same guard.

## Archive id and `EmailArchivedEvent`

After an email was archived completely, the bundle dispatches
`Hexis\EmailArchiveBundle\Event\EmailArchivedEvent` with:

- `getArchiveId()` - the archive id, e.g. `143005_0123456789abcdef`;
- `getPath()` - the archive directory relative to the archive root, e.g. `2026/10/16/143005_0123456789abcdef`;
- `getMessage()` - the archived message (through the subscriber, the MIME source of the sent message);
- `getTransport()` - the transport name passed to `archiveSent()` (`null` through the subscriber: `SentMessageEvent`
  does not carry it);
- `getHeader($name)` - a header of the archived message, from an `Email` or from a MIME source.

A host links its own records to the archive with a listener, for example through a correlation header it set:

```php
#[AsEventListener]
public function __invoke(EmailArchivedEvent $event): void
{
    $correlationId = $event->getHeader('X-System-Email-Id');
    if ($correlationId !== null) {
        // Store $event->getArchiveId() on the host's record for $correlationId.
    }
}
```

The listener runs inside the error guard: if it fails, the failure is logged and the email stays sent.

## Retention

The bundle never deletes anything: every archived email, its attachments and its index entry are kept until the host
deletes them. Archives contain personal data (addresses, content, attachments); retention periods, erasure requests
and access control are the host's responsibility, for example with a scheduled job that removes the day directories
and index files (or the object storage's lifecycle rules) past the retention period.

## Skipping archives

Use one of the following mechanisms to prevent an email from being archived:

- Add an `X-Archive-Skip: true` header to the message.
- Match an address, subject, or template through the configured `ignore_rules`.

## Manual usage

Although the bundle wires everything automatically, you can
inject `Hexis\EmailArchiveBundle\Service\EmailArchiveService` and call either of the following methods:

```php
$archiveId = $archiveService->archiveSent($sentMessage); // accepts Symfony\Component\Mailer\SentMessage
$archiveId = $archiveService->archiveEmail($rawMessage, $envelope); // accepts RawMessage/Email + optional Envelope
```

Both methods share the same persistence pipeline as the event subscriber, dispatch `EmailArchivedEvent`, and return the
archive id, or `null` when an ignore rule or the `X-Archive-Skip` header skipped the message. Called directly, they
throw on failure: the error guard belongs to the subscriber.

## Development

```bash
composer update --prefer-stable
vendor/bin/phpunit
```

The CI workflow runs the suite on PHP 8.2 with Symfony 6.4, PHP 8.3 with 7.4 and PHP 8.4 with 8.
