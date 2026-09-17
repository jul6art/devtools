<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\Symfony\DependencyInjection;

use Jul6Art\DevTools\Command\ClaudeInstallCommand;
use Jul6Art\DevTools\Command\InitCommand;
use Jul6Art\DevTools\Command\StackDetectCommand;
use Jul6Art\DevTools\Command\WorkflowsApplyCommand;
use Jul6Art\DevTools\Command\WorkflowsInspectCommand;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Wires the bundle's services and turns its configuration into something the services can read.
 *
 * Two rules this ecosystem arrived at the hard way:
 *
 * 1. **A brick whose dependency is optional is registered conditionally**, from here, guarded by
 *    `class_exists()` / `interface_exists()` — never by an attribute on the class. An
 *    `#[AsDecorator]` or `#[AsDoctrineListener]` on a vendor class is only honoured if the
 *    application autoconfigures `vendor/`, which it should not, and it makes the class
 *    unloadable when the package is absent.
 * 2. **A service that needs another *service* to exist is checked in a compiler pass**, not
 *    here: an extension runs before the other bundles have configured anything, so
 *    `$container->has('some.service')` is always false at this point.
 */
class DevToolsExtension extends Extension
{
    public const string ALIAS = 'devtools';

    /**
     * @var array<class-string, string>
     */
    public const array COMMANDS = [
        InitCommand::class => 'init',
        StackDetectCommand::class => 'stack:detect',
        WorkflowsInspectCommand::class => 'workflows:inspect',
        WorkflowsApplyCommand::class => 'workflows:apply',
        ClaudeInstallCommand::class => 'claude:install',
    ];

    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        if (false === ($config['enabled'] ?? true)) {
            return;
        }

        // Exposed as a container parameter so an application can branch on it, and so
        // `debug:container --parameter` tells the truth about what is active.
        $container->setParameter(self::ALIAS.'.enabled', true);
        $container->setParameter(self::ALIAS.'.language', \is_string($config['language'] ?? null) ? $config['language'] : null);

        // The core commands, under the devtools: prefix and pointed at the application by default. Nothing
        // else: whatever a command does, it does identically through vendor/bin/devtools.
        foreach (self::COMMANDS as $class => $name) {
            $definition = $container->register('devtools.command.'.str_replace(':', '_', $name), $class)
                ->setArgument('$defaultPath', '%kernel.project_dir%')
                ->addTag('console.command', ['command' => 'devtools:'.$name]);

            if (WorkflowsInspectCommand::class === $class) {
                $definition->setArgument('$defaultLanguage', '%'.self::ALIAS.'.language%');
            }
        }
    }

    #[\Override]
    public function getAlias(): string
    {
        return self::ALIAS;
    }
}
