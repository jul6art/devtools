<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverSelect;
use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<select field="order[status]" value="Paid"/>`: an option of a native select, by the text a person reads,
 * else by its value.
 */
final class SelectAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'select';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $value = $step->arguments['value'] ?? '';
        $select = new WebDriverSelect(Elements::field($client, $step->arguments['field'] ?? '', $context->timeout));

        try {
            $select->selectByVisibleText($value);
        } catch (NoSuchElementException) {
            try {
                $select->selectByValue($value);
            } catch (NoSuchElementException) {
                throw new StepFailed(\sprintf('No option "%s" in "%s".', $value, $step->arguments['field'] ?? ''));
            }
        }
    }
}
