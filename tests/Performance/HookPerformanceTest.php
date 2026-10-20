<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Performance;

use Jul6Art\DevTools\Tests\Support\GeneratesLargeSymfonyProject;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ADR-0019: the PreToolUse hook runs before every edit, so under 300 ms end to end — PHP started, the map
 * read, the answer written — on a 300-route project, for a service every route traverses.
 */
#[CoversNothing]
#[Group('performance')]
final class HookPerformanceTest extends TestCase
{
    use GeneratesLargeSymfonyProject;

    public function testThePreToolUseHookAnswersInUnder300MillisecondsEndToEnd(): void
    {
        $project = $this->inspectedLargeProject();
        $input = (string) json_encode(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => $project.'/src/Service/Catalog.php']]);
        $command = [\PHP_BINARY, \dirname(__DIR__, 2).'/bin/devtools', 'claude:hook', 'PreToolUse', '--path='.$project];

        // A first run warms the file system cache, as a real session's first edit has long done.
        new Process($command, input: $input)->mustRun();

        $started = microtime(true);
        $process = new Process($command, input: $input);
        $process->mustRun();
        $milliseconds = 1000 * (microtime(true) - $started);

        self::assertStringContainsString('is part of 300 documented workflows', $process->getOutput());
        self::assertLessThan(300.0, $milliseconds, \sprintf('The hook took %.0f ms.', $milliseconds));
        fwrite(\STDERR, \sprintf("\n[performance] PreToolUse hook on a service shared by %d routes, end to end: %.0f ms\n", self::ROUTES, $milliseconds));
    }
}
