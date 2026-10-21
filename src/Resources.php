<?php

declare(strict_types=1);

namespace Jul6Art\DevTools;

/**
 * Locates the files DevTools ships in `resources/`: schemas, knowledge, prompts, templates.
 */
final class Resources
{
    public static function path(string $relative): string
    {
        // Resolved, so that a path written into a brief reads plainly (no `src/../`).
        $base = realpath(__DIR__.'/../resources') ?: __DIR__.'/../resources';

        return $base.'/'.ltrim($relative, '/');
    }

    /**
     * A resource as a file another process can read: itself — or, inside a phar (ADR-0034), a copy made once
     * next to the system's temporary files, under a name that changes with its content. Node runs the bridge's
     * script, PHP's built-in server the review page's router: neither opens `phar://`.
     */
    public static function file(string $relative): string
    {
        $path = self::path($relative);

        if (!str_starts_with($path, 'phar://')) {
            return $path;
        }

        $content = (string) file_get_contents($path);
        $copy = sys_get_temp_dir().'/devtools-'.substr(hash('sha256', $content), 0, 16).'-'.basename($relative);

        if (!is_file($copy)) {
            file_put_contents($copy, $content);
        }

        return $copy;
    }
}
