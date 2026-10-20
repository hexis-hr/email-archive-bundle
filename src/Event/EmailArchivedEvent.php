<?php

namespace Hexis\EmailArchiveBundle\Event;

use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an e-mail was archived completely, so the host can link its own records to the archive.
 */
final class EmailArchivedEvent extends Event
{
    public function __construct(
        private readonly string $archiveId,
        private readonly string $path,
        private readonly RawMessage $message,
        private readonly ?string $transport,
    ) {
    }

    public function getArchiveId(): string
    {
        return $this->archiveId;
    }

    /**
     * The archive directory relative to the archive root, e.g. "2026/10/16/143005_0123456789abcdef".
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * The archived message; through the Mailer subscriber this is the MIME source of the sent message.
     */
    public function getMessage(): RawMessage
    {
        return $this->message;
    }

    public function getTransport(): ?string
    {
        return $this->transport;
    }

    /**
     * The first header of that name (case-insensitive), unfolded; null when the message has none.
     */
    public function getHeader(string $name): ?string
    {
        if ($this->message instanceof Message) {
            return $this->message->getHeaders()->get($name)?->getBodyAsString();
        }

        $head = preg_split("/\r?\n\r?\n/", $this->message->toString(), 2)[0];
        $head = preg_replace("/\r?\n[ \t]+/", ' ', $head) ?? $head;

        if (preg_match('/^' . preg_quote($name, '/') . ':[ \t]*(.*?)\r?$/mi', $head, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
