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
}
