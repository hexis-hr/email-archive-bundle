<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Controller\Webhook;

use Hexis\EmailArchiveBundle\Delivery\DeliveryEvent;
use Hexis\EmailArchiveBundle\Delivery\DeliveryEventStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Amazon SES event notifications delivered via SNS. Registered only when `email_archive.deliverability.ses.webhook.enabled`
 * is true; the host wires the route (see README).
 *
 * With `ses.webhook.secret` set, a request must carry it as the HTTP basic-auth password (SNS sends the credentials of a
 * subscription URL such as https://sns:<secret>@host/_email-webhooks/ses); anything else is answered 401 with a Basic
 * challenge. An empty secret (an unset environment variable) refuses every request; only null (none configured) leaves the
 * webhook open, for a host that guards the route itself.
 *
 * SNS subscription confirmations are logged, never auto-confirmed (that would be an outbound call). An event is recorded
 * under both the SES message id (mail.messageId, what Symfony's SES API transport reports as the sent message's id) and the
 * Message-ID header (mail.commonHeaders.messageId, what an SMTP transport reports), so it attaches to the archived email
 * whichever transport sent it.
 *
 * shortcut: the shared secret, not SNS's message signature, authenticates the request; verify the signature (its
 * certificate fetched from sns.<region>.amazonaws.com) when a secret in the subscription URL is not enough.
 */
final class SesWebhookController
{
    public function __construct(
        private readonly DeliveryEventStoreInterface $store,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $secret = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (null !== $this->secret && ('' === $this->secret || !hash_equals($this->secret, (string) $request->getPassword()))) {
            $this->logger?->warning('Rejected an SES webhook without the shared secret.');

            return new JsonResponse(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Basic realm="email-archive"']);
        }

        $payload = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $type = $payload['Type'] ?? $request->headers->get('x-amz-sns-message-type');

        if ('SubscriptionConfirmation' === $type) {
            $this->logger?->notice('Received SES/SNS subscription confirmation; confirm it manually.', [
                'subscribe_url' => $payload['SubscribeURL'] ?? null,
                'topic_arn' => $payload['TopicArn'] ?? null,
            ]);

            return new JsonResponse(['ok' => true, 'action' => 'confirm_subscription_manually']);
        }

        $message = [];
        if (isset($payload['Message']) && \is_string($payload['Message'])) {
            $decoded = json_decode($payload['Message'], true);
            if (\is_array($decoded)) {
                $message = $decoded;
            }
        }

        // eventType from a configuration set's event destination, notificationType from identity notifications
        $event = $message['eventType'] ?? $message['notificationType'] ?? null;
        $event = \is_string($event) ? $event : 'unknown';
        $at = $message[lcfirst($event)]['timestamp'] ?? $message['mail']['timestamp'] ?? null;
        $occurredAt = \is_string($at) && false !== strtotime($at) ? new \DateTimeImmutable($at) : new \DateTimeImmutable();

        $ids = array_unique(array_filter([$message['mail']['messageId'] ?? null, $message['mail']['commonHeaders']['messageId'] ?? null],
            static fn (mixed $id): bool => \is_string($id) && '' !== $id));
        foreach ($ids as $messageId) {
            $this->store->record(new DeliveryEvent(
                messageId: $messageId,
                event: strtolower($event),
                provider: 'ses',
                occurredAt: $occurredAt,
                raw: $message,
            ));
        }

        return new JsonResponse(['ok' => true]);
    }
}
