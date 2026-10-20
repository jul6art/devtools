<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * One action of the vocabulary of `scenario.xsd`, as the browser performs it (ADR-0021). An action is in the
 * vocabulary only once it has its class here.
 */
interface ActionInterface
{
    /**
     * The name of the action in `scenario.xsd`.
     */
    public function name(): string;

    /**
     * @throws StepFailed when the application does not do what the step says
     */
    public function perform(Client $client, Step $step, ActionContext $context): void;
}
