<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Link\RelatedLinks;
use Jul6Art\DevTools\Tracking\Revision;

/**
 * Renders the page of a workflow in the fixed template of ADR-0044: nine sections, the state machine and the
 * navigation drawn at the top of "Decisions" (ADR-0043 had ten, ADR-0008 eleven).
 *
 * The sections DevTools owns are always rendered from the model. Those Claude owns hold "—" — except the
 * cross-cutting mechanisms, which DevTools fills from the model — unless a page Claude wrote is given, in
 * which case its content is kept: a factual rewrite never erases a written page.
 *
 * The page carries no inventory any more: the files and the tests of a workflow live in its XML tracking
 * file, which is the one that links the workflow to its code and the one freshness reads. On a real
 * project those two tables were 250 of a page's 344 lines.
 *
 * No date of the run appears anywhere: the page of an unchanged workflow renders to the same bytes.
 */
final class PageRenderer
{
    /**
     * @param list<Revision>  $history oldest first, at least one
     * @param ParsedPage|null $written the current page, when Claude wrote it
     */
    public function render(Workflow $workflow, array $history, RenderingContext $context, ?ParsedPage $written = null): string
    {
        $last = array_last($history) ?? throw new \InvalidArgumentException(\sprintf('The page of "%s" needs at least one revision.', $workflow->id));

        $factual = [
            PageSection::Summary->value => MarkdownWriter::EMPTY,
            PageSection::Trigger->value => $this->trigger($workflow, $written?->preconditions()),
            PageSection::Journey->value => MarkdownWriter::EMPTY,
            PageSection::Decisions->value => MarkdownWriter::EMPTY,
            PageSection::Data->value => MarkdownWriter::EMPTY,
            PageSection::CrossCutting->value => $this->crossCutting($workflow),
            PageSection::Attention->value => MarkdownWriter::EMPTY,
            PageSection::Related->value => $this->related($workflow, $context),
            PageSection::History->value => $this->history($history),
        ];

        $lines = [
            '# '.$workflow->title,
            \sprintf('%s · type: %s · last updated: %s · commit: %s', MarkdownWriter::code($workflow->id->value), $workflow->type->name, $last->at->format('Y-m-d'), null === $last->commit ? MarkdownWriter::EMPTY : substr($last->commit, 0, 7)),
        ];

        foreach (PageSection::cases() as $section) {
            $content = $factual[$section->value];

            if ($section->writtenByClaude() && null !== $written?->section($section)) {
                $content = $written->section($section);
            }

            if (PageSection::Decisions === $section) {
                $content = $this->decisions($workflow, $content);
            }

            $lines[] = '';
            $lines[] = '## '.$section->value;
            $lines[] = '';
            $lines[] = '' === trim($content) ? MarkdownWriter::EMPTY : $content;
        }

        return implode("\n", $lines)."\n";
    }

