<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

use Jul6Art\DevTools\Console\ChangeRenderer;
use Jul6Art\DevTools\Console\CheckRenderer;
use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;

/**
 * `freshness` (ADR-0023): what `workflows:check` finds (ADR-0017), on the workflows the change reaches — a fact
 * changed, an entry point new or gone. Code that changed without a fact moving is a warning.
 */
final readonly class FreshnessStep implements GateStepInterface
{
    public function __construct(private InspectionPipeline $pipeline = new InspectionPipeline())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'freshness';
    }

    #[\Override]
    public function run(GateContext $context): GateStep
    {
        $report = $this->pipeline->run(new InspectionOptions($context->directory->root->path, dryRun: true, noAi: true));

        if ([] !== $report->errors) {
            return new GateStep($this->name(), $report->errors);
        }

        // What the change reaches, and what it declares: a route the change adds is on no map yet.
        $relevant = array_flip([...$context->reached(), ...array_keys(array_intersect($report->entryFiles, $context->changed))]);
        $failures = [];

        foreach ($report->decisions as $id => $decision) {
            if (!isset($relevant[$id])) {
                continue;
            }

            $failures[] = match ($decision->kind) {
                DecisionKind::Create => \sprintf('%s is not documented: devtools workflows:inspect documents it.', $id),
                DecisionKind::Orphan => \sprintf('%s is orphaned: its entry point is gone — restore it, or devtools workflows:inspect --prune.', $id),
                default => null,
            };
        }

        foreach ($report->changeGroups() as $group) {
            $reached = array_values(array_filter($group->workflows, static fn (string $id): bool => isset($relevant[$id])));

            if ([] !== $reached) {
                $failures[] = self::describe($group, $reached);
            }
        }

        $failures = array_values(array_filter($failures, \is_string(...)));
        $warnings = array_map(static fn (string $id): string => \sprintf('%s: its code changed without a fact moving — its page may say something no longer true.', $id), array_values(array_intersect(CheckRenderer::silent($report), array_keys($relevant))));

        if ([] !== $failures) {
            $failures[] = 'devtools workflows:diff --code says what changed; workflows:accept documents it, workflows:reject undoes it.';
        }

        return new GateStep($this->name(), $failures, $warnings, [\sprintf('%d files parsed', $report->filesParsed)]);
    }

    /**
     * @param list<string> $reached
     */
    private static function describe(ChangeGroup $group, array $reached): string
    {
        $change = $group->change;

        return trim(\sprintf(
            '%s %s %s%s — %s',
            ChangeRenderer::natureOf($change->nature),
            ChangeRenderer::subjectOf($change->subject),
            $change->target,
            null === $change->before && null === $change->after ? '' : \sprintf(': %s → %s', $change->before ?? '—', $change->after ?? '—'),
            implode(', ', $reached),
        ));
    }
}
