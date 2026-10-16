<?php

namespace Hexis\EmailArchiveBundle\Storage;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The local disk below archive_root; the daily index is one NDJSON file per day.
 */
final class LocalArchiveStorage implements ArchiveStorage
{
    private string $root;
    private Filesystem $filesystem;

    public function __construct(string $root, ?Filesystem $filesystem = null)
    {
        $this->root = rtrim($root, '/');
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    public function prepare(): void
    {
        $this->filesystem->mkdir($this->root . '/index');

        $gitignorePath = $this->root . '/.gitignore';
        if (!$this->filesystem->exists($gitignorePath)) {
            $this->filesystem->dumpFile($gitignorePath, "# Email Archive - Do not commit\n*\n!.gitignore\n");
        }
    }

    public function createDirectory(string $path): void
    {
        $this->filesystem->mkdir($this->root . '/' . $path);
    }

    public function write(string $path, string $contents): void
    {
        // Written to a temporary file and renamed: a reader never sees a partial file.
        $this->filesystem->dumpFile($this->root . '/' . $path, $contents);
    }

    public function addIndexEntry(\DateTimeInterface $sentAt, string $archiveId, string $line): void
    {
        $indexFile = sprintf('%s/index/%s.ndjson', $this->root, $sentAt->format('Y-m-d'));
        $this->filesystem->mkdir(\dirname($indexFile));

        // The exclusive lock serializes the appends of concurrent workers, so lines never interleave.
        if (file_put_contents($indexFile, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new IOException(sprintf('Failed to append to "%s".', $indexFile), 0, null, $indexFile);
        }
    }
}
