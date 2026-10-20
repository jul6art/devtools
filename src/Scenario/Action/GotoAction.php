<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<goto route="app_order_show"><param name="id" value="1"/></goto>`: the page of a route.
 */
final class GotoAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'goto';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $client->request('GET', $context->routes->url($step->arguments['route'] ?? '', $step->parameters));
    }
}
