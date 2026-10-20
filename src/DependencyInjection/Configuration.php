<?php

namespace Hexis\EmailArchiveBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('email_archive');

        $tb->getRootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('archive_root')
                    ->info('Local directory, or the prefix inside storage.service. Default: "%kernel.project_dir%/var/email" on the local disk, "email" in a Flysystem storage.')
                    ->defaultNull()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('service')
                            ->info('Id of a League\Flysystem\FilesystemOperator service to archive into; null keeps the local disk.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->integerNode('max_preview_bytes')->defaultValue(2_000_000)->min(1)->end()
                ->integerNode('max_attachment_bytes')->defaultValue(50_000_000)->min(1)->end()
                ->arrayNode('ignore_rules')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('from')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('to')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('subject_regex')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('templates')->scalarPrototype()->end()->defaultValue([])->end()
                    ->end()
                ->end()

                // Delivery tracking: provider webhooks record events beside the archive; optional register-on-send.
                ->arrayNode('deliverability')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('provider')->defaultValue('null')->info('null | mailgun | ses | <service id>')->end()
                        ->arrayNode('mailgun')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('api_key')->defaultNull()->end()
                                ->scalarNode('domain')->defaultNull()->end()
                                ->enumNode('region')->values(['us', 'eu'])->defaultValue('us')->end()
                                ->arrayNode('webhook')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultFalse()->end()
                                        ->scalarNode('signing_key')->defaultNull()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('ses')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('region')->defaultValue('us-east-1')->end()
                                ->scalarNode('configuration_set')
                                    ->info('Named on every outgoing message (X-SES-CONFIGURATION-SET), so SES publishes its delivery events; empty for none.')
                                    ->defaultValue('')
                                ->end()
                                ->arrayNode('webhook')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultFalse()->end()
                                        ->scalarNode('secret')
                                            ->info('The HTTP basic-auth password every SNS request must carry; empty refuses every request, null accepts any.')
                                            ->defaultNull()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}

