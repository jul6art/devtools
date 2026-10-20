<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Facebook\WebDriver\WebDriverBy;
use Jul6Art\DevTools\Scenario\Runner\StepFailed;
use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<assert-count selector="table.orders tbody tr" count="3"/>`: exactly so many — zero included.
 */
final class AssertCountAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'assert-count';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $selector = $step->arguments['selector'] ?? '';
        $expected = (int) ($step->arguments['count'] ?? 0);
        $count = static fn (): int => \count($client->findElements(WebDriverBy::cssSelector($selector)));

        if (!Elements::eventually($client, $context->timeout, static fn (): bool => $count() === $expected)) {
            throw new StepFailed(\sprintf('"%s" matches %d elements, %d expected.', $selector, $count(), $expected));
        }
    }
}
