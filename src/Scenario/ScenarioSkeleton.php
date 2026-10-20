<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Tracking\TrackingDocument;

/**
 * The scenario the facts give without AI (ADR-0020): a route that answers GET without a parameter is
 * visited as the role its security names, its route asserted, the page captured. Replayable at once, and
 * the base Claude starts from.
 */
final class ScenarioSkeleton
{
    public const string NAME = 'smoke';

    public static function of(TrackingDocument $workflow): ?Scenario
    {
        $methods = $workflow->main->attributes['methods'] ?? '';
        $path = $workflow->main->attributes['path'] ?? '';

        // A parameter needs a value only the project's data knows: that scenario is Claude's, or yours.
        if ('routes' !== $workflow->type->name || str_contains($path, '{') || ('' !== $methods && !\in_array('GET', explode('|', $methods), true))) {
            return null;
        }

        $route = $workflow->main->name;

        return new Scenario(
            $workflow->id,
            self::NAME,
            [
                new Step('goto', ['route' => $route]),
                new Step('assert-route', ['name' => $route]),
                new Step('screenshot', ['name' => str_replace('.', '-', $workflow->id->pageName())]),
            ],
            self::role($workflow->main->attributes['security'] ?? null),
        );
    }

    /**
     * The most specific role the security asks for: the last one listed — a page for `ROLE_OPERATOR` usually
     * asks for `ROLE_USER` first. Without a role, being authenticated is a login too; without either, none.
     */
    public static function role(?string $security): ?string
    {
        preg_match_all('/\b(ROLE_[A-Z0-9_]+|IS_AUTHENTICATED_[A-Z_]+)\b/', $security ?? '', $found);
        $roles = array_values(array_filter($found[1], static fn (string $role): bool => str_starts_with($role, 'ROLE_')));

        return array_last($roles) ?? array_last($found[1]);
    }
}
