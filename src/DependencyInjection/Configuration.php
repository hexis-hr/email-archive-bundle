<?php

namespace Hexis\EmailArchiveBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    private const IGNORE_RULE_KEYS = ['from', 'to', 'subject_regex', 'templates'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('email_archive');

        $tb->getRootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('archive_root')->defaultValue('%kernel.project_dir%/var/email')->end()
                ->integerNode('max_preview_bytes')->defaultValue(2_000_000)->min(1)->end()
                ->integerNode('max_attachment_bytes')->defaultValue(50_000_000)->min(1)->end()
                ->variableNode('ignore_rules')
                    ->info('Array with keys from, to, subject_regex, templates, or a %env(json:...)% value resolving to one.')
                    ->defaultValue(['from' => [], 'to' => [], 'subject_regex' => [], 'templates' => []])
                    ->validate()
                        ->ifTrue(static fn($v) => !is_array($v) && !is_string($v))
                        ->thenInvalid('ignore_rules must be an array or an env placeholder.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn($v) => is_array($v) && array_diff(array_keys($v), self::IGNORE_RULE_KEYS) !== [])
                        ->then(static function (array $v) {
                            throw new \InvalidArgumentException(sprintf(
                                'Unknown ignore_rules key(s): %s. Allowed: %s.',
                                implode(', ', array_diff(array_keys($v), self::IGNORE_RULE_KEYS)),
                                implode(', ', self::IGNORE_RULE_KEYS)
                            ));
                        })
                    ->end()
                ->end()
            ->end();

        return $tb;
    }
}

