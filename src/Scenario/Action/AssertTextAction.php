<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<assert-text text="Order #1" selector="h1"/>`: the text, as a person reads it, in the element — the page
 * when no selector is given.
 */
final class AssertTextAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'assert-text';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $text = $step->arguments['text'] ?? '';
        $selector = $step->arguments['selector'] ?? 'body';
        $contains = static fn (): bool => array_any($client->findElements(WebDriverBy::cssSelector($selector)), static fn (WebDriverElement $element): bool => str_contains((string) preg_replace('/\s+/u', ' ', $element->getText()), $text));

        if (!Elements::eventually($client, $context->timeout, $contains)) {
            throw new StepFailed(\sprintf('"%s" does not read "%s" after %d s.', $selector, $text, $context->timeout));
        }
    }
}
