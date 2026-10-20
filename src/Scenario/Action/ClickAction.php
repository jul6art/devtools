<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<click text="Save"/>` or `<click selector=".row .delete"/>`: what a person reads first, a selector for
 * what has no text.
 */
final class ClickAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'click';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        $text = $step->arguments['text'] ?? null;
        $target = null === $text ? Elements::css($client, $step->arguments['selector'] ?? '', $context->timeout) : Elements::clickable($client, $text, $context->timeout);
        $target->click();
    }
}
