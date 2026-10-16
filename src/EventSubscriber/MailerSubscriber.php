<?php

namespace Hexis\EmailArchiveBundle\EventSubscriber;

use Hexis\EmailArchiveBundle\Service\EmailArchiveService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\SentMessageEvent;

class MailerSubscriber implements EventSubscriberInterface
{

    /**
     * @param EmailArchiveService|\Closure(): EmailArchiveService $emailArchiveService a closure defers building
     *        the service (and its storage) into the guarded block, so even that cannot fail the e-mail
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EmailArchiveService|\Closure $emailArchiveService,
        private readonly bool $emailArchiveEnabled,
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

            $service->archiveSent($message);
        } catch (\Throwable $exception) {
            $this->logger->error('The e-mail was sent but could not be archived: {error}', [
                'error' => $exception->getMessage(),
                'exception' => $exception,
                'messageId' => $message->getMessageId(),
            ]);
        }
    }
}
