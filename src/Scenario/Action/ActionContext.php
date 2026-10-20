<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Runner\RouteMap;

/**
 * What an action needs besides the browser: the routes, how long it may wait, and where a screenshot goes.
 */
final readonly class ActionContext
{
    /**
     * @param \Closure(string): void $capture saves the screen and the DOM under a name
     */
    public function __construct(public RouteMap $routes, public int $timeout, public \Closure $capture)
    {
    }
}