    private function trigger(Workflow $workflow, ?string $preconditions): string
    {
        $rows = [['Entry point', self::describe($workflow->main->kind, $workflow->main->name, $workflow->main->attributes)]];

        foreach ($workflow->satellites as $satellite) {
            $rows[] = ['Satellite', self::describe($satellite->kind, $satellite->name, $satellite->attributes)];
        }

        foreach ($workflow->main->attributes as $name => $value) {
            if (!\in_array($name, ['path', 'methods', 'security'], true)) {
                $rows[] = [ucfirst($name), MarkdownWriter::code($value)];
            }
        }

        $security = $workflow->main->attributes['security'] ?? null;
        $rows[] = ['Security', null === $security ? MarkdownWriter::EMPTY : implode(', ', array_map(MarkdownWriter::code(...), explode(', ', $security)))];
        $rows[] = ['Preconditions', $preconditions ?? MarkdownWriter::EMPTY];

        return MarkdownWriter::table(['Element', 'Value'], $rows);
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function describe(string $kind, string $name, array $attributes): string
    {
        if (isset($attributes['path'])) {
            return MarkdownWriter::code(trim(($attributes['methods'] ?? '').' '.$attributes['path'])).' ('.MarkdownWriter::code($name).')';
        }

        return MarkdownWriter::code($name).' ('.$kind.')';
    }

    /**
     * DevTools' diagrams first — the state machine, a decision on the field `marking`, then where the
     * workflow leads — and Claude's below the line a parser splits on (ADR-0044). Nothing to draw, no line.
     */
    private function decisions(Workflow $workflow, string $claude): string
    {
        $blocks = [];

        if ($workflow->states instanceof StateMachine) {
            $blocks[] = \sprintf("**%s** — state machine %s\n\n%s", MarkdownWriter::code('marking'), MarkdownWriter::code($workflow->states->name), MermaidWriter::stateDiagram($workflow->states));
        }

        if ([] !== $workflow->navigation) {
            $blocks[] = "**Navigation**\n\n".$this->navigation($workflow);
        }

        if ([] === $blocks) {
            return $claude;
        }

        $drawn = implode("\n\n", $blocks)."\n\n".PageSection::CLAUDE_BELOW;

        return '' === trim($claude) || MarkdownWriter::EMPTY === trim($claude) ? $drawn : $drawn."\n\n".trim($claude);
    }

    private function navigation(Workflow $workflow): string
    {
        $labels = [$workflow->main->name];
        $edges = [];

        foreach ($workflow->navigation as $edge) {
            $labels[] = $edge->target;
            $edges[] = [0, \count($labels) - 1, $edge->label];
        }

        return MermaidWriter::flowchart('LR', $labels, $edges);
    }

    /**
     * What runs inside this workflow without being one: the listeners it goes through, the event each one
     * answers, its priority, and the fields it can write while it runs (ADR-0043) — a voter, the attributes
     * it votes on, and `access` (ADR-0044).
     *
     * A listener used to be a workflow with a page of its own; what a reader wants is this table.
     */
    private function crossCutting(Workflow $workflow): string
    {
        if ([] === $workflow->mechanisms) {
            return MarkdownWriter::EMPTY;
        }

        return MarkdownWriter::table(['Mechanism', 'Event', 'Priority', 'Decides'], array_map(
            static fn (Mechanism $mechanism): array => [
                MarkdownWriter::code($mechanism->name),
                MarkdownWriter::code($mechanism->event),
                null === $mechanism->priority ? MarkdownWriter::EMPTY : (string) $mechanism->priority,
                self::writes($mechanism),
            ],
            $workflow->mechanisms,
        ));
    }

    private static function writes(Mechanism $mechanism): string
    {
        $targets = array_values(array_unique(array_map(static fn (DecisionPoint $decision): string => $decision->target, $mechanism->decisions)));

        return [] === $targets ? MarkdownWriter::EMPTY : implode(', ', array_map(MarkdownWriter::code(...), $targets));
    }

    private function related(Workflow $workflow, RenderingContext $context): string
    {
        $related = [];

        foreach ($workflow->dependsOn as $dependency) {
            $related[$dependency->value] = [$dependency, 'depends on'];
        }

        foreach ($workflow->navigation as $edge) {
            $target = $context->workflowOf($edge->target);

            if ($target instanceof WorkflowId && !$target->equals($workflow->id)) {
                $related[$target->value] ??= [$target, 'navigation'];
            }
        }

        ksort($related, \SORT_STRING);

        return RelatedLinks::section(
            array_values(array_map(fn (array $entry): string => '- '.$this->linkTo($workflow, $entry[0], $context).' — '.$entry[1], $related)),
            $context->linked($workflow->type, $workflow->group?->directory, $workflow->id),
        );
    }

    /**
     * @param list<Revision> $history
     */
    private function history(array $history): string
    {
        return MarkdownWriter::table(['Date', 'Commit', 'Change'], array_map(
            static fn (Revision $revision): array => [$revision->at->format('Y-m-d'), null === $revision->commit ? MarkdownWriter::EMPTY : substr($revision->commit, 0, 7), $revision->reason],
            $history,
        ));
    }

    private function linkTo(Workflow $from, WorkflowId $target, RenderingContext $context): string
    {
        $page = $context->relativePage($from->type, $from->group?->directory, $target);

        return null === $page ? MarkdownWriter::code($target->value) : MarkdownWriter::link(MarkdownWriter::code($target->value), $page);
    }
}
