<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Facebook\WebDriver\WebDriverBy;
use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<select2 field="order[product]" search="Widget" pick="Widget XL"/>`: a Select2 field as a person uses it —
 * open it, type, wait for the results, pick one — then checks the select underneath took the value.
 */
final class Select2Action implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'select2';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $field = $step->arguments['field'] ?? '';
        $pick = $step->arguments['pick'] ?? '';
        $select = Elements::first($client, $context->timeout, \sprintf('no select "%s"', $field), [WebDriverBy::name($field), WebDriverBy::id($field)]);
        $id = (string) $select->getAttribute('id');

        // Select2 puts its container right after the select it hides.
        $opener = $select->findElement(WebDriverBy::xpath('following-sibling::span[contains(concat(" ", normalize-space(@class), " "), " select2-container ")][1]//span[contains(@class, "select2-selection")]'));
        $opener->click();

        $search = Elements::css($client, '.select2-container--open .select2-search__field', $context->timeout);
        $search->sendKeys($step->arguments['search'] ?? '');

        Elements::first($client, $context->timeout, \sprintf('no result "%s" for "%s"', $pick, $step->arguments['search'] ?? ''), [
            WebDriverBy::xpath(\sprintf('//li[contains(@class, "select2-results__option") and not(contains(@class, "loading-results")) and normalize-space()=%s]', Elements::literal($pick))),
        ])->click();

        $chosen = $client->executeScript('var s = arguments[0]; return s.selectedIndex < 0 ? null : s.options[s.selectedIndex].text;', [$select]);

        if (!\is_string($chosen) || trim($chosen) !== $pick) {
            throw new StepFailed(\sprintf('"%s" picked, but the select %s holds "%s".', $pick, '' === $id ? $field : '#'.$id, \is_string($chosen) ? trim($chosen) : ''));
        }
    }
}
