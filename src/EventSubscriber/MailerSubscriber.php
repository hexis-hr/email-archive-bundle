<?php

namespace Hexis\EmailArchiveBundle\EventSubscriber;

use Hexis\EmailArchiveBundle\Deliverability\DeliverabilityTrackerInterface;
use Hexis\EmailArchiveBundle\Deliverability\NullTracker;
use Hexis\EmailArchiveBundle\Service\EmailArchiveService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\SentMessageEvent;

class MailerSubscriber implements EventSubscriberInterface
{

    /**
     * @param EmailArchiveService|\Closure(): EmailArchiveService $emailArchiveService a closure defers building
     *        the service (and its storage) into the guarded block, so even that cannot fail the e-mail
     * @param string $deliverabilityProvider email_archive.deliverability.provider, handed to the tracker
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EmailArchiveService|\Closure $emailArchiveService,
        private readonly bool $emailArchiveEnabled,
        private readonly DeliverabilityTrackerInterface $deliverabilityTracker = new NullTracker(),
        private readonly string $deliverabilityProvider = 'null',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SentMessageEvent::class => 'onSentMessage',
        ];
    }

    /**
     * Runs after the transport delivered the message: an exception here would reach the caller, and a Messenger
     * retry would send the e-mail again. An archive failure is logged and the e-mail stays sent.
     */
    public function onSentMessage(SentMessageEvent $event): void
    {
        if (!$this->emailArchiveEnabled) {
            return;
        }

        $message = $event->getMessage();

        try {
            $service = $this->emailArchiveService instanceof \Closure
                ? ($this->emailArchiveService)()
                : $this->emailArchiveService;

            $archiveId = $service->archiveSent($message);
            $this->logger->info('E-mail {messageId} sent, archived as {archiveId}.', [
                'messageId' => $message->getMessageId(),
                'archiveId' => $archiveId ?? 'none (skipped by a rule)',
            ]);
        } catch (\Throwable $exception) {
            try {
                $this->logger->error('The e-mail was sent but could not be archived: {error}', [
                    'error' => $exception->getMessage(),
                    'exception' => $exception,
                    'messageId' => $message->getMessageId(),
                ]);
            } catch (\Throwable) {
                // The logger failed too (a full disk takes both); reporting must not fail the sent e-mail either.
            }
        }

        // Register the send with the deliverability backend (no-op for NullTracker); like the archive, never fails the e-mail.
        try {
            $this->deliverabilityTracker->register($message, $this->deliverabilityProvider);
        } catch (\Throwable $exception) {
            try {
                $this->logger->warning('Deliverability registration failed.', ['exception' => $exception]);
            } catch (\Throwable) {
            }
        }
    }
}
