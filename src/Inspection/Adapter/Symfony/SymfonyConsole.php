<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

/**
 * Asks the analysed project's console a question and reads its JSON answer (ADR-0007).
 */
final readonly class SymfonyConsole
{
    /**
     * The only text accepted before the JSON: the title `debug:config` prints on standard output.
     */
    private const string DEBUG_CONFIG_TITLE = '/\ACurrent configuration for "[^"\n]+"\n=+\n/';

    /**
     * @param list<string> $command the console, e.g. ['php', 'bin/console']
     */
    public function __construct(
        private ConsoleRunnerInterface $runner,
        private string $workingDirectory,
        private array $command,
        private string $environment,
        private float $timeout = 60,
    ) {
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<mixed>
     */
    public function json(array $arguments): array
    {
        $question = implode(' ', $arguments);
        $run = $this->runner->run([...$this->command, ...$arguments, '--format=json', '--env='.$this->environment], $this->workingDirectory, $this->timeout);

        if (0 !== $run->exitCode) {
            throw new ConsoleFailed(\sprintf("`%s` exited with code %d:\n%s", $question, $run->exitCode, ConsoleFailed::firstLines('' !== trim($run->errorOutput) ? $run->errorOutput : $run->output)));
        }

        $output = (string) preg_replace(self::DEBUG_CONFIG_TITLE, '', ltrim($run->output));
        $output = ltrim($output);

        if (!str_starts_with($output, '{') && !str_starts_with($output, '[')) {
            throw new ConsoleFailed(\sprintf("`%s` did not answer with JSON; it printed first:\n%s", $question, ConsoleFailed::firstLines($output)));
        }

        try {
            $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalid) {
            throw new ConsoleFailed(\sprintf('`%s` answered with invalid JSON: %s', $question, $invalid->getMessage()), $invalid->getCode(), $invalid);
        }

        return \is_array($data) ? $data : throw new ConsoleFailed(\sprintf('`%s` answered with JSON that is not an object or a list.', $question));
    }
}
