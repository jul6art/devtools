<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

/**
 * Raises PHP's memory limit for an inspection, the way Composer and PHPStan do.
 *
 * Documenting a repository of a few hundred workflows needs more than the 128 MB a default PHP gives a
 * script, and through the bundle the booted kernel is already in memory: DevTools died inside a real
 * project while the same scan passed standalone. A limit already higher — or unlimited — is left alone,
 * and so is one the machine refuses to change.
 */
final class MemoryLimit
{
    public const string MINIMUM = '512M';

    /**
     * @return string|null the limit as it now is, null when nothing was changed
     */
    public static function raiseTo(string $minimum = self::MINIMUM): ?string
    {
        $current = self::bytes(\ini_get('memory_limit'));
        $wanted = self::bytes($minimum);

        if (-1 === $current || $current >= $wanted) {
            return null;
        }

        return false === @ini_set('memory_limit', $minimum) ? null : $minimum;
    }

    /**
     * `512M` → 536870912, `-1` → -1 (no limit).
     */
    public static function bytes(string $limit): int
    {
        $limit = trim($limit);

        if (1 !== preg_match('/^(-?\d+)\s*([KMG])?$/i', $limit, $matches)) {
            return -1;
        }

        $value = (int) $matches[1];

        return $value < 0 ? -1 : $value * match (strtoupper($matches[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
    }
}
