<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

/**
 * Typed reads in JSON a console produced: nothing in it is trusted to have the expected shape.
 *
 * @internal
 */
final class Json
{
    /**
     * @return array<mixed>
     */
    public static function array(mixed $data, string $key): array
    {
        $value = \is_array($data) ? ($data[$key] ?? null) : null;

        return \is_array($value) ? $value : [];
    }

    public static function string(mixed $data, string $key): ?string
    {
        $value = \is_array($data) ? ($data[$key] ?? null) : null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }
}
