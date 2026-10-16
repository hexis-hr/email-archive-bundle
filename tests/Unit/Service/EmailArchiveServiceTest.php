<?php

namespace Hexis\EmailArchiveBundle\Tests\Unit\Service;

use Hexis\EmailArchiveBundle\Event\EmailArchivedEvent;
use Hexis\EmailArchiveBundle\Service\EmailArchiveService;
use Hexis\EmailArchiveBundle\Storage\ArchiveStorage;
use Hexis\EmailArchiveBundle\Storage\FlysystemArchiveStorage;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class EmailArchiveServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hexis-email-archive-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testEmailIsArchivedUnderItsDayWithMetadataPreviewAndIndex(): void
    {
        $email = $this->email()
            ->cc('accounts@example.com')
            ->text('Plain body')
            ->html('<p>Invoice 42</p>');

        $archiveId = $this->service()->archiveEmail($email, null);

        $archive = $this->onlyArchive();
        self::assertMatchesRegularExpression('/^\d{6}_[0-9a-f]{16}$/', (string) $archiveId);
        self::assertSame($archiveId, $archive['archiveId']);
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2}/' . $archiveId . '$#', $archive['path']);
        self::assertSame('Invoice 42', $archive['subject']);
        self::assertSame('billing@example.com', $archive['from']);
        self::assertSame(['customer@example.com'], $archive['to']);
        self::assertSame(['accounts@example.com'], $archive['cc']);
        self::assertSame('default', $archive['transport']);
        self::assertTrue($archive['hasPreview']);
        self::assertSame(0, $archive['attachmentsCount']);

        $dir = $this->root . '/' . $archive['path'];
        self::assertStringContainsString('Subject: Invoice 42', (string) file_get_contents($dir . '/message.eml'));
        self::assertSame('<p>Invoice 42</p>', file_get_contents($dir . '/preview.html'));
        self::assertFileDoesNotExist($dir . '/preview.txt');
        self::assertDirectoryExists($dir . '/attachments');
        self::assertFileExists($this->root . '/.gitignore');

        $index = $this->indexLines($archive);
        self::assertCount(1, $index);
        self::assertSame($archive['archiveId'], $index[0]['archiveId']);
        self::assertSame($archive['path'], $index[0]['path']);
    }

    public function testTextOnlyEmailGetsATextPreview(): void
    {
        $this->service()->archiveEmail($this->email()->text('Plain body'), null);

        $dir = $this->root . '/' . $this->onlyArchive()['path'];
        self::assertSame('Plain body', file_get_contents($dir . '/preview.txt'));
        self::assertFileDoesNotExist($dir . '/preview.html');
    }

    public function testPreviewIsTruncatedToTheLimit(): void
    {
        $this->service(maxPreviewBytes: 10)->archiveEmail($this->email()->html('<p>0123456789abcdef</p>'), null);

        $dir = $this->root . '/' . $this->onlyArchive()['path'];
        self::assertSame("<p>0123456\n<!-- truncated -->", file_get_contents($dir . '/preview.html'));
    }

    public function testAttachmentsAreStoredWithTheirMetadata(): void
    {
        $email = $this->email()->text('See attached')->attach('hello world', 'report.txt', 'text/plain');

        $this->service()->archiveEmail($email, null);

        $archive = $this->onlyArchive();
        self::assertSame('hello world', file_get_contents($this->root . '/' . $archive['path'] . '/attachments/1_report.txt'));
        self::assertSame(1, $archive['attachmentsCount']);
        self::assertSame(11, $archive['attachmentsBytes']);
        self::assertSame('1_report.txt', $archive['attachmentsMeta'][0]['filename']);
        self::assertSame('report.txt', $archive['attachmentsMeta'][0]['originalName']);
        self::assertSame('text/plain', $archive['attachmentsMeta'][0]['contentType']);
        self::assertSame(11, $archive['attachmentsMeta'][0]['size']);
        self::assertSame(hash('sha256', 'hello world'), $archive['attachmentsMeta'][0]['sha256']);
    }

    public function testAttachmentIsTruncatedToTheLimit(): void
    {
        $email = $this->email()->text('See attached')->attach('hello world', 'report.txt', 'text/plain');

        $this->service(maxAttachmentBytes: 5)->archiveEmail($email, null);

        $archive = $this->onlyArchive();
        self::assertSame('hello', file_get_contents($this->root . '/' . $archive['path'] . '/attachments/1_report.txt'));
        self::assertSame(5, $archive['attachmentsMeta'][0]['size']);
    }

    public function testSentMessageIsArchivedFromItsMimeSource(): void
    {
        $email = $this->email()
            ->html('<p>Invoice 42</p>')
            ->attach('%PDF-1.4 fake', 'invoice.pdf', 'application/pdf');
        $sent = new SentMessage($email, Envelope::create($email));

        $archiveId = $this->service()->archiveSent($sent, 'smtp');

        $archive = $this->onlyArchive();
        $dir = $this->root . '/' . $archive['path'];
        self::assertSame($archiveId, $archive['archiveId']);
        self::assertSame($sent->getMessageId(), $archive['messageId']);
        self::assertSame('smtp', $archive['transport']);
        self::assertSame('Invoice 42', $archive['subject']);
        self::assertSame(['customer@example.com'], $archive['to']);
        self::assertSame($sent->toString(), file_get_contents($dir . '/message.eml'));
        self::assertStringContainsString('<p>Invoice 42</p>', (string) file_get_contents($dir . '/preview.html'));
        self::assertSame('%PDF-1.4 fake', file_get_contents($dir . '/attachments/1_invoice.pdf'));
        self::assertSame('application/pdf', $archive['attachmentsMeta'][0]['contentType']);
    }

    /**
     * @return iterable<string, array{array<string, list<string>>}>
     */
    public static function ignoreRules(): iterable
    {
        yield 'sender' => [['from' => ['BILLING@example.com']]];
        yield 'recipient' => [['to' => ['Customer@example.com']]];
        yield 'subject regex' => [['subject_regex' => ['/^\[debug\]/i']]];
        yield 'subject substring' => [['subject_regex' => ['invoice 42']]];
        yield 'template' => [['templates' => ['Transactional/Invoice']]];
    }

    /**
     * @param array<string, list<string>> $rules
     */
    #[DataProvider('ignoreRules')]
    public function testIgnoreRulesSkipTheArchive(array $rules): void
    {
        $email = $this->email()->subject('[Debug] Invoice 42')->text('Body');
        $email->getHeaders()->addTextHeader('X-Template-Name', 'transactional/invoice');

        $events = [];
        $archiveId = $this->service(
            ignoreRules: $rules + ['from' => [], 'to' => [], 'subject_regex' => [], 'templates' => []],
            dispatcher: $this->dispatcher($events),
        )->archiveEmail($email, null);

        self::assertNull($archiveId);
        self::assertSame([], $this->archives());
        self::assertSame([], $events);
    }

    public function testSkipHeaderSkipsTheArchive(): void
    {
        $email = $this->email()->text('Body');
        $email->getHeaders()->addTextHeader('X-Archive-Skip', 'true');

        self::assertNull($this->service()->archiveEmail($email, null));
        self::assertSame([], $this->archives());
    }

    public function testSkipHeaderSkipsASentMessage(): void
    {
        $email = $this->email()->text('Body');
        $email->getHeaders()->addTextHeader('X-Archive-Skip', 'yes');

        self::assertNull($this->service()->archiveSent(new SentMessage($email, Envelope::create($email))));
        self::assertSame([], $this->archives());
    }

    public function testArchivedEventCarriesTheIdPathMessageAndTransport(): void
    {
        $email = $this->email()->html('<p>Invoice 42</p>');
        $email->getHeaders()->addTextHeader('X-System-Email-Id', '0199f0c4-mail');
        $sent = new SentMessage($email, Envelope::create($email));
        $events = [];

        $archiveId = $this->service(dispatcher: $this->dispatcher($events))->archiveSent($sent, 'smtp');

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame($archiveId, $event->getArchiveId());
        self::assertSame($this->onlyArchive()['path'], $event->getPath());
        self::assertSame($sent->getMessage(), $event->getMessage());
        self::assertSame('smtp', $event->getTransport());
        self::assertSame('0199f0c4-mail', $event->getHeader('X-System-Email-Id'));
    }

    public function testArchivedEventIsDispatchedAfterEveryFileIsWritten(): void
    {
        $seen = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EmailArchivedEvent::class, function (EmailArchivedEvent $event) use (&$seen): void {
            $dir = $this->root . '/' . $event->getPath();
            $index = sprintf('%s/index/%s.ndjson', $this->root, str_replace('/', '-', substr($event->getPath(), 0, 10)));
            $seen = is_file($dir . '/message.eml') && is_file($dir . '/meta.json') && is_file($index);
        });

        $this->service(dispatcher: $dispatcher)->archiveEmail($this->email()->text('Body'), null);

        self::assertTrue($seen);
    }

    public function testFlysystemStorageGetsTheSameLayoutBelowItsPrefix(): void
    {
        $filesystem = new FlysystemFilesystem(new InMemoryFilesystemAdapter());
        $email = $this->email()->html('<p>Invoice 42</p>')->attach('hello world', 'report.txt', 'text/plain');
        $events = [];

        $archiveId = $this->service(
            dispatcher: $this->dispatcher($events),
            storage: new FlysystemArchiveStorage($filesystem, 'email'),
        )->archiveEmail($email, null);

        $path = $events[0]->getPath();
        self::assertStringEndsWith('/' . $archiveId, $path);
        $meta = json_decode($filesystem->read('email/' . $path . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($archiveId, $meta['archiveId']);
        self::assertSame($path, $meta['path']);
        self::assertStringContainsString('Subject: Invoice 42', $filesystem->read('email/' . $path . '/message.eml'));
        self::assertSame('<p>Invoice 42</p>', $filesystem->read('email/' . $path . '/preview.html'));
        self::assertSame('hello world', $filesystem->read('email/' . $path . '/attachments/1_report.txt'));

        $index = json_decode(
            $filesystem->read(sprintf('email/index/%s/%s.ndjson', substr($meta['sentAt'], 0, 10), $archiveId)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($archiveId, $index['archiveId']);
        self::assertSame($path, $index['path']);
        self::assertDirectoryDoesNotExist($this->root);
    }

    /**
     * @param array{from:string[],to:string[],subject_regex:string[],templates:string[]} $ignoreRules
     */
    private function service(
        array $ignoreRules = ['from' => [], 'to' => [], 'subject_regex' => [], 'templates' => []],
        int $maxPreviewBytes = 2_000_000,
        int $maxAttachmentBytes = 50_000_000,
        ?EventDispatcher $dispatcher = null,
        ?ArchiveStorage $storage = null,
    ): EmailArchiveService {
        return new EmailArchiveService(
            $this->root,
            $ignoreRules,
            null,
            $maxPreviewBytes,
            $maxAttachmentBytes,
            $storage,
            $dispatcher,
        );
    }

    /**
     * @param list<EmailArchivedEvent> $events receives every dispatched event
     */
    private function dispatcher(array &$events): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EmailArchivedEvent::class, static function (EmailArchivedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        return $dispatcher;
    }

    private function email(): Email
    {
        return (new Email())
            ->from(new Address('billing@example.com', 'Billing'))
            ->to('customer@example.com')
            ->subject('Invoice 42');
    }

    /**
     * @return list<array<string, mixed>> the meta.json of every archive
     */
    private function archives(): array
    {
        $archives = [];
        foreach (glob($this->root . '/[0-9][0-9][0-9][0-9]/*/*/*/meta.json') ?: [] as $file) {
            $archives[] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }

        return $archives;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyArchive(): array
    {
        $archives = $this->archives();
        self::assertCount(1, $archives);

        return $archives[0];
    }

    /**
     * @param array<string, mixed> $archive
     * @return list<array<string, mixed>>
     */
    private function indexLines(array $archive): array
    {
        $file = sprintf('%s/index/%s.ndjson', $this->root, substr((string) $archive['sentAt'], 0, 10));
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        return array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
    }
}
