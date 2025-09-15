# Hexis Email Archive Bundle

A Symfony bundle that archives every email dispatched through the Mailer component to a structured directory on disk.

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
| `archive_root`         | `%kernel.project_dir%/var/email` | Root directory used for storing archives and indexes.                             |
| `max_preview_bytes`    | `2_000_000`                      | Maximum number of bytes to persist for generated previews before truncation.      |
| `max_attachment_bytes` | `50_000_000`                     | Maximum number of bytes that will be stored for any single attachment.            |
| `ignore_rules`         | see below                        | Define senders, recipients, subjects, or templates that should never be archived. |

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

## What gets archived?

When an email is sent, the bundle listens to `SentMessageEvent`, captures the original message, and writes the following
payloads beneath `${archive_root}/YYYY/MM/DD/<archiveId>/`:

- `message.eml` — the raw MIME message.
- `preview.html` or `preview.txt` — the first HTML or text body found in the message, truncated to `max_preview_bytes`
  and annotated with `<!-- truncated -->` when shortened.
- `attachments/` — decoded attachments saved with numeric prefixes, truncated to `max_attachment_bytes` if necessary.
- `meta.json` — metadata describing sender, recipients, subject, transport, hashes, attachment details, and a relative
  archive path.

The constructor also ensures the root contains an `.gitignore` to prevent accidental VCS commits and creates an `index`
directory populated with daily NDJSON files (`index/YYYY-MM-DD.ndjson`) that summarize each archived message for quick
lookup.

## Skipping archives

Use one of the following mechanisms to prevent an email from being archived:

- Add an `X-Archive-Skip: true` header to the message.
- Match an address, subject, or template through the configured `ignore_rules`.

## Manual usage

Although the bundle wires everything automatically, you can
inject `Hexis\EmailArchiveBundle\Service\EmailArchiveService` and call either of the following methods:

```php
$archiveService->archiveSent($sentMessage); // accepts Symfony\Component\Mailer\SentMessage
$archiveService->archiveEmail($rawMessage, $envelope); // accepts RawMessage/Email + optional Envelope
```

Both methods share the same persistence pipeline as the event subscriber.
