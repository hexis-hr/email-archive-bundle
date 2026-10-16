<?php

namespace Hexis\EmailArchiveBundle\Tests\Integration\Kernel;

use Hexis\EmailArchiveBundle\Tests\Support\CollectingLogger;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Boots the real bundle in a Symfony kernel and sends e-mails through Mailer.
 */
final class EmailArchiveKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        $root = self::$kernel instanceof TestKernel ? self::$kernel->archiveRoot() : null;
        parent::tearDown();
        if ($root !== null && is_dir($root)) {
            (new Filesystem())->remove($root);
        }
    }

    public function testSentEmailIsArchivedOnce(): void
    {
        $kernel = self::bootKernel();

        $this->mailer()->send($this->email());

        $index = glob($kernel->archiveRoot() . '/index/*.ndjson') ?: [];
        self::assertCount(1, $index);
        $lines = file($index[0], FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $lines);

        $entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invoice 42', $entry['subject']);

        $dir = $kernel->archiveRoot() . '/' . $entry['path'];
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($entry['archiveId'], $meta['archiveId']);
        self::assertSame(['customer@example.com'], $meta['to']);
        self::assertStringContainsString('X-System-Email-Id: 0199f0c4-mail', (string) file_get_contents($dir . '/message.eml'));

        $events = self::getContainer()->get(ArchivedEvents::class)->events;
        self::assertCount(1, $events);
        self::assertSame($entry['archiveId'], $events[0]->getArchiveId());
        self::assertSame($entry['path'], $events[0]->getPath());
        self::assertSame('0199f0c4-mail', $events[0]->getHeader('X-System-Email-Id'));
    }

    public function testArchiveFailureIsLoggedAndTheSendSucceeds(): void
    {
        self::bootKernel(['environment' => TestKernel::ENV_BROKEN_DISK]);

        $this->mailer()->send($this->email());

        $errors = array_values(array_filter(
            $this->logger()->records,
            static fn(array $record): bool => $record['level'] === LogLevel::ERROR,
        ));
        self::assertCount(1, $errors);
        self::assertStringContainsString('could not be archived', $errors[0]['message']);
        self::assertInstanceOf(\Throwable::class, $errors[0]['context']['exception']);
        self::assertSame([], self::getContainer()->get(ArchivedEvents::class)->events);
    }

    private function logger(): CollectingLogger
    {
        return self::getContainer()->get('logger');
    }

    private function mailer(): MailerInterface
    {
        return self::getContainer()->get('test.mailer');
    }

    private function email(): Email
    {
        $email = (new Email())
            ->from('billing@example.com')
            ->to('customer@example.com')
            ->subject('Invoice 42')
            ->html('<p>Invoice 42</p>');
        $email->getHeaders()->addTextHeader('X-System-Email-Id', '0199f0c4-mail');

        return $email;
    }
}
