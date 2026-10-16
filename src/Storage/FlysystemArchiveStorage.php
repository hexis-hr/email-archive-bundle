<?php

namespace Hexis\EmailArchiveBundle\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * A Flysystem filesystem (S3, local, ...) below a prefix. Appending is not atomic on object storage, so the daily
 * index is one single-line NDJSON file per archive: index/YYYY-MM-DD/<archiveId>.ndjson.
 */
final class FlysystemArchiveStorage implements ArchiveStorage
{
    private FilesystemOperator $filesystem;
    private string $prefix;

    public function __construct(FilesystemOperator $filesystem, string $prefix = '')
    {
        $this->filesystem = $filesystem;
        $this->prefix = trim($prefix, '/');
    }

    public function prepare(): void
    {
        // Nothing to prepare: object storage has no directories, and a .gitignore means nothing there.
    }

    public function createDirectory(string $path): void
    {
        // Files create their own path; an empty directory would only be a marker object.
    }

    public function write(string $path, string $contents): void
    {
        $this->filesystem->write($this->location($path), $contents);
    }

    public function addIndexEntry(\DateTimeInterface $sentAt, string $archiveId, string $line): void
    {
        $this->write(sprintf('index/%s/%s.ndjson', $sentAt->format('Y-m-d'), $archiveId), $line . "\n");
    }

    private function location(string $path): string
    {
        return $this->prefix === '' ? $path : $this->prefix . '/' . $path;
    }
}
