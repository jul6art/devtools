<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * What a command of the project answered.
 */
final readonly class ToolOutput
{
    /**
     * @param int|null $exitCode null when it did not finish
     */
    public function __construct(public ?int $exitCode, public string $output, public string $errors = '')
    {
    }

    /**
     * The first line worth showing: of the output, else of the errors.
     */
    public function firstLine(): string
    {
        foreach ([$this->output, $this->errors] as $text) {
            foreach (explode("\n", $text) as $line) {
                if ('' !== trim($line)) {
                    return mb_substr(trim($line), 0, 200);
                }
            }
        }

        return null === $this->exitCode ? 'it did not finish' : \sprintf('exit code %d', $this->exitCode);
    }
}
