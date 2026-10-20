<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Deliverability;

use Symfony\Component\Mailer\SentMessage;

/**
 * Registers a freshly sent message with a deliverability backend so its delivery
 * status can be tracked (via provider APIs and/or webhooks). Called for every
 * archived email. The active provider is chosen with `email_archive.deliverability.provider`.
 */
interface DeliverabilityTrackerInterface
{
    public function register(SentMessage $sentMessage, string $providerId): void;
}
