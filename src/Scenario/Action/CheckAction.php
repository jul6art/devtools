<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<check field="order[urgent]"/>`: a checkbox or a radio button checked — left alone when it already is.
 */
final class CheckAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'check';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $field = Elements::field($client, $step->arguments['field'] ?? '', $context->timeout);

        if (!$field->isSelected()) {
            $field->click();
        }
    }
}
