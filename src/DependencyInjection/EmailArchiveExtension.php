<?php

namespace Hexis\EmailArchiveBundle\DependencyInjection;

use Hexis\EmailArchiveBundle\Storage\FlysystemArchiveStorage;
use Hexis\EmailArchiveBundle\Storage\LocalArchiveStorage;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Config\FileLocator;

final class EmailArchiveExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $storageService = $config['storage']['service'];
        $archiveRoot = $config['archive_root'] ?? ($storageService === null ? '%kernel.project_dir%/var/email' : 'email');

        $container->setParameter('hexis_email_archive.enabled', (bool)$config['enabled']);
        $container->setParameter('hexis_email_archive.archive_root', (string)$archiveRoot);
        $container->setParameter('hexis_email_archive.max_preview_bytes', (int)$config['max_preview_bytes']);
        $container->setParameter('hexis_email_archive.max_attachment_bytes', (int)$config['max_attachment_bytes']);
        $container->setParameter('hexis_email_archive.ignore_rules', $config['ignore_rules']);

        if ($storageService === null) {
            $container->register('hexis_email_archive.storage', LocalArchiveStorage::class)
                ->setArguments(['%hexis_email_archive.archive_root%']);
        } else {
            if (!interface_exists(FilesystemOperator::class)) {
                throw new LogicException('The "email_archive.storage.service" option needs league/flysystem. Try running "composer require league/flysystem".');
            }

            $container->register('hexis_email_archive.storage', FlysystemArchiveStorage::class)
                ->setArguments([new Reference($storageService), '%hexis_email_archive.archive_root%']);
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'email_archive';
    }
}

