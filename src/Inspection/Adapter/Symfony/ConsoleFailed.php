<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

/**
 * The project's console could not answer: not installed, kernel not booting, timeout, output that is not
 * the JSON asked for. The adapter falls back to the static scan and says why.
 */
final class ConsoleFailed extends \RuntimeException
{
    /**
     * The first lines of an output, enough to recognise the cause without flooding the report.
     */
    public static function firstLines(string $output, int $lines = 5): string
    {
        $kept = \array_slice(array_values(array_filter(array_map(rtrim(...), explode("\n", $output)), static fn (string $line): bool => '' !== trim($line))), 0, $lines);

        return [] === $kept ? '(no output)' : implode("\n", $kept);
    }
}
