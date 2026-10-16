<?php

namespace Hexis\EmailArchiveBundle\Tests\Unit\DependencyInjection;

use Hexis\EmailArchiveBundle\DependencyInjection\EmailArchiveExtension;
use Hexis\EmailArchiveBundle\Storage\FlysystemArchiveStorage;
use Hexis\EmailArchiveBundle\Storage\LocalArchiveStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class EmailArchiveExtensionTest extends TestCase
{
    public function testLocalDiskIsTheDefault(): void
    {
        $container = $this->load([]);

        $storage = $container->getDefinition('hexis_email_archive.storage');
        self::assertSame(LocalArchiveStorage::class, $storage->getClass());
        self::assertSame(['%hexis_email_archive.archive_root%'], $storage->getArguments());
        self::assertSame('%kernel.project_dir%/var/email', $container->getParameter('hexis_email_archive.archive_root'));
    }

    public function testArchiveRootIsKeptForTheLocalDisk(): void
    {
        $container = $this->load(['archive_root' => '/srv/mail-archive']);

        self::assertSame('/srv/mail-archive', $container->getParameter('hexis_email_archive.archive_root'));
    }

    public function testStorageServiceSelectsFlysystemWithADefaultPrefix(): void
    {
        $container = $this->load(['storage' => ['service' => 'app.file_storage']]);

        $storage = $container->getDefinition('hexis_email_archive.storage');
        self::assertSame(FlysystemArchiveStorage::class, $storage->getClass());
        self::assertEquals([new Reference('app.file_storage'), '%hexis_email_archive.archive_root%'], $storage->getArguments());
        self::assertSame('email', $container->getParameter('hexis_email_archive.archive_root'));
    }

    public function testArchiveRootIsThePrefixInsideTheStorageService(): void
    {
        $container = $this->load(['storage' => ['service' => 'app.file_storage'], 'archive_root' => 'mail/archive']);

        self::assertSame('mail/archive', $container->getParameter('hexis_email_archive.archive_root'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new EmailArchiveExtension())->load([$config], $container);

        return $container;
    }
}
