<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Tests\Unit\Controller\Webhook;

use Hexis\EmailArchiveBundle\Controller\Webhook\MailgunWebhookController;
use Hexis\EmailArchiveBundle\Tests\Support\InMemoryDeliveryEventStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class MailgunWebhookControllerTest extends TestCase
{
    public function testRecordsEvent(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $controller = new MailgunWebhookController($store, null);

        $response = $controller($this->request([
            'event-data' => ['event' => 'delivered', 'timestamp' => 1717360000, 'message' => ['headers' => ['message-id' => 'abc@h']]],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $store->events);
        self::assertSame('delivered', $store->events[0]->event);
        self::assertSame('mailgun', $store->events[0]->provider);
        self::assertSame('abc@h', $store->events[0]->messageId);
    }

    public function testInvalidSignatureRejected(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $controller = new MailgunWebhookController($store, 'secret');

        $response = $controller($this->request([
            'signature' => ['token' => 't', 'timestamp' => '1', 'signature' => 'bad'],
            'event-data' => ['event' => 'delivered', 'message' => ['headers' => ['message-id' => 'a@h']]],
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $store->events);
    }

    public function testValidSignatureAccepted(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $controller = new MailgunWebhookController($store, 'secret');
        $signature = hash_hmac('sha256', '1' . 'tok', 'secret');

        $response = $controller($this->request([
            'signature' => ['token' => 'tok', 'timestamp' => '1', 'signature' => $signature],
            'event-data' => ['event' => 'opened', 'message' => ['headers' => ['message-id' => 'a@h']]],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $store->events);
        self::assertSame('opened', $store->events[0]->event);
    }

    public function testMissingMessageIdSkipped(): void
    {
        $store = new InMemoryDeliveryEventStore();
        $controller = new MailgunWebhookController($store, null);

        $response = $controller($this->request([
            'event-data' => ['event' => 'delivered', 'message' => ['headers' => []]],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $store->events);
    }

    /** @param array<string, mixed> $payload */
    private function request(array $payload): Request
    {
        return Request::create('/_email-webhooks/mailgun', 'POST', [], [], [], [], json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
