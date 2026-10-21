<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Laravel;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleRunnerInterface;

/**
 * Asks the analysed project's Artisan a question and reads its JSON answer (ADR-0030). Artisan is a Symfony
 * console: the runner and its failures are the Symfony adapter's.
 *
 * Not `readonly`: it counts the processes it launched (ADR-0042).
 */
final class Artisan
{
    private int $calls = 0;

    /**
     * @param list<string> $command     Artisan, e.g. ['php', 'artisan']
     * @param string|null  $environment null for the project's own APP_ENV
     */
    public function __construct(
        private readonly ConsoleRunnerInterface $runner,
        private readonly string $workingDirectory,
        private readonly array $command,
        private readonly ?string $environment = null,
        private readonly float $timeout = 60,
    ) {
    }

    /**
     * How many times Artisan was actually launched.
     */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * @param list<string> $arguments the command and its options, `--json` or `--format=json` included
     *
     * @return array<mixed>
     */
    public function json(array $arguments): array
    {
        $question = implode(' ', $arguments);
        ++$this->calls;
        $run = $this->runner->run([...$this->command, ...$arguments, '--no-ansi', ...(null === $this->environment ? [] : ['--env='.$this->environment])], $this->workingDirectory, $this->timeout);

        if (0 !== $run->exitCode) {
            throw new ConsoleFailed(\sprintf("`artisan %s` exited with code %d:\n%s", $question, $run->exitCode, ConsoleFailed::firstLines('' !== trim($run->errorOutput) ? $run->errorOutput : $run->output)));
        }

        $output = ltrim($run->output);

        if (!str_starts_with($output, '{') && !str_starts_with($output, '[')) {
            throw new ConsoleFailed(\sprintf("`artisan %s` did not answer with JSON; it printed first:\n%s", $question, ConsoleFailed::firstLines($output)));
        }

        try {
            $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalid) {
            throw new ConsoleFailed(\sprintf('`artisan %s` answered with invalid JSON: %s', $question, $invalid->getMessage()), $invalid->getCode(), $invalid);
        }

        return \is_array($data) ? $data : throw new ConsoleFailed(\sprintf('`artisan %s` answered with JSON that is not an object or a list.', $question));
    }
}
