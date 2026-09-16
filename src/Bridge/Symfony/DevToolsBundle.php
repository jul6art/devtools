<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\Symfony;

use Jul6Art\DevTools\Bridge\Symfony\DependencyInjection\DevToolsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The `require-dev` wrapper around the standalone core.
 *
 * It registers the core commands in `bin/console` and provides the Symfony adapter with access to
 * the compiled kernel — and nothing else. Every piece of logic lives in the core, testable without
 * Symfony; nothing outside `Bridge/Symfony` may depend on `symfony/http-kernel`.
 *
 * Registering a compiler pass? Override `build()` here — a pass is how you check that a service
 * the application may or may not have actually exists, which an extension cannot do (extensions
 * run before the other bundles have had their say):
 *
 * ```php
 * #[\Override]
 * public function build(ContainerBuilder $container): void
 * {
 *     parent::build($container);
 *
 *     $container->addCompilerPass(new SomethingOptionalPass());
 * }
 * ```
 */
class DevToolsBundle extends Bundle
{
    /**
     * Symfony derives the alias from the class name and would expect `dev_tools`. The product is
     * called DevTools, its folder `.devtools/` and its commands `devtools:*`, so the configuration
     * key follows them rather than the underscore convention.
     */
    #[\Override]
    public function getContainerExtension(): ExtensionInterface
    {
        return new DevToolsExtension();
    }

    #[\Override]
    public function getPath(): string
    {
        return __DIR__;
    }
}
