<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

use Jul6Art\DevTools\Impact\ImpactedWorkflow;
use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Run\StepResult;
use Jul6Art\DevTools\Scenario\Run\Verdict;
use Jul6Art\DevTools\Scenario\Runner\PantherRunner;
use Jul6Art\DevTools\Scenario\Runner\ScenarioSession;
use Jul6Art\DevTools\Scenario\Scenario;
use Jul6Art\DevTools\Scenario\ScenarioGenerator;
use Jul6Art\DevTools\Scenario\XmlScenarioStore;

/**
 * `scenarios` (ADR-0023): the scenarios of the routes and components the change reaches, replayed (ADR-0021)
 * and compared with their baselines (ADR-0022) — those only, never the whole suite. A reached workflow without
 * a scenario, or a run that cannot happen here, is a warning.
 */
final readonly class ScenariosStep implements GateStepInterface
{
    /**
     * @param bool|null $panther whether Panther is installed — null: look for it
     */
    public function __construct(private ?ScenarioSession $session = null, private ?bool $panther = null)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'scenarios';
    }

    #[\Override]
    public function run(GateContext $context): GateStep
    {
        $reached = array_values(array_filter($context->impact->workflows, static fn (ImpactedWorkflow $workflow): bool => \in_array($workflow->type->name, ScenarioGenerator::TYPES, true)));

        if ([] === $reached) {
            return new GateStep($this->name(), notes: ['The change reaches no route and no component.']);
        }

        $ids = array_map(static fn (ImpactedWorkflow $workflow): string => $workflow->id->value, $reached);
        [$scenarios, $invalid] = new XmlScenarioStore()->readProject($context->directory, $ids);
        $failures = array_map(static fn (string $file, string $error): string => \sprintf('%s cannot be read: %s', $file, $error), array_keys($invalid), $invalid);
        $covered = array_unique(array_map(static fn (Scenario $scenario): string => $scenario->workflow->value, $scenarios));
        $warnings = array_map(static fn (string $id): string => \sprintf('%s has no scenario: devtools scenarios:generate %s writes one.', $id, $id), array_values(array_diff($ids, $covered)));

        if ([] === $scenarios) {
            return new GateStep($this->name(), $failures, $warnings);
        }

        $unavailable = match (true) {
            !($this->panther ?? PantherRunner::available()) => \sprintf('%d scenarios not replayed: Panther is not installed — composer require --dev %s.', \count($scenarios), PantherRunner::PACKAGE),
            null === $context->baseUrl => \sprintf('%d scenarios not replayed: no base URL — <scenarios base-url="…"/> in .devtools/config.xml, or --base-url.', \count($scenarios)),
            default => null,
        };

        if (null !== $unavailable || null === $context->baseUrl) {
            return new GateStep($this->name(), $failures, [...$warnings, $unavailable]);
        }

        [$run, $artifacts, $warning] = ($this->session ?? new ScenarioSession())->run($context->directory, $context->config, $scenarios, $context->baseUrl, $context->at, $context->timeout);
        $relative = $context->directory->root->relative($artifacts);

        foreach ($run->scenarios as $result) {
            if (Verdict::Passed !== $result->verdict) {
                $failures[] = self::describe($result, $relative);
            }
        }

        return new GateStep($this->name(), $failures, [...$warnings, ...(null === $warning ? [] : [$warning])], [\sprintf('%d scenarios replayed — %s/result.xml', \count($run->scenarios), $relative)]);
    }

    /**
     * The scenario, the step, why, and where to look.
     */
    private static function describe(ScenarioResult $result, string $artifacts): string
    {
        $step = array_find($result->steps, static fn (StepResult $step): bool => \in_array($step->verdict, [Verdict::Failed, Verdict::Error], true));
        $where = array_filter([
            null === $step?->screenshot ? null : 'capture '.$artifacts.'/'.$step->screenshot,
            null === $step?->visual?->diffImage ? null : 'diff '.$artifacts.'/'.$step->visual->diffImage,
        ]);

        return \sprintf('%s — %s%s', $result->id, $result->failure() ?? $result->verdict->value, [] === $where ? '' : ' — '.implode(', ', $where));
    }
}
