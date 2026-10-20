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

    public function testTrackingIsOffByDefault(): void
    {
        $container = $this->load([]);

        self::assertSame('Hexis\\EmailArchiveBundle\\Deliverability\\NullTracker', (string) $container->getAlias('Hexis\\EmailArchiveBundle\\Deliverability\\DeliverabilityTrackerInterface'));
        self::assertFalse($container->hasDefinition('Hexis\\EmailArchiveBundle\\Controller\\Webhook\\SesWebhookController'));
        self::assertFalse($container->hasDefinition('Hexis\\EmailArchiveBundle\\EventSubscriber\\SesConfigurationSetSubscriber'));
    }

    public function testSesTrackingNamesTheConfigurationSetAndGuardsItsWebhook(): void
    {
        $container = $this->load(['deliverability' => ['provider' => 'ses', 'ses' => ['configuration_set' => 'tenant-set', 'webhook' => ['enabled' => true, 'secret' => 'fake']]]]);

        self::assertSame('Hexis\\EmailArchiveBundle\\Deliverability\\SesTracker', (string) $container->getAlias('Hexis\\EmailArchiveBundle\\Deliverability\\DeliverabilityTrackerInterface'));
        self::assertSame(['tenant-set'], $container->getDefinition('Hexis\\EmailArchiveBundle\\EventSubscriber\\SesConfigurationSetSubscriber')->getArguments());
        self::assertSame('%hexis_email_archive.deliverability.ses.webhook.secret%',
            $container->getDefinition('Hexis\\EmailArchiveBundle\\Controller\\Webhook\\SesWebhookController')->getArgument('$secret'));
        self::assertSame('fake', $container->getParameter('hexis_email_archive.deliverability.ses.webhook.secret'));
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
