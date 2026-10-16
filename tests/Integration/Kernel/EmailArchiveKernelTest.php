<?php

namespace Hexis\EmailArchiveBundle\Tests\Integration\Kernel;

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
        if ($root !== null) {
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
