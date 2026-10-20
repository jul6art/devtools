<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<assert-visible selector=".flash-success"/>`: present, and shown.
 */
final class AssertVisibleAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'assert-visible';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $selector = $step->arguments['selector'] ?? '';
        $visible = static fn (): bool => array_any($client->findElements(WebDriverBy::cssSelector($selector)), static fn (WebDriverElement $element): bool => $element->isDisplayed());

        if (!Elements::eventually($client, $context->timeout, $visible)) {
            throw new StepFailed(\sprintf('Nothing visible matches "%s" after %d s.', $selector, $context->timeout));
        }
    }
}
