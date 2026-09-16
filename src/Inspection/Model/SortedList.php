<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * @internal
 */
final class SortedList
{
    /**
     * Sorts by key and refuses two items with the same key: a duplicate is a bug in whatever built
     * the list, and silently dropping one would hide it.
     *
     * @template T
     *
     * @param list<T>             $items
     * @param callable(T): string $key
     *
     * @return list<T>
     */
    public static function of(array $items, callable $key, string $what): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $itemKey = $key($item);

            if (isset($keyed[$itemKey])) {
                throw new InvalidModel(\sprintf('Duplicate %s: "%s".', $what, str_replace("\0", ' ', $itemKey)));
            }

            $keyed[$itemKey] = $item;
        }

        ksort($keyed, \SORT_STRING);

        return array_values($keyed);
    }
}
