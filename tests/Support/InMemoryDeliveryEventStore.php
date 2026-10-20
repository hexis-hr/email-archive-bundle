<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Tests\Support;

use Hexis\EmailArchiveBundle\Delivery\DeliveryEvent;
use Hexis\EmailArchiveBundle\Delivery\DeliveryEventStoreInterface;

/**
 * Capturing test double for the delivery store.
 */
final class InMemoryDeliveryEventStore implements DeliveryEventStoreInterface
{
    /** @var list<DeliveryEvent> */
    public array $events = [];

    public function record(DeliveryEvent $event): void
    {
        $this->events[] = $event;
    }

    public function eventsFor(?string $messageId): array
    {
        return $this->events;
    }

    public function latestStatus(?string $messageId): ?string
    {
        return $this->events ? end($this->events)->event : null;
    }
}
