<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Tests\Unit\Controller\Webhook;

use Hexis\EmailArchiveBundle\Controller\Webhook\SesWebhookController;
use Hexis\EmailArchiveBundle\Tests\Support\InMemoryDeliveryEventStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SesWebhookControllerTest extends TestCase
{
    public function testSubscriptionConfirmationIsSurfacedNotRecorded(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $controller = new SesWebhookController($store);

        $response = $controller($this->request([
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.example/confirm',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $store->events);
        self::assertStringContainsString('confirm_subscription_manually', (string) $response->getContent());
    }

    public function testNotificationRecordsEventWithMessageId(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $controller = new SesWebhookController($store);

        $message = json_encode([
            'eventType' => 'Delivery',
            'mail' => ['commonHeaders' => ['messageId' => '<x@h>']],
        ], \JSON_THROW_ON_ERROR);

        $response = $controller($this->request(['Type' => 'Notification', 'Message' => $message]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $store->events);
        self::assertSame('delivery', $store->events[0]->event);
        self::assertSame('ses', $store->events[0]->provider);
        self::assertSame('<x@h>', $store->events[0]->messageId);
    }

    public function testAnEventIsRecordedUnderTheSesIdAndTheMessageIdHeaderWhenItHappened(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $message = json_encode([
            'eventType' => 'Bounce',
            'bounce' => ['timestamp' => '2026-10-20T10:05:00.000Z'],
            'mail' => ['timestamp' => '2026-10-20T10:00:00.000Z', 'messageId' => '0100019a-ses', 'commonHeaders' => ['messageId' => '<x@h>']],
        ], \JSON_THROW_ON_ERROR);

        (new SesWebhookController($store))($this->request(['Type' => 'Notification', 'Message' => $message]));

        self::assertSame(['0100019a-ses', '<x@h>'], array_map(static fn($e): string => $e->messageId, $store->events));
        self::assertSame('bounce', $store->events[0]->event);
        self::assertEquals(new \DateTimeImmutable('2026-10-20T10:05:00.000Z'), $store->events[0]->occurredAt);
    }

    public function testWithASecretOnlyARequestCarryingItIsHeard(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $controller = new SesWebhookController($store, null, 's3cret-fake');
        $payload = ['Type' => 'Notification', 'Message' => json_encode(['eventType' => 'Delivery', 'mail' => ['messageId' => 'm1']], \JSON_THROW_ON_ERROR)];

        foreach ([null, 'wrong'] as $password) {
            $response = $controller($this->request($payload, $password));
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Basic realm="email-archive"', $response->headers->get('WWW-Authenticate'), 'the challenge SNS answers with the URL\'s credentials');
        }
        self::assertCount(0, $store->events);

        self::assertSame(200, $controller($this->request($payload, 's3cret-fake'))->getStatusCode());
        self::assertCount(1, $store->events);

        self::assertSame(401, (new SesWebhookController($store, null, ''))($this->request($payload, ''))->getStatusCode(), 'an unset secret closes the webhook');
        self::assertCount(1, $store->events);
    }

    /** @param array<string, mixed> $payload */
    private function request(array $payload, ?string $password = null): Request
    {
        $server = null === $password ? [] : ['PHP_AUTH_USER' => 'sns', 'PHP_AUTH_PW' => $password];

        return Request::create('/_email-webhooks/ses', 'POST', [], [], [], $server, json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
