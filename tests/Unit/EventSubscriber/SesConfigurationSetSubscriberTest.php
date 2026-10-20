<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Tests\Unit\EventSubscriber;

use Hexis\EmailArchiveBundle\EventSubscriber\SesConfigurationSetSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SesConfigurationSetSubscriberTest extends TestCase
{
    public function testEveryMessageNamesTheConfigurationSet(): void
    {
        $email = $this->send(new SesConfigurationSetSubscriber('goldfish-estee'), new Email());

        self::assertSame('goldfish-estee', $email->getHeaders()->get('X-SES-CONFIGURATION-SET')?->getBodyAsString());
    }

    public function testAMessageThatNamesOneKeepsItAndNoneIsAddedWithoutASet(): void
    {
        $own = (new Email())->text('x');
        $own->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', 'own');

        self::assertSame('own', $this->send(new SesConfigurationSetSubscriber('goldfish-estee'), $own)->getHeaders()->get('X-SES-CONFIGURATION-SET')?->getBodyAsString());
        self::assertFalse($this->send(new SesConfigurationSetSubscriber(''), new Email())->getHeaders()->has('X-SES-CONFIGURATION-SET'));
    }

    private function send(SesConfigurationSetSubscriber $subscriber, Email $email): Email
    {
        $email->from('a@example.test')->to('b@example.test')->text('x');
        $subscriber->onMessage(new MessageEvent($email, new Envelope(new Address('a@example.test'), [new Address('b@example.test')]), 'ses'));

        return $email;
    }
}
