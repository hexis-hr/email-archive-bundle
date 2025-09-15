<?php

namespace Hexis\EmailArchiveBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

final class EmailArchiveExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('hexis_email_archive.enabled', (bool)$config['enabled']);
        $container->setParameter('hexis_email_archive.archive_root', (string)$config['archive_root']);
        $container->setParameter('hexis_email_archive.max_preview_bytes', (int)$config['max_preview_bytes']);
        $container->setParameter('hexis_email_archive.max_attachment_bytes', (int)$config['max_attachment_bytes']);
        $container->setParameter('hexis_email_archive.ignore_rules', $config['ignore_rules']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'email_archive';
    }
}

