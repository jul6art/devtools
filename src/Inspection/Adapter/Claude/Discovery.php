<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Claude;

use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;

/**
 * Where the discovery of a stack lives: `.devtools/discovery/<stack>.xml`, versioned — the memory of what
 * Claude found, and the input of the Claude path on every later scan (ADR-0013).
 */
final class Discovery
{
    /**
     * `.` → `project`, `front` → `front`, `apps/web` → `apps-web`.
     */
    public static function slug(StackProfile|string $stack): string
    {
        $root = $stack instanceof StackProfile ? $stack->root : $stack;

        return '.' === $root ? 'project' : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($root)), '-');
    }

    public static function file(DevToolsDirectory $directory, string $slug): string
    {
        return $directory->path('discovery/'.$slug.'.xml');
    }
}
