<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Laravel;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRun;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRunnerInterface;

/**
 * Replays the real answers of the laravel-minimal Artisan, recorded in tests/Fixtures/console/laravel-minimal/
 * (the command definitions trimmed to the names the adapter reads).
 */
final class RecordedArtisanRunner implements ConsoleRunnerInterface
{
    public const array FILES = [
        'route:list --json' => 'routes',
        'list --format=json' => 'commands',
        'event:list --json' => 'events',
    ];

    /**
     * @var list<list<string>>
     */
    public array $runs = [];

    /**
     * @param array<string, string|int> $overrides recording name => output replacing the recorded one, or the exit code it fails with
     */
    public function __construct(private readonly array $overrides = [], private readonly ?\Throwable $failure = null)
    {
    }

    #[\Override]
    public function run(array $command, string $workingDirectory, float $timeout): ConsoleRun
    {
        $this->runs[] = $command;

        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        $question = \array_slice($command, (int) array_search('artisan', $command, true) + 1);
        $arguments = implode(' ', array_values(array_filter($question, static fn (string $argument): bool => '--no-ansi' !== $argument && !str_starts_with($argument, '--env='))));
        $name = self::FILES[$arguments] ?? throw new \LogicException(\sprintf('No recording for "%s".', $arguments));
        $override = $this->overrides[$name] ?? null;

        if (\is_int($override)) {
            return new ConsoleRun($override, '', 'ERROR  Command "'.$arguments.'" failed.');
        }

        return new ConsoleRun(0, $override ?? (string) file_get_contents(__DIR__.'/../../../Fixtures/console/laravel-minimal/'.$name.'.json'), '');
    }
}
