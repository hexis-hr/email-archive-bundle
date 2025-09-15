<?php

namespace Hexis\EmailArchiveBundle\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class EmailArchiveService
{

    private int $maxPreviewBytes;
    private int $maxAttachmentBytes;

    /** @var array{from:string[],to:string[],subject_regex:string[],templates:string[]} */
    private array $ignoreRules;

    private string $archiveRoot;
    private Filesystem $filesystem;

    /**
     * @param array{from:string[],to:string[],subject_regex:string[],templates:string[]} $ignoreRules
     */
    public function __construct(
        string $archiveRoot,
        array $ignoreRules = ['from' => [], 'to' => [], 'subject_regex' => [], 'templates' => []],
        Filesystem $filesystem = null,
        int $maxPreviewBytes = 2_000_000,
        int $maxAttachmentBytes = 50_000_000,
    ) {
        $this->archiveRoot = rtrim($archiveRoot, '/');
        $this->ignoreRules = self::normalizeIgnoreRules($ignoreRules);
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->maxPreviewBytes = $maxPreviewBytes;
        $this->maxAttachmentBytes = $maxAttachmentBytes;

        $this->ensureArchiveStructure();
    }

    private static function normalizeIgnoreRules(array $rules): array
    {
        $norm = [
            'from' => [],
            'to' => [],
            'subject_regex' => [],
            'templates' => [],
        ];

        $norm['from'] = array_values(array_unique(array_map('strtolower', (array)($rules['from'] ?? []))));
        $norm['to'] = array_values(array_unique(array_map('strtolower', (array)($rules['to'] ?? []))));
        $norm['subject_regex'] = array_values(
            array_filter(
                array_map('trim', (array)($rules['subject_regex'] ?? [])),
                fn($v) => $v !== ''
            )
        );
        $norm['templates'] = array_values(array_unique(array_map('strtolower', (array)($rules['templates'] ?? []))));

        return $norm;
    }

    private function ensureArchiveStructure(): void
    {
        $indexDir = $this->archiveRoot . '/index';
        $this->filesystem->mkdir($indexDir);

        $gitignorePath = $this->archiveRoot . '/.gitignore';
        if (!$this->filesystem->exists($gitignorePath)) {
            $gitignoreContent = "# Email Archive - Do not commit\n*\n!.gitignore\n";
            $this->filesystem->dumpFile($gitignorePath, $gitignoreContent);
        }
    }

    public function archiveSent(SentMessage $sent, ?string $transport = null): void
    {
        $rawMessage = $sent->getMessage();
        $envelope = $sent->getEnvelope();
        $messageId = $sent->getMessageId();

        $this->archiveCore($rawMessage, $envelope, $transport, $messageId);
    }

    /**
     * Core archiving implementation
     */
    private function archiveCore(
        RawMessage $message,
        ?Envelope $envelope,
        ?string $transport,
        ?string $actualMessageId
    ): void {
        $sentAt = new \DateTimeImmutable();
        $archiveId = $this->generateArchiveId($sentAt);
        $archivePath = $this->buildArchivePath($sentAt, $archiveId);

        // Extract email data first (we’ll decide to skip or not based on rules)
        $emailData = $this->extractEmailData($message, $envelope, $sentAt, $transport, $actualMessageId);

        // Selective ignore (header + env rules)
        if ($this->shouldSkipArchive($message, $emailData)) {
            return;
        }

        // Create archive directory
        $this->filesystem->mkdir($archivePath);

        // Store message.eml
        $this->storeRawMessage($archivePath, $message);

        // Store preview (HTML or text)
        $this->storePreview($archivePath, $message, $emailData);

        // Store attachments
        $attachmentsResult = $this->storeAttachments($archivePath, $message);
        $emailData['attachments'] = $attachmentsResult['count'];
        $emailData['attachmentsBytes'] = $attachmentsResult['bytes'];
        $emailData['attachmentsMeta'] = $attachmentsResult['items'];

        // Store metadata
        $this->storeMetadata($archivePath, $emailData, $archiveId);

        // Update daily index
        $this->updateDailyIndex($sentAt, $emailData, $archiveId);
    }

    private function generateArchiveId(\DateTimeInterface $sentAt): string
    {
        $timeStr = $sentAt->format('His');
        $randomStr = bin2hex(random_bytes(8));

        return $timeStr . '_' . $randomStr;
    }

