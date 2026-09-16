<?php

declare(strict_types=1);

namespace App\Util;

final class StringHelper
{
    public static function slug(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));
    }
}
