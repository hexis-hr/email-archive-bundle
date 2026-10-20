<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Deliverability;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tracks deliverability through the Mailgun Events API using symfony/http-client
 * (no Mailgun SDK). Best-effort: any transport error is swallowed and logged so
 * tracking never breaks the send flow. Loaded only when the Mailgun tracker is
 * enabled. The API key comes from configuration/environment — never hardcoded.
 */
final class MailgunTracker implements DeliverabilityTrackerInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $domain,
        private readonly string $region = 'us',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function register(SentMessage $sentMessage, string $providerId): void
    {
        $messageId = trim($sentMessage->getMessageId(), '<>');
        if ('' === $messageId) {
            return;
        }

        $baseUri = 'eu' === $this->region ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

        try {
            $status = $this->httpClient->request('GET', sprintf('%s/v3/%s/events', $baseUri, $this->domain), [
                'auth_basic' => ['api', $this->apiKey],
                'query' => ['message-id' => $messageId],
            ])->getStatusCode();

            $this->logger?->info('Registered message with Mailgun deliverability tracking.', [
                'provider' => $providerId,
                'message_id' => $messageId,
                'status' => $status,
            ]);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Mailgun deliverability registration failed.', [
                'provider' => $providerId,
                'message_id' => $messageId,
                'exception' => $exception,
            ]);
        }
    }
}