    private function buildArchivePath(\DateTimeInterface $sentAt, string $archiveId): string
    {
        return sprintf(
            '%s/%s/%s/%s/%s',
            $this->archiveRoot,
            $sentAt->format('Y'),
            $sentAt->format('m'),
            $sentAt->format('d'),
            $archiveId
        );
    }

    /**
     * Accepts $transport and $actualMessageId (from SentMessage).
     */
    private function extractEmailData(
        RawMessage $message,
        ?Envelope $envelope,
        \DateTimeInterface $sentAt,
        ?string $transport,
        ?string $actualMessageId
    ): array {
        $rawString = $message->toString();

        $data = [
            'messageId' => $actualMessageId,
            'subject' => null,
            'from' => null,
            'to' => [],
            'cc' => [],
            'bcc' => [],
            'sentAt' => $sentAt->format('c'),
            'transport' => $transport ?? 'default',
            'size' => strlen($rawString), // bytes
            'hash' => hash('sha256', $rawString),
            'attachments' => 0,
            'hasPreview' => false,
            'htmlBody' => null,
            'textBody' => null,
            'template' => null, // from optional X-Template-Name header
        ];

        if ($envelope) {
            $data['from'] = $envelope->getSender()?->getAddress();
            $data['to'] = array_map(static fn($r) => $r->getAddress(), $envelope->getRecipients());
        }

        // Generic RawMessage parsing (best-effort MIME handling)
        if ($message instanceof RawMessage) {
            $raw = $message->toString();

            // Split headers/body (handles \r\n\r\n or \n\n)
            $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
            $headerStr = $parts[0] ?? '';
            $bodyStr = $parts[1] ?? '';

            $headers = $this->parseHeaders($headerStr);

            // Message-Id (only if not provided by SentMessage)
            if ($data['messageId'] === null) {
                $mid = $this->getHeader($headers, 'Message-Id');
                if ($mid) {
                    $mid = trim(str_replace(["\r", "\n"], '', $mid));
                    if ($mid !== '' && $mid[0] === '<' && substr($mid, -1) === '>') {
                        $mid = substr($mid, 1, -1);
                    }
                    $data['messageId'] = $mid;
                }
            }

            // Subject
            $subject = $this->getHeader($headers, 'Subject');
            if ($subject) {
                $data['subject'] = $this->decodeMimeHeader($subject);
            }

            // Template name (optional)
            $template = $this->getHeader($headers, 'X-Template-Name');
            if ($template) {
                $data['template'] = trim($this->decodeMimeHeader($template));
            }

            // From/To/Cc/Bcc — only if not already set by Envelope
            if (empty($data['from'])) {
                $from = $this->getHeader($headers, 'From');
                $data['from'] = $from ? ($this->parseEmails($from)[0] ?? null) : null;
            }

            if (empty($data['to'])) {
                $to = $this->getHeader($headers, 'To');
                $cc = $this->getHeader($headers, 'Cc');
                $bcc = $this->getHeader($headers, 'Bcc');

                $data['to'] = $to ? $this->parseEmails($to) : [];
                $data['cc'] = $cc ? $this->parseEmails($cc) : [];
                $data['bcc'] = $bcc ? $this->parseEmails($bcc) : [];
            }

            // Attachments
            $data['attachments'] = substr_count($headerStr, "Content-Disposition: attachment");

            // Preview
            $ctype = (string)$this->getHeader($headers, 'Content-Type');
            $ctypeLower = strtolower($ctype);

            if (str_starts_with($ctypeLower, 'text/html')) {
                $data['htmlBody'] = $this->decodePartBody($bodyStr, $headers);
                $data['hasPreview'] = true;
            } elseif (str_starts_with($ctypeLower, 'text/plain')) {
                $data['textBody'] = $this->decodePartBody($bodyStr, $headers);
                $data['hasPreview'] = true;
            } elseif (str_starts_with($ctypeLower, 'multipart/')) {
                $boundary = $this->detectBoundary($ctype, $bodyStr);

                if ($boundary) {
                    $mParts = $this->extractMultipartParts($bodyStr, $boundary);

                    // Prefer HTML
                    foreach ($mParts as $part) {
                        $pHeaders = $this->parseHeaders($part['headers']);
                        $pCtype = strtolower((string)$this->getHeader($pHeaders, 'Content-Type'));
                        if (str_starts_with($pCtype, 'text/html')) {
                            $data['htmlBody'] = $this->decodePartBody($part['body'], $pHeaders);
                            $data['hasPreview'] = true;
                            break;
                        }
                    }
                    // Fallback text/plain
                    if (!$data['hasPreview']) {
                        foreach ($mParts as $part) {
                            $pHeaders = $this->parseHeaders($part['headers']);
                            $pCtype = strtolower((string)$this->getHeader($pHeaders, 'Content-Type'));
                            if (str_starts_with($pCtype, 'text/plain')) {
                                $data['textBody'] = $this->decodePartBody($part['body'], $pHeaders);
                                $data['hasPreview'] = true;
                                break;
                            }
                        }
                    }
                    // Ako još uvijek attachmentsCount==0, prebroji u partovima
                    if (($data['attachments'] ?? 0) === 0) {
                        foreach ($mParts as $part) {
                            $h = $part['headers'];
                            if (stripos($h, 'Content-Disposition: attachment') !== false) {
                                $data['attachments'] = ($data['attachments'] ?? 0) + 1;
                            } elseif (stripos($h, 'Content-Disposition: inline') !== false
                                && preg_match('/filename=/i', $h)) {
                                $data['attachments'] = ($data['attachments'] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        }

        if ($message instanceof Email) {
            if ($data['messageId'] === null) {
                $data['messageId'] = $message->generateMessageId();
            }
            $data['subject'] = $message->subject();

            // Optional template header for template-matching rules
            $templateHeader = $message->getHeaders()->get('X-Template-Name');
            if ($templateHeader) {
                $data['template'] = trim((string)$templateHeader->getBodyAsString());
            }

            if (empty($data['from'])) {
                $from = $message->getFrom();
                $data['from'] = !empty($from) ? $from[0]->getAddress() : null;
            }

            if (empty($data['to'])) {
                $data['to'] = array_map(static fn($r) => $r->getAddress(), $message->getTo());
                $data['cc'] = array_map(static fn($r) => $r->getAddress(), $message->getCc());
                $data['bcc'] = array_map(static fn($r) => $r->getAddress(), $message->getBcc());
            }

            $htmlBody = $message->getHtmlBody();
            $textBody = $message->getTextBody();

            if ($htmlBody) {
                $data['htmlBody'] = $htmlBody;
                $data['hasPreview'] = true;
            } elseif ($textBody) {
                $data['textBody'] = $textBody;
                $data['hasPreview'] = true;
            }

            $data['attachments'] = count($message->getAttachments());
        }

        return $data;
    }

    /**
     * Parse RFC 5322 headers into an associative array (lowercased names).
     * Handles folded headers and concatenates duplicate fields.
     *
     * @return array<string,string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $lines = preg_split("/\r?\n/", $rawHeaders) ?: [];
        $current = '';
        $parsed = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                // Continuation line
                $current .= ' ' . trim($line);
                continue;
            }
            if ($current !== '') {
                [$name, $value] = array_pad(explode(':', $current, 2), 2, '');
                $key = strtolower(trim($name));
                $val = trim($value);
                if ($key !== '') {
                    $parsed[$key] = isset($parsed[$key]) ? ($parsed[$key] . ', ' . $val) : $val;
                }
            }
            $current = $line;
        }
        if ($current !== '') {
            [$name, $value] = array_pad(explode(':', $current, 2), 2, '');
            $key = strtolower(trim($name));
            $val = trim($value);
            if ($key !== '') {
                $parsed[$key] = isset($parsed[$key]) ? ($parsed[$key] . ', ' . $val) : $val;
            }
        }

        return $parsed;
    }

    /** Get a header by (case-insensitive) name. */
    private function getHeader(array $headers, string $name): ?string
    {
        $key = strtolower($name);

        return $headers[$key] ?? null;
    }

    /** Decode RFC 2047 mime-encoded words, falling back safely. */
    private function decodeMimeHeader(string $value): string
    {
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, 0, 'UTF-8');
            if ($decoded !== false) {
                return $decoded;
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * Extracts email addresses from a header line.
     * Pragmatic parser: returns bare addresses.
     *
     * @return string[]
     */
    private function parseEmails(string $header): array
    {
        $emails = [];
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $header, $m)) {
            $emails = array_map('strtolower', $m[0]);
        }

        return array_values(array_unique($emails));
    }

    /**
     * Decode a part body according to Content-Transfer-Encoding and charset to UTF-8.
     *
     * @param array<string,string> $headers
     */
    private function decodePartBody(string $body, array $headers): string
    {
        $cte = strtolower((string)($this->getHeader($headers, 'Content-Transfer-Encoding') ?? ''));
        $ctype = (string)($this->getHeader($headers, 'Content-Type') ?? '');
        $charset = 'UTF-8';
        if (preg_match('/charset="?([^";]+)"?/i', $ctype, $m)) {
            $charset = $m[1];
        }

        // Transfer decoding
        switch ($cte) {
            case 'base64':
                $decoded = base64_decode($body, true);
                if ($decoded !== false) {
                    $body = $decoded;
                }
                break;
            case 'quoted-printable':
                $body = quoted_printable_decode($body);
                break;
            default:
                break;
        }

        // Convert to UTF-8 if needed
        $charsetUpper = strtoupper($charset);
        if ($charsetUpper !== 'UTF-8' && $charsetUpper !== 'UTF8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false) {
                $body = $converted;
            }
        }

        return $body;
    }

    /**
     * Robustno pronađi MIME boundary:
     * 1) pokuša parsirati iz Content-Type headera
     * 2) ako ne uspije, heuristički ga iščita iz početka tijela (prva linija koja izgleda kao --BOUNDARY)
     */
    private function detectBoundary(?string $contentTypeHeader, string $bodyStr): ?string
    {
        $ctype = strtolower((string)($contentTypeHeader ?? ''));

        // 1) Pokušaj iz headera (podržava boundary="..."; i boundary=...)
        if ($ctype !== '') {
            if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentTypeHeader, $m)) {
                return $m[1];
            }
            if (preg_match('/boundary\s*=\s*([^; \t\r\n]+)/i', $contentTypeHeader, $m)) {
                // skini eventualne navodnike/višak
                return trim($m[1], "\"' \t");
            }
        }

        // 2) Heuristika iz tijela: traži prvu liniju koja izgleda kao --TOKEN
        // Normaliziraj EOL
        $normalized = preg_replace("/\r\n?/", "\n", $bodyStr) ?? $bodyStr;
        $lines = explode("\n", ltrim($normalized));
        // RFC 2046: token smije sadržavati A-Z a-z 0-9 i određene simbole; dopuštamo i crticom bogat niz
        foreach ($lines as $idx => $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                // preambula može imati prazne redove, nastavi
                continue;
            }
            if (preg_match('/^--([!#$%&\'*+\.^_`|~0-9A-Za-z-]{1,70})(?:--)?$/', $line, $m)) {
                return $m[1];
            }
            // nakon prve "neprazne" linije bez boundary-a, odustani od heuristike
            if ($idx > 10) {
                break;
            }
        }

        return null;
    }

    /**
     * Split a multipart body into parts using boundary.
     *
     * @return array<int, array{headers:string, body:string}>
     */
    private function extractMultipartParts(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;

        // Normalize line endings to \n
        $normalized = preg_replace("/\r\n?/", "\n", $body) ?? $body;

        // Ensure we start at the first boundary
        $start = strpos($normalized, $delimiter);
        if ($start === false) {
            return [];
        }
        $normalized = substr($normalized, $start);

        // Split on boundaries; keep only content blocks
        $chunks = preg_split('/\n--' . preg_quote($boundary, '/') . '(?:--)?\s*\n?/', "\n" . trim($normalized, "\n"));
        $parts = [];

        foreach ($chunks as $chunk) {
            $chunk = ltrim($chunk, "\n");
            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue;
            }
            // Split part headers/body
            $split = preg_split("/\n\n/", $chunk, 2);
            $pHeaders = $split[0] ?? '';
            $pBody = $split[1] ?? '';

            $parts[] = ['headers' => $pHeaders, 'body' => $pBody];
        }

        return $parts;
    }

