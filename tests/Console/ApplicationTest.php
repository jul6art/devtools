<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Console;

use Jul6Art\DevTools\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    public function testItIsNamedAfterTheProduct(): void
    {
        self::assertSame('DevTools', new Application()->getName());
    }

    /**
     * The version ends up in the `tool` attribute of every tracking XML; an empty one would make
     * two generations by two different releases indistinguishable.
     */
    public function testItAlwaysReportsAVersion(): void
    {
        self::assertNotSame('', new Application()->getVersion());
    }

    /**
     * The verbosity is forced: `phpunit.xml.dist` sets `SHELL_VERBOSITY=-1`, which the ApplicationTester
     * of symfony/console 8.x neutralises and the one of 7.4.0 does not. Without the option this test
     * is green on the highest dependency set and reads an empty display on the lowest one.
     */
    public function testItListsItsCommands(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run(['command' => 'list', '--verbose' => true]));
        self::assertStringContainsString('DevTools', $tester->getDisplay());
    }
}
