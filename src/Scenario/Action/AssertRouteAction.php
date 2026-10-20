<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<assert-route name="app_order_show"/>`: the page reached is one of this route — after a redirect too.
 */
final class AssertRouteAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'assert-route';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $route = $step->arguments['name'] ?? '';

        if (!Elements::eventually($client, $context->timeout, static fn (): bool => $context->routes->matches($route, $client->getCurrentURL()))) {
            throw new StepFailed(\sprintf('Expected the route "%s" (%s), the browser is at %s.', $route, $context->routes->path($route), parse_url($client->getCurrentURL(), \PHP_URL_PATH) ?: '/'));
        }
    }
}
