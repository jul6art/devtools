<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The bundle's configuration tree.
 *
 * Keep it thin: the options of DevTools itself live in `.devtools/config.xml`, which the standalone
 * mode reads too. A node here is only justified by something that exists in the bridge alone.
 *
 * Write an `->info()` on every node: it is what `config:dump-reference` shows, and it is the only
 * documentation a reader gets before opening the code.
 *
 * > ⚠️ **A node that decides something at compile time cannot be an env var.** `%env(bool:X)%`
 * > reaches a `booleanNode()` as the placeholder *string* and the config layer rejects it. Use a
 * > plain value for anything that gates service registration, and keep env vars for values passed
 * > through to a service at runtime (a `scalarNode` argument).
 */
class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(DevToolsExtension::ALIAS);

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Registers the bundle\'s services. false leaves it installed and inert.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('language')
                    ->info('The language the workflow pages are written in, as two lowercase letters. `.devtools/config.xml` and the --locale option win over it. Default: en.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $language): bool => null !== $language && (!\is_string($language) || 1 !== preg_match('/^[a-z]{2}$/', $language)))
                        ->thenInvalid('%s is not a language: give two lowercase letters, such as "en" or "fr".')
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
