<?php

namespace Hexis\EmailArchiveBundle\Tests\Integration\Kernel;

use Hexis\EmailArchiveBundle\Event\EmailArchivedEvent;

/**
 * A host listener: records every EmailArchivedEvent.
 */
final class ArchivedEvents
{
    /** @var list<EmailArchivedEvent> */
    public array $events = [];

    public function __invoke(EmailArchivedEvent $event): void
    {
        $this->events[] = $event;
    }
}
