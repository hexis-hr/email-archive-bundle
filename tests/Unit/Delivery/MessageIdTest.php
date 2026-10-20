<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Tests\Unit\Delivery;

use Hexis\EmailArchiveBundle\Delivery\MessageId;
use PHPUnit\Framework\TestCase;

final class MessageIdTest extends TestCase
{
    public function testNormalizeStripsBracketsAndLowercases(): void
    {
        self::assertSame('foo@bar', MessageId::normalize('<Foo@Bar>'));
        self::assertSame('id', MessageId::normalize('  <ID>  '));
        self::assertSame('plain@host', MessageId::normalize('plain@host'));
    }

    public function testNormalizeReturnsNullForEmpty(): void
    {
        self::assertNull(MessageId::normalize(null));
        self::assertNull(MessageId::normalize(''));
        self::assertNull(MessageId::normalize('<>'));
    }
}
