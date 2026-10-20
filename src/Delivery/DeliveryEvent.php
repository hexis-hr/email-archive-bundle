<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Delivery;

/**
 * A single provider delivery event (delivered, opened, clicked, bounced,
 * complained, …) correlated to a sent email by its Message-ID.
 */
final class DeliveryEvent
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $event,
        public readonly string $provider,
        public readonly \DateTimeImmutable $occurredAt,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'event' => $this->event,
            'provider' => $this->provider,
            'occurredAt' => $this->occurredAt->format(\DATE_ATOM),
            'raw' => $this->raw,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            messageId: (string) ($data['messageId'] ?? ''),
            event: (string) ($data['event'] ?? 'unknown'),
            provider: (string) ($data['provider'] ?? 'unknown'),
            occurredAt: new \DateTimeImmutable((string) ($data['occurredAt'] ?? 'now')),
            raw: \is_array($data['raw'] ?? null) ? $data['raw'] : [],
        );
    }
}
