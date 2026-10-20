<?php

namespace Hexis\EmailArchiveBundle\Tests\Unit\Storage;

use Hexis\EmailArchiveBundle\Storage\LocalArchiveStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalArchiveStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hexis-email-archive-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testPrepareCreatesTheIndexDirectoryAndAGitignore(): void
    {
        (new LocalArchiveStorage($this->root . '/'))->prepare();

        self::assertDirectoryExists($this->root . '/index');
        self::assertSame("# Email Archive - Do not commit\n*\n!.gitignore\n", file_get_contents($this->root . '/.gitignore'));
    }

    public function testPrepareKeepsAnExistingGitignore(): void
    {
        (new Filesystem())->dumpFile($this->root . '/.gitignore', "custom\n");

        (new LocalArchiveStorage($this->root))->prepare();

        self::assertSame("custom\n", file_get_contents($this->root . '/.gitignore'));
    }

    public function testWriteCreatesTheParentDirectories(): void
    {
        $storage = new LocalArchiveStorage($this->root);

        $storage->createDirectory('2026/10/16/143005_0123456789abcdef/attachments');
        $storage->write('2026/10/16/143005_0123456789abcdef/meta.json', '{}');

        self::assertDirectoryExists($this->root . '/2026/10/16/143005_0123456789abcdef/attachments');
        self::assertSame('{}', file_get_contents($this->root . '/2026/10/16/143005_0123456789abcdef/meta.json'));
    }

    public function testIndexEntriesOfADayAreAppendedToOneFile(): void
    {
        $storage = new LocalArchiveStorage($this->root);
        $day = new \DateTimeImmutable('2026-10-16 14:30:05');

        $storage->addIndexEntry($day, '143005_0000000000000001', '{"archiveId":"143005_0000000000000001"}');
        $storage->addIndexEntry($day, '143005_0000000000000002', '{"archiveId":"143005_0000000000000002"}');
        $storage->addIndexEntry($day->modify('+1 day'), '143005_0000000000000003', '{"archiveId":"143005_0000000000000003"}');

        self::assertSame(
            "{\"archiveId\":\"143005_0000000000000001\"}\n{\"archiveId\":\"143005_0000000000000002\"}\n",
            file_get_contents($this->root . '/index/2026-10-16.ndjson'),
        );
        self::assertSame("{\"archiveId\":\"143005_0000000000000003\"}\n", file_get_contents($this->root . '/index/2026-10-17.ndjson'));
    }

    public function testReadAllReadsTheFilesOfOneDirectoryInNameOrder(): void
    {
        $storage = new LocalArchiveStorage($this->root);
        $storage->write('delivery/a/2.json', 'two');
        $storage->write('delivery/a/1.json', 'one');
        $storage->write('delivery/a/deeper/3.json', 'three');

        self::assertSame(['one', 'two'], $storage->readAll('delivery/a'));
        self::assertSame([], $storage->readAll('delivery/none'));
    }

    public function testReadReadsOneFileOrNothing(): void
    {
        $storage = new LocalArchiveStorage($this->root);
        $storage->write('2026/10/16/a/meta.json', '{}');

        self::assertSame('{}', $storage->read('2026/10/16/a/meta.json'));
        self::assertNull($storage->read('2026/10/16/a/preview.html'));
    }

    public function testReadIndexReadsTheLinesOfOneDay(): void
    {
        $storage = new LocalArchiveStorage($this->root);
        $day = new \DateTimeImmutable('2026-10-16 14:30:05');
        $storage->addIndexEntry($day, '143005_0000000000000001', '{"n":1}');
        $storage->addIndexEntry($day, '143005_0000000000000002', '{"n":2}');
        $storage->addIndexEntry($day->modify('+1 day'), '143005_0000000000000003', '{"n":3}');

        self::assertSame(['{"n":1}', '{"n":2}'], $storage->readIndex($day));
        self::assertSame([], $storage->readIndex($day->modify('-1 day')));
    }
}
