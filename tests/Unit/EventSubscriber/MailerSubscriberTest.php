<?php

namespace Hexis\EmailArchiveBundle\Tests\Unit\EventSubscriber;

use Hexis\EmailArchiveBundle\EventSubscriber\MailerSubscriber;
use Hexis\EmailArchiveBundle\Service\EmailArchiveService;
use Hexis\EmailArchiveBundle\Tests\Support\CollectingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

final class MailerSubscriberTest extends TestCase
{
    private string $root;
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hexis-email-archive-test-' . bin2hex(random_bytes(6));
        $this->logger = new CollectingLogger();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testListensToSentMessageEvent(): void
    {
        self::assertSame([SentMessageEvent::class => 'onSentMessage'], MailerSubscriber::getSubscribedEvents());
    }

    public function testSentMessageIsArchived(): void
    {
        $subscriber = new MailerSubscriber($this->logger, new EmailArchiveService($this->root), true);

        $subscriber->onSentMessage($this->event());

        self::assertCount(1, $this->archives());
        self::assertSame([], $this->logger->records);
    }

    public function testServiceClosureIsResolvedWhenAMessageIsSent(): void
    {
        $subscriber = new MailerSubscriber($this->logger, fn(): EmailArchiveService => new EmailArchiveService($this->root), true);

        $subscriber->onSentMessage($this->event());

        self::assertCount(1, $this->archives());
    }

    public function testArchiveFailureIsLoggedAndNotThrown(): void
    {
        $service = new EmailArchiveService($this->root);
        // The disk goes away after the service was built: the archive directory can no longer be created.
        $filesystem = new Filesystem();
        $filesystem->remove($this->root);
        $filesystem->dumpFile($this->root, 'not a directory');

        $event = $this->event();
        (new MailerSubscriber($this->logger, $service, true))->onSentMessage($event);

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $record['level']);
        self::assertInstanceOf(\Throwable::class, $record['context']['exception']);
        self::assertSame($event->getMessage()->getMessageId(), $record['context']['messageId']);
    }

    public function testServiceThatCannotBeBuiltIsLoggedAndNotThrown(): void
    {
        $subscriber = new MailerSubscriber($this->logger, function (): EmailArchiveService {
            throw new \RuntimeException('Storage is not configured.');
        }, true);

        $subscriber->onSentMessage($this->event());

        self::assertCount(1, $this->logger->records);
        self::assertSame('Storage is not configured.', $this->logger->records[0]['context']['error']);
    }

    public function testDisabledArchiveNeitherBuildsTheServiceNorArchives(): void
    {
        $built = false;
        $subscriber = new MailerSubscriber($this->logger, function () use (&$built): EmailArchiveService {
            $built = true;

            return new EmailArchiveService($this->root);
        }, false);

        $subscriber->onSentMessage($this->event());

        self::assertFalse($built);
        self::assertDirectoryDoesNotExist($this->root);
        self::assertSame([], $this->logger->records);
    }

    private function event(): SentMessageEvent
    {
        $email = (new Email())
            ->from('billing@example.com')
            ->to('customer@example.com')
            ->subject('Invoice 42')
            ->text('Body');

        return new SentMessageEvent(new SentMessage($email, Envelope::create($email)));
    }

    /**
     * @return list<string>
     */
    private function archives(): array
    {
        return glob($this->root . '/[0-9][0-9][0-9][0-9]/*/*/*/meta.json') ?: [];
    }
}
