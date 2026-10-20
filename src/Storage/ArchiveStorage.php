<?php

namespace Hexis\EmailArchiveBundle\Storage;

/**
 * Where archives are written. Paths are relative to the archive root, e.g. "2026/10/16/<archiveId>/meta.json".
 */
interface ArchiveStorage
{
    /**
     * Prepares the archive root (called when the archive service is built).
     */
    public function prepare(): void;

    public function createDirectory(string $path): void;

    public function write(string $path, string $contents): void;

    /**
     * Adds one archive's line to the index of its day; must be safe when several workers archive at once.
     */
    public function addIndexEntry(\DateTimeInterface $sentAt, string $archiveId, string $line): void;

    /**
     * The contents of the files directly in $directory, in the order of their names; empty when there is none.
     *
     * @return list<string>
     */
    public function readAll(string $directory): array;

    /**
     * A file's contents, or null when there is none.
     */
    public function read(string $path): ?string;

    /**
     * The index lines of one day, one JSON object per archive; empty when nothing was archived that day.
     *
     * @return list<string>
     */
    public function readIndex(\DateTimeInterface $day): array;
}
