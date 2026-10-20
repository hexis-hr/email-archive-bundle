<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Delivery;

/**
 * Normalises an RFC 5322 Message-ID for correlation. The archive stores the id
 * as it appears on the message (often "<id@host>"); providers report it without
 * angle brackets and with varying case. Normalising both sides lets delivery
 * events attach to the right archived email.
 */
final class MessageId
{
    public static function normalize(?string $id): ?string
    {
        if (null === $id) {
            return null;
        }

        $id = strtolower(trim($id));
        $id = trim($id, '<>');

        return '' === $id ? null : $id;
    }
}
