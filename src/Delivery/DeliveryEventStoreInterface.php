<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Delivery;

/**
 * Stores and retrieves provider delivery events, keyed by Message-ID so they can
 * be shown against the matching archived email.
 */
interface DeliveryEventStoreInterface
{
    public function record(DeliveryEvent $event): void;

    /**
     * @return list<DeliveryEvent> chronological (oldest first)
     */
    public function eventsFor(?string $messageId): array;

    /**
     * The most recent event name for a message, or null if none recorded.
     */
    public function latestStatus(?string $messageId): ?string;
}
