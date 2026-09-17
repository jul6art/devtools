<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\CommandLine;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRun;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRunnerInterface;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ProcessConsoleRunner;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyConsole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandLine::class)]
#[CoversClass(ProcessConsoleRunner::class)]
#[CoversClass(SymfonyConsole::class)]
#[CoversClass(ConsoleFailed::class)]
#[CoversClass(ConsoleRun::class)]
final class ConsoleTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('commandLines')]
    public function testACommandLineIsSplitIntoArgumentsWithoutAShell(string $line, array $expected): void
    {
        self::assertSame($expected, CommandLine::split($line));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commandLines(): iterable
    {
        yield 'default' => ['bin/console', ['bin/console']];
        yield 'docker' => ['docker compose exec -T php bin/console', ['docker', 'compose', 'exec', '-T', 'php', 'bin/console']];
        yield 'quoted' => ['php "my app/bin/console"', ['php', 'my app/bin/console']];
        yield 'single quotes and extra spaces' => ["  symfony   console  'x y' ", ['symfony', 'console', 'x y']];
        yield 'shell metacharacters stay literal' => ['bin/console; rm -rf /', ['bin/console;', 'rm', '-rf', '/']];
        yield 'tabs and accents' => ["docker\texec\u{00a0}", ['docker', 'exec']];
        yield 'multibyte path' => ['php bin/cônsole', ['php', 'bin/cônsole']];
    }

    public function testAnUnclosedQuoteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CommandLine::split('php "bin/console');
    }

    /**
     * Arguments reach the process one by one: nothing is ever interpreted by a shell.
     */
    public function testTheProcessRunnerPassesArgumentsVerbatim(): void
    {
        $run = new ProcessConsoleRunner()->run([\PHP_BINARY, '-r', 'echo json_encode(array_slice($argv, 1));', 'a b', '; echo pwned', '$(id)'], sys_get_temp_dir(), 10);

        self::assertSame(0, $run->exitCode);
        self::assertSame(['a b', '; echo pwned', '$(id)'], json_decode($run->output, true));
    }

    /**
     * A console inheriting SHELL_VERBOSITY=-1 — as exported by PHPUnit, or by a user's shell — runs quiet
     * and prints no JSON at all: the runner resets it for the console it launches.
     */
    public function testTheConsoleNeverInheritsAQuietVerbosity(): void
    {
        $previous = getenv('SHELL_VERBOSITY');
        putenv('SHELL_VERBOSITY=-1');

        try {
            $run = new ProcessConsoleRunner()->run([\PHP_BINARY, '-r', 'echo getenv("SHELL_VERBOSITY");'], sys_get_temp_dir(), 10);
        } finally {
            putenv(false === $previous ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY='.$previous);
        }

        self::assertSame('0', $run->output);
    }

    public function testTheTitleOfDebugConfigIsTheOnlyTextAcceptedBeforeTheJson(): void
    {
        $console = new SymfonyConsole(new RecordedConsoleRunner(), '/project', ['php', 'bin/console'], 'dev');

        self::assertSame([['path' => '^/orders']], array_map(static fn (mixed $rule): array => ['path' => \is_array($rule) ? $rule['path'] : null], $console->json(['debug:config', 'security', 'access_control'])));
    }

    public function testAnythingElseBeforeTheJsonIsAFailureQuotingTheLines(): void
    {
        $runner = new RecordedConsoleRunner(['router' => "PHP Deprecated:  Something is deprecated in /x.php on line 3\n{\"a\": 1}"]);

        $this->expectException(ConsoleFailed::class);
        $this->expectExceptionMessage('PHP Deprecated:  Something is deprecated');

        new SymfonyConsole($runner, '/project', ['php', 'bin/console'], 'dev')->json(['debug:router']);
    }

    public function testANonZeroExitIsAFailureQuotingTheErrorOutput(): void
    {
        $runner = new class implements ConsoleRunnerInterface {
            public function run(array $command, string $workingDirectory, float $timeout): ConsoleRun
            {
                return new ConsoleRun(255, '', "In Kernel.php line 12:\n  Class \"Doctrine\\Bundle\" not found\n");
            }
        };

        $this->expectException(ConsoleFailed::class);
        $this->expectExceptionMessage('Class "Doctrine\Bundle" not found');

        new SymfonyConsole($runner, '/project', ['php', 'bin/console'], 'dev')->json(['debug:router']);
    }

    public function testTheCommandIsThePrefixThenTheArgumentsThenFormatAndEnvironment(): void
    {
        $runner = new RecordedConsoleRunner();

        new SymfonyConsole($runner, '/project', ['docker', 'compose', 'exec', '-T', 'php', 'bin/console'], 'test')->json(['debug:router']);

        self::assertSame([['docker', 'compose', 'exec', '-T', 'php', 'bin/console', 'debug:router', '--format=json', '--env=test']], $runner->runs);
    }
}
