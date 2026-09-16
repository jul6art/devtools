<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRun;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRunnerInterface;

/**
 * Replays the real outputs of the symfony-minimal console, recorded in tests/Fixtures/console/.
 */
final class RecordedConsoleRunner implements ConsoleRunnerInterface
{
    public const array FILES = [
        'debug:router' => 'router',
        'debug:container --tag=console.command' => 'commands',
        'debug:container --tag=messenger.message_handler' => 'handlers',
        'debug:event-dispatcher' => 'events',
        'debug:config framework workflows' => 'workflows',
        'debug:config security access_control' => 'access-control',
    ];

    /**
     * @var list<list<string>>
     */
    public array $runs = [];

    /**
     * @param array<string, string> $overrides recording name => output replacing the recorded one
     */
    public function __construct(private readonly array $overrides = [], private readonly ?\Throwable $failure = null)
    {
    }

    public function run(array $command, string $workingDirectory, float $timeout): ConsoleRun
    {
        $this->runs[] = $command;

        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        // What follows the console, without the options every question carries.
        $question = \array_slice($command, (int) array_search('bin/console', $command, true) + 1);
        $arguments = implode(' ', array_values(array_filter($question, static fn (string $argument): bool => !str_starts_with($argument, '--format=') && !str_starts_with($argument, '--env='))));
        $name = self::FILES[$arguments] ?? throw new \LogicException(\sprintf('No recording for "%s".', $arguments));
        $output = $this->overrides[$name] ?? (string) file_get_contents(__DIR__.'/../../../Fixtures/console/symfony-minimal/'.$name.'.txt');

        return new ConsoleRun(0, $output, '');
    }
}
