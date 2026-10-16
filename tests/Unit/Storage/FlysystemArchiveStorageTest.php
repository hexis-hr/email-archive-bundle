<?php

namespace Hexis\EmailArchiveBundle\Tests\Unit\Storage;

use Hexis\EmailArchiveBundle\Storage\FlysystemArchiveStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class FlysystemArchiveStorageTest extends TestCase
{
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
    }

    public function testFilesAreWrittenBelowThePrefix(): void
    {
        (new FlysystemArchiveStorage($this->filesystem, '/email/'))->write('2026/10/16/a/meta.json', '{}');

        self::assertSame('{}', $this->filesystem->read('email/2026/10/16/a/meta.json'));
    }

    public function testEmptyPrefixWritesAtTheRoot(): void
    {
        (new FlysystemArchiveStorage($this->filesystem))->write('2026/10/16/a/meta.json', '{}');

        self::assertSame('{}', $this->filesystem->read('2026/10/16/a/meta.json'));
    }

    public function testPrepareAndDirectoriesWriteNothing(): void
    {
        $storage = new FlysystemArchiveStorage($this->filesystem, 'email');

        $storage->prepare();
        $storage->createDirectory('2026/10/16/a/attachments');

        self::assertSame([], $this->filesystem->listContents('', true)->toArray());
    }

    public function testEveryIndexEntryIsItsOwnFileUnderItsDay(): void
    {
        $storage = new FlysystemArchiveStorage($this->filesystem, 'email');
        $day = new \DateTimeImmutable('2026-10-16 14:30:05');

        $storage->addIndexEntry($day, '143005_0000000000000001', '{"archiveId":"143005_0000000000000001"}');
        $storage->addIndexEntry($day, '143005_0000000000000002', '{"archiveId":"143005_0000000000000002"}');

        $files = $this->filesystem->listContents('email/index/2026-10-16')->map(static fn($item): string => $item->path())->toArray();
        sort($files);
        self::assertSame([
            'email/index/2026-10-16/143005_0000000000000001.ndjson',
            'email/index/2026-10-16/143005_0000000000000002.ndjson',
        ], $files);
        self::assertSame(
            "{\"archiveId\":\"143005_0000000000000002\"}\n",
            $this->filesystem->read('email/index/2026-10-16/143005_0000000000000002.ndjson'),
        );
    }
}
