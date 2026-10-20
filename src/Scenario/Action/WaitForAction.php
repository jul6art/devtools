<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<wait-for selector=".results" timeout="30000"/>`: what an asynchronous page shows late, waited for as long
 * as the step says — in milliseconds — or the run's timeout.
 */
final class WaitForAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'wait-for';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $timeout = isset($step->arguments['timeout']) ? max(1, (int) ceil((int) $step->arguments['timeout'] / 1000)) : $context->timeout;
        Elements::css($client, $step->arguments['selector'] ?? '', $timeout);
    }
}
