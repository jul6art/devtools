<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<fill field="order[customer]" value="ACME"/>`: types into a field, after emptying it.
 */
final class FillAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'fill';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $field = Elements::field($client, $step->arguments['field'] ?? '', $context->timeout);
        $field->clear();
        $field->sendKeys($step->arguments['value'] ?? '');
    }
}
