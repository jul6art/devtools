<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Node;

use Jul6Art\DevTools\Tests\Support\NodeDependencies;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The Node bridge's script has tests of its own, in JavaScript (tests/Node/): PHPUnit runs them, so that
 * `composer qa` exercises them — or says why it could not (ADR-0031).
 */
#[CoversNothing]
final class NodeScriptTest extends TestCase
{
    public function testTheScriptsOwnTestsPass(): void
    {
        NodeDependencies::install(__DIR__.'/../../Fixtures/projects/angular-minimal');

        // The reporter is named: without a terminal — in CI — Node writes TAP, and `ℹ fail 0` is the spec reporter's.
        $run = new Process(['node', '--test', '--test-reporter=spec', 'tests/Node/devtools-analyze.test.mjs'], \dirname(__DIR__, 3), timeout: 300);
        $run->run();

        self::assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        self::assertMatchesRegularExpression('/^ℹ fail 0$/m', $run->getOutput());
        self::assertMatchesRegularExpression('/^ℹ pass [1-9]\d*$/m', $run->getOutput(), 'Tests ran: none is no proof.');
    }
}
