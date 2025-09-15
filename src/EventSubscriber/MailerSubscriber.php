<?php

namespace Hexis\EmailArchiveBundle\EventSubscriber;

use Hexis\EmailArchiveBundle\Service\EmailArchiveService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\SentMessageEvent;

class MailerSubscriber implements EventSubscriberInterface
{

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EmailArchiveService $emailArchiveService,
        private readonly bool $emailArchiveEnabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SentMessageEvent::class => 'onSentMessage',
        ];
    }

    public function onSentMessage(SentMessageEvent $event): void
    {
        if (!$this->emailArchiveEnabled) {
            return;
        }

        $message = $event->getMessage();

        $this->emailArchiveService->archiveSent($message);
    }
}

