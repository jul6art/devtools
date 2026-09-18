<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\EndToEnd;

use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ADR-0048: the extension included from a project's `phpstan.neon` really registers the rule, and a real
 * PHPStan run reports the drift on the line that carries it.
 */
#[CoversNothing]
#[Group('end-to-end')]
final class PhpStanExtensionTest extends TestCase
{
    use CopiesFixtureProjects;

    public function testARealRunReportsTheDriftAtItsLine(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $devtools = \dirname(__DIR__, 2);

        // Exit code 1: the fixture has no console to answer, which is a warning and not a failure.
        $this->execute([$devtools.'/bin/devtools', 'workflows:inspect', $project, '--no-ai', '--prune'], null, false);

        $pricing = $project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace("'quoted'", "'estimated'", (string) file_get_contents($pricing)));

        file_put_contents($project.'/phpstan.neon', <<<NEON
            includes:
                - {$devtools}/resources/phpstan/extension.neon

            parameters:
                level: 0
                paths:
                    - src
                devtools:
                    path: {$project}

            NEON);

        $phpstan = $this->execute([$devtools.'/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', $project.'/phpstan.neon'], $project, false);
        $output = $phpstan->getOutput().$phpstan->getErrorOutput();

        self::assertStringContainsString('src/Service/OrderPricing.php', $output);
        self::assertStringContainsString('"line":24', $output, 'At the line the fact is written on.');
        // JSON escapes the backslashes of the class name: the two halves are asserted apart.
        self::assertStringContainsString('modified decision', $output);
        self::assertStringContainsString('Order::status', $output);
        self::assertStringContainsString('devtools.workflowDrift', $output, 'The identifier, so the error can be talked about.');
        self::assertStringContainsString('workflows:accept', $output, 'The tip names what to do next.');
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command, ?string $cwd = null, bool $mustSucceed = true): Process
    {
        $process = new Process(['php', ...$command], $cwd, ['XDEBUG_MODE' => 'off'], null, 300.0);
        $process->run();

        if ($mustSucceed) {
            self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        }

        return $process;
    }
}
