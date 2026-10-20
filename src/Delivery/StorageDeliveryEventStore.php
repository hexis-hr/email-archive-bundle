<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Delivery;

use Hexis\EmailArchiveBundle\Storage\ArchiveStorage;

/**
 * Delivery events beside the archive, in the same storage (the local disk or a Flysystem filesystem): one JSON file per
 * event in delivery/<sha1(normalized Message-ID)>/, because object storage cannot append and concurrent webhooks must
 * never interleave.
 */
final class StorageDeliveryEventStore implements DeliveryEventStoreInterface
{
    public function __construct(private readonly ArchiveStorage $storage)
    {
    }

    public function record(DeliveryEvent $event): void
    {
        $id = MessageId::normalize($event->messageId);
        if (null === $id) {
            return;
        }

        $this->storage->write(
            sprintf('%s/%s-%s.json', $this->directory($id), $event->occurredAt->format('Ymd\THis.u'), bin2hex(random_bytes(4))),
            json_encode($event->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n",
        );
    }

    public function eventsFor(?string $messageId): array
    {
        $id = MessageId::normalize($messageId);
        if (null === $id) {
            return [];
        }

        $events = [];
        foreach ($this->storage->readAll($this->directory($id)) as $json) {
            $data = json_decode($json, true);
            if (\is_array($data)) {
                $events[] = DeliveryEvent::fromArray($data);
            }
        }
        usort($events, static fn (DeliveryEvent $a, DeliveryEvent $b): int => $a->occurredAt <=> $b->occurredAt);

        return $events;
    }

    public function latestStatus(?string $messageId): ?string
    {
        $events = $this->eventsFor($messageId);

        return [] === $events ? null : end($events)->event;
    }

    private function directory(string $normalizedMessageId): string
    {
        return 'delivery/' . sha1($normalizedMessageId);
    }
}
