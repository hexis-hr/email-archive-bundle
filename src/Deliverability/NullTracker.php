<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Deliverability;

use Symfony\Component\Mailer\SentMessage;

/**
 * Default tracker: does nothing. Active unless `email_archive.deliverability.provider`
 * selects another provider, so deliverability tracking is opt-in.
 */
final class NullTracker implements DeliverabilityTrackerInterface
{
    public function register(SentMessage $sentMessage, string $providerId): void
    {
        // Intentionally empty.
    }
}
