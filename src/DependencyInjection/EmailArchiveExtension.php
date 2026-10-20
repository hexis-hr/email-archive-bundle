<?php

namespace Hexis\EmailArchiveBundle\DependencyInjection;

use Hexis\EmailArchiveBundle\Deliverability\DeliverabilityTrackerInterface;
use Hexis\EmailArchiveBundle\Deliverability\MailgunTracker;
use Hexis\EmailArchiveBundle\Deliverability\SesTracker;
use Hexis\EmailArchiveBundle\EventSubscriber\SesConfigurationSetSubscriber;
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

        $d = $config['deliverability'];
        $provider = (string) $d['provider'];
        $container->setParameter('hexis_email_archive.deliverability.provider', $provider);
        $container->setParameter('hexis_email_archive.deliverability.mailgun.api_key', $d['mailgun']['api_key']);
        $container->setParameter('hexis_email_archive.deliverability.mailgun.domain', $d['mailgun']['domain']);
        $container->setParameter('hexis_email_archive.deliverability.mailgun.region', $d['mailgun']['region']);
        $container->setParameter('hexis_email_archive.deliverability.mailgun.webhook.signing_key', $d['mailgun']['webhook']['signing_key']);
        $container->setParameter('hexis_email_archive.deliverability.ses.region', $d['ses']['region']);
        $container->setParameter('hexis_email_archive.deliverability.ses.webhook.secret', $d['ses']['webhook']['secret']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        // Provider services stay out of the container unless used.
        $ses = $d['ses']['enabled'] || 'ses' === $provider;
        if ($d['mailgun']['enabled'] || 'mailgun' === $provider) {
            $loader->load('delivery_mailgun.yaml');
        }
        if ($ses) {
            $loader->load('delivery_ses.yaml');
            $container->register(SesConfigurationSetSubscriber::class, SesConfigurationSetSubscriber::class)
                ->setArguments([(string) $d['ses']['configuration_set']])
                ->addTag('kernel.event_subscriber');
        }
        if ($d['mailgun']['webhook']['enabled']) {
            $loader->load('webhook_mailgun.yaml');
        }
        if ($d['ses']['webhook']['enabled']) {
            $loader->load('webhook_ses.yaml');
        }

        // The tracker the subscriber registers each send with (NullTracker, set in services.yaml, unless a provider is chosen).
        $trackerId = match ($provider) {
            'null' => null,
            'mailgun' => MailgunTracker::class,
            'ses' => SesTracker::class,
            default => $provider, // a service id of the host's
        };
        if (null !== $trackerId) {
            $container->setAlias(DeliverabilityTrackerInterface::class, $trackerId);
        }
    }

    public function getAlias(): string
    {
        return 'email_archive';
    }
}

