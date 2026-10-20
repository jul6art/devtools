<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<submit selector="form[name=order]"/>`: a form sent as its Enter key would send it.
 */
final class SubmitAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'submit';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        Elements::css($client, $step->arguments['selector'] ?? '', $context->timeout)->submit();
    }
}
