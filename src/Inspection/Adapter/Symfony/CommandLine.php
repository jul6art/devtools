<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

/**
 * Splits the configured console command (`docker compose exec -T php bin/console`) into arguments, the
 * way a shell would split words — and nothing more: no variable, no substitution, no operator. The
 * result is handed to a process one argument at a time, never to a shell (.github/SECURITY.md).
 *
 * Unicode throughout: a path may hold any character, and a command pasted from a browser may hold a
 * non-breaking space, which separates words here rather than sticking them together.
 */
final class CommandLine
{
    /**
     * @return list<string>
     */
    public static function split(string $line): array
    {
        $arguments = [];
        $current = '';
        $inWord = false;
        $quote = null;

        foreach (preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (null !== $quote) {
                if ($character === $quote) {
                    $quote = null;
                } else {
                    $current .= $character;
                }

                continue;
            }

            if ('"' === $character || "'" === $character) {
                $quote = $character;
                $inWord = true;
            } elseif (1 === preg_match('/\s/u', $character)) {
                if ($inWord) {
                    $arguments[] = $current;
                    $current = '';
                    $inWord = false;
                }
            } else {
                $current .= $character;
                $inWord = true;
            }
        }

        if (null !== $quote) {
            throw new \InvalidArgumentException(\sprintf('The command "%s" has an unclosed quote.', $line));
        }

        if ($inWord) {
            $arguments[] = $current;
        }

        return $arguments;
    }
}
