<?php

declare(strict_types=1);

namespace Hexis\EmailArchiveBundle\Tests\Unit\Delivery;

use Hexis\EmailArchiveBundle\Delivery\DeliveryEvent;
use Hexis\EmailArchiveBundle\Delivery\StorageDeliveryEventStore;
use Hexis\EmailArchiveBundle\Storage\ArchiveStorage;
use Hexis\EmailArchiveBundle\Storage\FlysystemArchiveStorage;
use Hexis\EmailArchiveBundle\Storage\LocalArchiveStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem as LocalFilesystem;

final class StorageDeliveryEventStoreTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/email-archive-delivery-' . bin2hex(random_bytes(4));
    }

    public static function tearDownAfterClass(): void
    {
        (new LocalFilesystem())->remove(self::$root);
    }

    /** @return iterable<string, array{\Closure(): ArchiveStorage}> */
    public static function storages(): iterable
    {
        yield 'local disk' => [static fn(): ArchiveStorage => new LocalArchiveStorage(self::$root . '/' . bin2hex(random_bytes(4)))];
        yield 'flysystem' => [static fn(): ArchiveStorage => new FlysystemArchiveStorage(new Filesystem(new InMemoryFilesystemAdapter()), 'email')];
    }

    /** @param \Closure(): ArchiveStorage $storage */
    #[DataProvider('storages')]
    public function testEventsAreKeptPerMessageInTheirOrder(\Closure $storage): void
    {
        $store = new StorageDeliveryEventStore($storage());
        $store->record(new DeliveryEvent('<A@host>', 'open', 'ses', new \DateTimeImmutable('2026-10-20T10:05:00+00:00')));
        $store->record(new DeliveryEvent('a@HOST', 'delivery', 'ses', new \DateTimeImmutable('2026-10-20T10:00:00+00:00')));
        $store->record(new DeliveryEvent('<b@host>', 'bounce', 'ses', new \DateTimeImmutable('2026-10-20T10:01:00+00:00')));
        $store->record(new DeliveryEvent('<>', 'delivery', 'ses', new \DateTimeImmutable()));

        self::assertSame(['delivery', 'open'], array_map(static fn(DeliveryEvent $e): string => $e->event, $store->eventsFor('<a@host>')),
            'the Message-ID is normalised, the events ordered by when they happened');
        self::assertSame('open', $store->latestStatus('a@host'));
        self::assertSame('bounce', $store->latestStatus('<b@host>'));
        self::assertSame([], $store->eventsFor('<unknown@host>'));
        self::assertNull($store->latestStatus(null));
    }

    /** @param \Closure(): ArchiveStorage $storage */
    #[DataProvider('storages')]
    public function testTwoEventsAtTheSameMomentAreBothKept(\Closure $storage): void
    {
        $store = new StorageDeliveryEventStore($storage());
        $at = new \DateTimeImmutable('2026-10-20T10:00:00+00:00');
        $store->record(new DeliveryEvent('<a@host>', 'delivery', 'ses', $at));
        $store->record(new DeliveryEvent('<a@host>', 'delivery', 'ses', $at));

        self::assertCount(2, $store->eventsFor('<a@host>'));
    }
}
