<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Message;

/**
 * SES publishes delivery, open and click events only for messages sent with a configuration set: this names
 * `email_archive.deliverability.ses.configuration_set` on every outgoing message (the X-SES-CONFIGURATION-SET header, which
 * SES's SMTP interface reads and Symfony's SES API transport turns into ConfigurationSetName), unless the message names one.
 */
final class SesConfigurationSetSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-SES-CONFIGURATION-SET';

    public function __construct(private readonly string $configurationSet)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => 'onMessage'];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if ('' === $this->configurationSet || !$message instanceof Message || $message->getHeaders()->has(self::HEADER)) {
            return;
        }

        $message->getHeaders()->addTextHeader(self::HEADER, $this->configurationSet);
    }
}
