<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;

final class ScenariosUnavailable extends \RuntimeException
{
    public static function notInspected(): self
    {
        return new self('The project was never inspected: run workflows:inspect first.');
    }

    public static function noScenarioFor(WorkflowId $workflow): self
    {
        return new self(\sprintf('"%s" is not a route or a component documented in this project: scenarios are written for those only.', $workflow->value));
    }
}
