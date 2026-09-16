<?php

declare(strict_types=1);

namespace Jul6Art\DevTools;

use Composer\InstalledVersions;

/**
 * The installed version of DevTools, as Composer resolved it: the same string in `--version` and in the
 * `tool` attribute of every tracking file.
 */
final class Version
{
    public static function current(): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('jul6art/devtools')) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion('jul6art/devtools') ?? 'dev';
    }
}
