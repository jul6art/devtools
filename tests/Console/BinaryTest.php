<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Console;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `bin/devtools` in a separate process, the way a project calls it.
 *
 * What it guards is invisible from inside PHPUnit, where the autoloader is already loaded: a wrong
 * path to `vendor/autoload.php`, a missing shebang, a syntax only the CLI entry point uses.
 */
#[CoversNothing]
final class BinaryTest extends TestCase
{
    public function testTheBinaryRunsStandalone(): void
    {
        $command = \sprintf(
            '%s %s --version 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(__DIR__.'/../../bin/devtools'),
        );

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringStartsWith('DevTools', implode("\n", $output));
    }
}
