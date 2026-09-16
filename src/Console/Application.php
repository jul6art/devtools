<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * The standalone entry point: `vendor/bin/devtools`, usable on any project whatever its language.
 *
 * Every command lives in the core and is registered here. The Symfony bridge registers the same
 * commands in `bin/console` under the `devtools:` prefix, and adds nothing but kernel introspection:
 * a command that only works through the bridge is a command the standalone mode silently lacks.
 */
final class Application extends BaseApplication
{
    public const string NAME = 'DevTools';

    public function __construct()
    {
        parent::__construct(self::NAME, self::version());
    }

    /**
     * The installed version as Composer resolved it, so `devtools --version` and the `tool`
     * attribute written into the tracking XML can never disagree with the lock file.
     */
    private static function version(): string
    {
        if (!class_exists(InstalledVersions::class)
            || !InstalledVersions::isInstalled('jul6art/devtools')) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion('jul6art/devtools') ?? 'dev';
    }
}
