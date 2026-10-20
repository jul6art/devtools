<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Visual;

/**
 * What changed between two normalised DOMs (ADR-0022), as the lines removed and added — and, in one line,
 * what a reader wants first: `<select name="order[product]">` became `<input name="order[product]">`.
 */
final class DomDiff
{
    /**
     * Between two blocks of changes that do not touch.
     */
    public const string HUNK = '@@';

    /**
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return list<string> `- ` removed, `+ ` added, in the order of the pages, `@@` between two blocks
     */
    public function diff(array $before, array $after): array
    {
        // What both share at the top and at the bottom is most of a page: the comparison is between the rest.
        $prefix = 0;

        while ($prefix < \count($before) && $prefix < \count($after) && $before[$prefix] === $after[$prefix]) {
            ++$prefix;
        }

        $suffix = 0;

        while ($suffix < \count($before) - $prefix && $suffix < \count($after) - $prefix && $before[\count($before) - 1 - $suffix] === $after[\count($after) - 1 - $suffix]) {
            ++$suffix;
        }

        return self::lcs(\array_slice($before, $prefix, \count($before) - $prefix - $suffix), \array_slice($after, $prefix, \count($after) - $prefix - $suffix));
    }

    /**
     * The first changes, one per block: an element replaced by another named as such — the outermost of each
     * side, its children left to the diff.
     *
     * @param list<string> $diff
     */
    public function summary(array $diff, int $limit = 3): ?string
    {
        $hunks = array_values(array_filter(array_map(
            static fn (string $hunk): array => array_values(array_filter(explode("\n", $hunk), static fn (string $line): bool => '' !== $line)),
            explode("\n".self::HUNK."\n", "\n".implode("\n", $diff)."\n"),
        )));
        $changes = array_map(static function (array $hunk): string {
            $removed = self::outermost($hunk, '- ');
            $added = self::outermost($hunk, '+ ');

            return match (true) {
                null !== $removed && null !== $added => \sprintf('%s became %s', $removed, $added),
                null !== $removed => 'removed '.$removed,
                default => 'added '.$added,
            };
        }, \array_slice($hunks, 0, $limit));

        return [] === $changes ? null : implode('; ', $changes).(\count($hunks) > $limit ? '; and more in the DOM diff' : '');
    }

    /**
     * @param list<string> $hunk
     */
    private static function outermost(array $hunk, string $side): ?string
    {
        $lines = array_values(array_filter($hunk, static fn (string $line): bool => str_starts_with($line, $side)));
        usort($lines, static fn (string $a, string $b): int => (\strlen($a) - \strlen(ltrim(substr($a, 2)))) <=> (\strlen($b) - \strlen(ltrim(substr($b, 2)))));

        return [] === $lines ? null : trim(substr($lines[0], 2));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return list<string>
     */
    private static function lcs(array $before, array $after): array
    {
        [$rows, $columns] = [\count($before), \count($after)];
        $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($row = $rows - 1; $row >= 0; --$row) {
            for ($column = $columns - 1; $column >= 0; --$column) {
                $lengths[$row][$column] = $before[$row] === $after[$column] ? $lengths[$row + 1][$column + 1] + 1 : max($lengths[$row + 1][$column], $lengths[$row][$column + 1]);
            }
        }

        $diff = [];
        [$row, $column] = [0, 0];

        while ($row < $rows || $column < $columns) {
            if ($row < $rows && $column < $columns && $before[$row] === $after[$column]) {
                ++$row;
                ++$column;

                if ([] !== $diff && self::HUNK !== array_last($diff)) {
                    $diff[] = self::HUNK;
                }
            } elseif ($row < $rows && ($column >= $columns || $lengths[$row + 1][$column] >= $lengths[$row][$column + 1])) {
                // What was there first, then what replaced it: the order a reader expects.
                $diff[] = '- '.$before[$row++];
            } else {
                $diff[] = '+ '.$after[$column++];
            }
        }

        return self::HUNK === array_last($diff) ? \array_slice($diff, 0, -1) : $diff;
    }
}
