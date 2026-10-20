<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Action;

use Jul6Art\DevTools\Scenario\Step;
use Symfony\Component\Panther\Client;

/**
 * `<screenshot name="after-save"/>`: the screen and the DOM, kept with the run — what the visual comparison
 * reads (ADR-0022).
 */
final class ScreenshotAction implements ActionInterface
{
    #[\Override]
    public function name(): string
    {
        return 'screenshot';
    }

    #[\Override]
    public function perform(Client $client, Step $step, ActionContext $context): void
    {
        ($context->capture)($step->arguments['name'] ?? 'screen');
    }
}
