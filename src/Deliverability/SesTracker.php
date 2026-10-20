<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Deliverability;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;

/**
 * Tracks deliverability for Amazon SES. SES reports delivery status
 * asynchronously over SNS, so registration simply records the correlation; the
 * actual status events arrive via {@see \Hexis\EmailArchiveBundle\Controller\Webhook\SesWebhookController}.
 * Loaded only when the SES tracker is enabled. No AWS SDK dependency.
 */
final class SesTracker implements DeliverabilityTrackerInterface
{
    public function __construct(
        private readonly string $region = 'us-east-1',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function register(SentMessage $sentMessage, string $providerId): void
    {
        $this->logger?->info('Registered message for SES deliverability tracking (status arrives via SNS webhook).', [
            'provider' => $providerId,
            'message_id' => $sentMessage->getMessageId(),
            'region' => $this->region,
        ]);
    }
}
