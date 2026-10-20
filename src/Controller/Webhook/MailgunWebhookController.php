<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Controller\Webhook;

use Hexis\EmailArchiveBundle\Delivery\DeliveryEvent;
use Hexis\EmailArchiveBundle\Delivery\DeliveryEventStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Mailgun delivery-status webhook. Registered only when
 * `email_archive.deliverability.mailgun.webhook.enabled` is true; the host wires
 * the route manually (see README). Verifies Mailgun's HMAC signature (no SDK, no
 * network) when a signing key is configured, then records the event against the
 * archived email via the delivery store.
 */
final class MailgunWebhookController
{
    public function __construct(
        private readonly DeliveryEventStoreInterface $store,
        private readonly ?string $signingKey = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $signature = \is_array($payload['signature'] ?? null) ? $payload['signature'] : [];
        if (null !== $this->signingKey && !$this->isSignatureValid($signature)) {
            $this->logger?->warning('Rejected Mailgun webhook with an invalid signature.');

            return new JsonResponse(['error' => 'invalid signature'], 403);
        }

        $eventData = \is_array($payload['event-data'] ?? null) ? $payload['event-data'] : [];
        $messageId = $eventData['message']['headers']['message-id'] ?? null;
        if (!\is_string($messageId) || '' === $messageId) {
            return new JsonResponse(['ok' => true, 'note' => 'no message-id, skipped']);
        }

        $timestamp = $eventData['timestamp'] ?? ($signature['timestamp'] ?? null);
        $occurredAt = (is_numeric($timestamp))
            ? (new \DateTimeImmutable())->setTimestamp((int) $timestamp)
            : new \DateTimeImmutable();

        $this->store->record(new DeliveryEvent(
            messageId: $messageId,
            event: \is_string($eventData['event'] ?? null) ? $eventData['event'] : 'unknown',
            provider: 'mailgun',
            occurredAt: $occurredAt,
            raw: $eventData,
        ));

        return new JsonResponse(['ok' => true]);
    }

    /** @param array<string, mixed> $signature */
    private function isSignatureValid(array $signature): bool
    {
        $token = (string) ($signature['token'] ?? '');
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $provided = (string) ($signature['signature'] ?? '');

        if ('' === $token || '' === $timestamp || '' === $provided) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, (string) $this->signingKey);

        return hash_equals($expected, $provided);
    }
}
