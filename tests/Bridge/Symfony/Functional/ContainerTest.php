<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\Symfony\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

/**
 * The first test to write, and the one that keeps paying: a real container, built with the bundle
 * registered.
 *
 * It catches what no unit test can — a services.yaml that does not parse, a reference to a service
 * that does not exist, a configuration node the extension reads under another name. Every one of
 * those is invisible until something boots.
 */
#[CoversNothing]
final class ContainerTest extends AbstractFunctionalTestCase
{
    public function testTheBundleBoots(): void
    {
        self::assertTrue($this->boot()->getParameter('devtools.enabled'));
    }

    /**
     * The same commands as vendor/bin/devtools, under the devtools: prefix: a command available through
     * one mode only is a command the other mode silently lacks.
     */
    public function testTheCoreCommandsAreRegisteredUnderTheDevtoolsPrefix(): void
    {
        $container = $this->boot();

        self::assertTrue($container->has('console.command_loader'));

        $loader = $container->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);
        $names = array_values(array_filter($loader->getNames(), static fn (string $name): bool => str_starts_with($name, 'devtools:')));
        sort($names);
        self::assertSame(['devtools:claude:install', 'devtools:init', 'devtools:knowledge:list', 'devtools:knowledge:promote', 'devtools:stack:detect', 'devtools:workflows:apply', 'devtools:workflows:inspect'], $names);
    }

    public function testTheConfiguredLanguageReachesTheInspectionCommand(): void
    {
        $container = $this->boot('test', ['language' => 'fr']);

        self::assertSame('fr', $container->getParameter('devtools.language'));
        self::assertNull($this->boot()->getParameter('devtools.language'), 'Nothing configured: the pages are written in English.');
    }

    /**
     * `enabled: false` must leave the bundle installed and inert — an application should be able
     * to switch it off without uninstalling it, and without its optional dependencies becoming
     * required.
     */
    public function testItCanBeDisabled(): void
    {
        self::assertFalse($this->boot('test', ['enabled' => false])->hasParameter('devtools.enabled'));
    }
}
