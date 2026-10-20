<?php

namespace Hexis\EmailArchiveBundle\Tests\Unit\Event;

use Hexis\EmailArchiveBundle\Event\EmailArchivedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class EmailArchivedEventTest extends TestCase
{
    public function testHeaderIsReadFromAnEmail(): void
    {
        $email = (new Email())->from('billing@example.com')->to('customer@example.com')->text('Body');
        $email->getHeaders()->addTextHeader('X-System-Email-Id', '0199f0c4-mail');

        $event = new EmailArchivedEvent('143005_0123456789abcdef', '2026/10/16/143005_0123456789abcdef', $email, null);

        self::assertSame('0199f0c4-mail', $event->getHeader('x-system-email-id'));
        self::assertNull($event->getHeader('X-Missing'));
    }

    public function testHeaderIsReadFromAMimeSource(): void
    {
        $raw = new RawMessage(
            "From: billing@example.com\r\n"
            . "X-System-Email-Id: 0199f0c4-mail\r\n"
            . "Subject: A long\r\n subject\r\n"
            . "\r\n"
            . "X-Not-A-Header: body\r\n"
        );

        $event = new EmailArchivedEvent('143005_0123456789abcdef', '2026/10/16/143005_0123456789abcdef', $raw, 'smtp');

        self::assertSame('0199f0c4-mail', $event->getHeader('X-System-Email-Id'));
        self::assertSame('A long subject', $event->getHeader('subject'));
        self::assertNull($event->getHeader('X-Not-A-Header'));
        self::assertSame('smtp', $event->getTransport());
    }
}