    /**
     * Decide if archiving should be skipped via header or env rules.
     */
    private function shouldSkipArchive(RawMessage $message, array $emailData): bool
    {
        // 1) X-Archive-Skip header (Email API)
        $headerSkip = null;
        if ($message instanceof Email) {
            $h = $message->getHeaders()->get('X-Archive-Skip');
            if ($h) {
                $headerSkip = strtolower(trim((string)$h->getBodyAsString()));
            }
        } else {
            // RawMessage: parse headers
            $raw = $message->toString();
            $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
            $headers = $this->parseHeaders($parts[0] ?? '');
            $headerSkip = strtolower(trim((string)($this->getHeader($headers, 'X-Archive-Skip') ?? '')));
        }

        if (in_array($headerSkip, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        // 2) Env rules
        $from = strtolower((string)($emailData['from'] ?? ''));
        if ($from !== '' && in_array($from, $this->ignoreRules['from'], true)) {
            return true;
        }

        $toList = array_map('strtolower', (array)($emailData['to'] ?? []));
        foreach ($toList as $addr) {
            if (in_array($addr, $this->ignoreRules['to'], true)) {
                return true;
            }
        }

        $subject = (string)($emailData['subject'] ?? '');
        foreach ($this->ignoreRules['subject_regex'] as $pattern) {
            // Accept both full regex (/.../i) and a plain substring (we'll preg_quote it)
            $isRegex = preg_match('/^(.).+\1[imsxuADSUXJ]*$/', $pattern) === 1;
            $rx = $isRegex ? $pattern : '/' . preg_quote($pattern, '/') . '/i';
            set_error_handler(static fn() => true); // silence invalid patterns
            $match = @preg_match($rx, $subject) === 1;
            restore_error_handler();
            if ($match) {
                return true;
            }
        }

        $template = strtolower((string)($emailData['template'] ?? ''));
        if ($template !== '' && in_array($template, $this->ignoreRules['templates'], true)) {
            return true;
        }

        return false;
    }

    private function storeRawMessage(string $archivePath, RawMessage $message): void
    {
        $emlPath = $archivePath . '/message.eml';
        $this->filesystem->dumpFile($emlPath, $message->toString());
    }

    private function storePreview(string $archivePath, RawMessage $message, array $emailData): void
    {
        $write = function (string $path, string $payload): void {
            if (strlen($payload) > $this->maxPreviewBytes) {
                $payload = substr($payload, 0, $this->maxPreviewBytes) . "\n<!-- truncated -->";
            }
            $this->filesystem->dumpFile($path, $payload);
        };

        if (!empty($emailData['htmlBody'])) {
            $write($archivePath . '/preview.html', (string)$emailData['htmlBody']);
        } elseif (!empty($emailData['textBody'])) {
            $write($archivePath . '/preview.txt', (string)$emailData['textBody']);
        }
    }

    /**
     * @return array{count:int, bytes:int, items:array<int,array{
     *   filename:string, originalName:string, contentType:?string,
     *   size:int, sha256:string, disposition:?string, cid:?string
     * }>}
     */
    private function storeAttachments(string $archivePath, RawMessage|Email $message): array
    {
        $dir = $archivePath . '/attachments';
        $this->filesystem->mkdir($dir);

        $items = [];
        $totalBytes = 0;
        $i = 0;

        // Case 1: Symfony\Mime\Email provides attachments directly.
        if ($message instanceof Email) {
            foreach ($message->getAttachments() as $part) {
                // Symfony\Component\Mime\Part\DataPart | Attachment
                $original = $part->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename')
                    ?? $part->getPreparedHeaders()->getHeaderParameter('Content-Type', 'name')
                    ?? 'attachment.bin';

                $safe = $this->safeFilename($original);
                $filename = sprintf('%d_%s', $i + 1, $safe);
                $path = $dir . '/' . $filename;

                $binary = (string)$part->getBody(); // already decoded
                // size guard
                if (strlen($binary) > $this->maxAttachmentBytes) {
                    $binary = substr($binary, 0, $this->maxAttachmentBytes);
                }

                $this->filesystem->dumpFile($path, $binary);
                $size = filesize($path) ?: strlen($binary);
                $sha = hash_file('sha256', $path) ?: hash('sha256', $binary);

                $items[] = [
                    'filename' => $filename,
                    'originalName' => $original,
                    'contentType' => $part->getMediaType() && $part->getMediaSubtype()
                        ? $part->getMediaType() . '/' . $part->getMediaSubtype()
                        : ($part->getPreparedHeaders()->get('Content-Type')->getBodyAsString() ?? null),
                    'size' => $size,
                    'sha256' => $sha,
                    'disposition' => $part->asDebugString() && str_contains($part->asDebugString(), 'inline') ?
                        'inline' : 'attachment',
                    'cid' => method_exists($part, 'getContentId') ? ($part->getContentId() ?: null) : null,
                ];

                $totalBytes += $size;
                $i++;
            }

            return ['count' => count($items), 'bytes' => $totalBytes, 'items' => $items];
        }

        // Case 2: Raw MIME — scan multipart parts and persist any with filename or explicit attachment disposition.
        $raw = $message->toString();
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $headers = $this->parseHeaders($parts[0] ?? '');
        $ctypeFull = (string)($this->getHeader($headers, 'Content-Type') ?? '');

        $boundary = $this->detectBoundary($ctypeFull, $parts[1] ?? '');
        if (!$boundary || !isset($parts[1])) {
            return ['count' => 0, 'bytes' => 0, 'items' => []];
        }

        $multipart = $this->extractMultipartParts($parts[1], $boundary);
        foreach ($multipart as $part) {
            $pHeaders = $this->parseHeaders($part['headers']);
            $disp = $this->getHeader($pHeaders, 'Content-Disposition') ?? '';
            $pCtype = $this->getHeader($pHeaders, 'Content-Type') ?? '';

            $original = null;

            // 1) Content-Disposition: filename*=
            if ($disp && preg_match('/filename\*\s*=\s*(?:"|\')?([^"\';\r\n]+)(?:"|\')?/i', $disp, $m1)) {
                $tmp = $m1[1];
                if (stripos($tmp, "UTF-8''") === 0) {
                    $tmp = substr($tmp, 7);
                    $tmp = urldecode($tmp);
                }
                $original = $tmp;
            }
            // 2) Content-Disposition: filename=
            if ($original === null && $disp && preg_match('/filename\s*=\s*(?:"([^"]+)"|([^;\r\n]+))/i', $disp, $m2)) {
                $original = $m2[1] !== '' ? $m2[1] : trim($m2[2]);
            }
            // 3) Fallback: Content-Type: name=
            if ($original === null && $pCtype &&
                preg_match('/;\s*name\s*=\s*(?:"([^"]+)"|([^;\r\n]+))/i', $pCtype, $m3)) {
                $original = $m3[1] !== '' ? $m3[1] : trim($m3[2]);
            }

            // RFC 2231/5987 residual pattern: <charset>''<urlencoded>
            if ($original !== null && str_contains($original, "''")) {
                $tmp = explode("''", $original, 2);
                $original = urldecode($tmp[1] ?? $original);
            }
            if ($original !== null) {
                $original = trim($original, "\"'");
            }

            $hasFilename = $original !== null;
            if ($original === null) {
                $original = 'attachment.bin';
            }

            $isAttachment = stripos($disp, 'attachment') !== false
                || (stripos($disp, 'inline') !== false && $hasFilename);

            if (!$isAttachment) {
                continue;
            }

            $safe = $this->safeFilename($original);
            $filename = sprintf('%d_%s', $i + 1, $safe);
            $path = $dir . '/' . $filename;

            $binary = $this->decodeTransfer($part['body'], $pHeaders);
            if (strlen($binary) > $this->maxAttachmentBytes) {
                $binary = substr($binary, 0, $this->maxAttachmentBytes);
            }

            $this->filesystem->dumpFile($path, $binary);
            $size = filesize($path) ?: strlen($binary);
            $sha = hash_file('sha256', $path) ?: hash('sha256', $binary);

            // Try grab CID for inline parts
            $cid = $this->getHeader($pHeaders, 'Content-Id');
            if ($cid) {
                $cid = trim($cid);
                if ($cid !== '' && $cid[0] === '<' && substr($cid, -1) === '>') {
                    $cid = substr($cid, 1, -1);
                }
            }

            // Derive content type more cleanly if possible
            $ct = null;
            if ($pCtype) {
                $ct = preg_split('/;\s*/', $pCtype)[0];
            }

            $items[] = [
                'filename' => $filename,
                'originalName' => $original,
                'contentType' => $ct,
                'size' => $size,
                'sha256' => $sha,
                'disposition' => stripos($disp, 'inline') !== false ? 'inline' : 'attachment',
                'cid' => $cid ?: null,
            ];

            $totalBytes += $size;
            $i++;
        }

        // NOTE: removed debug dump($items);

        return ['count' => count($items), 'bytes' => $totalBytes, 'items' => $items];
    }

    private function safeFilename(string $name): string
    {
        // strip path traversal and control chars
        $name = preg_replace('#[\\\\/]+#', '_', $name) ?? $name;
        $name = preg_replace('/[^\p{L}\p{N}\.\-\_\(\)\[\]\s]/u', '_', $name) ?? $name;
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment.bin';
        }
        // cap length
        if (strlen($name) > 200) {
            $ext = '';
            if (preg_match('/(\.[A-Za-z0-9]{1,8})$/', $name, $m)) {
                $ext = $m[1];
            }
            $base = substr($name, 0, 200 - strlen($ext));
            $name = $base . $ext;
        }

        return $name;
    }

    /**
     * @param array<string,string> $headers
     */
    private function decodeTransfer(string $body, array $headers): string
    {
        $cte = strtolower((string)($this->getHeader($headers, 'Content-Transfer-Encoding') ?? ''));
        switch ($cte) {
            case 'base64':
                $decoded = base64_decode($body, true);
                if ($decoded !== false) {
                    return $decoded;
                }

                return $body;
            case 'quoted-printable':
                return quoted_printable_decode($body);
            default:
                return $body;
        }
    }

    private function storeMetadata(string $archivePath, array $emailData, string $archiveId): void
    {
        $metadata = [
            'archiveId' => $archiveId,
            'messageId' => $emailData['messageId'],
            'subject' => $emailData['subject'],
            'from' => $emailData['from'],
            'to' => $emailData['to'],
            'cc' => $emailData['cc'],
            'bcc' => $emailData['bcc'],
            'sentAt' => $emailData['sentAt'],
            'transport' => $emailData['transport'],
            'size' => $emailData['size'],
            'hash' => $emailData['hash'],
            'hasPreview' => $emailData['hasPreview'],
            'template' => $emailData['template'],
            'path' => $this->getRelativePath($archivePath),

            'attachmentsCount' => (int)($emailData['attachments'] ?? 0),
            'attachmentsBytes' => (int)($emailData['attachmentsBytes'] ?? 0),
            'attachmentsMeta' => array_values($emailData['attachmentsMeta'] ?? []),
        ];

        $this->filesystem->dumpFile(
            $archivePath . '/meta.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function getRelativePath(string $fullPath): string
    {
        return str_replace($this->archiveRoot . '/', '', $fullPath);
    }

    private function updateDailyIndex(\DateTimeInterface $sentAt, array $emailData, string $archiveId): void
    {
        $indexFile = sprintf('%s/index/%s.ndjson', $this->archiveRoot, $sentAt->format('Y-m-d'));

        $indexEntry = [
            'archiveId' => $archiveId,
            'messageId' => $emailData['messageId'],
            'subject' => $emailData['subject'],
            'from' => $emailData['from'],
            'to' => $emailData['to'],
            'cc' => $emailData['cc'],
            'sentAt' => $emailData['sentAt'],
            'size' => $emailData['size'],
            'hasPreview' => $emailData['hasPreview'],
            'template' => $emailData['template'],
            'path' => sprintf(
                '%s/%s/%s/%s',
                $sentAt->format('Y'),
                $sentAt->format('m'),
                $sentAt->format('d'),
                $archiveId
            ),

            'attachmentsCount' => (int)($emailData['attachments'] ?? 0),
            'attachmentsBytes' => (int)($emailData['attachmentsBytes'] ?? 0),
        ];

        file_put_contents(
            $indexFile,
            json_encode($indexEntry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Backwards-compatible entrypoint if you only have RawMessage/Envelope.
     * (Will synthesize Message-Id and set transport to "default" unless provided.)
     */
    public function archiveEmail(RawMessage $message, ?Envelope $envelope): void
    {
        $this->archiveCore($message, $envelope, null, null);
    }
}

