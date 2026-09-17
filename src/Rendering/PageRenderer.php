<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Tracking\Revision;

/**
 * Renders the page of a workflow in the fixed template of specs § 4.5 (ADR-0008).
 *
 * The sections DevTools owns are always rendered from the model. Those Claude owns hold "—" — or, for the
 * journey and the cross-cutting mechanisms, what DevTools can state on its own — unless a page Claude
 * wrote is given, in which case its content is kept: a factual rewrite never erases a written page.
 * No date of the run appears anywhere: the page of an unchanged workflow renders to the same bytes.
 */
final class PageRenderer
{
    /**
     * Above this many files reached, the journey shows a count per role instead of one box per file.
     */
    private const int JOURNEY_FILES = 12;

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
            PageSection::Journey->value => $this->journey($workflow),
            PageSection::Navigation->value => $this->navigation($workflow),
            PageSection::Components->value => $this->components($workflow),
            PageSection::Data->value => MarkdownWriter::EMPTY,
            PageSection::CrossCutting->value => $this->crossCutting($workflow, $context),
            PageSection::Attention->value => MarkdownWriter::EMPTY,
            PageSection::Tests->value => $this->tests($workflow),
            PageSection::Related->value => $this->related($workflow, $context),
            PageSection::History->value => $this->history($history),
        ];

        $lines = [
            '# '.$workflow->title,
            \sprintf('%s · type : %s · dernière mise à jour : %s · commit : %s', MarkdownWriter::code($workflow->id->value), $workflow->type->name, $last->at->format('Y-m-d'), null === $last->commit ? MarkdownWriter::EMPTY : substr($last->commit, 0, 7)),
        ];

        foreach (PageSection::cases() as $section) {
            $content = $factual[$section->value];

            if ($section->writtenByClaude() && null !== $written?->section($section)) {
                $content = $written->section($section);
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
        $rows = [['Point d\'entrée', self::describe($workflow->main->kind, $workflow->main->name, $workflow->main->attributes)]];

        foreach ($workflow->satellites as $satellite) {
            $rows[] = ['Satellite', self::describe($satellite->kind, $satellite->name, $satellite->attributes)];
        }

        foreach ($workflow->main->attributes as $name => $value) {
            if (!\in_array($name, ['path', 'methods', 'security'], true)) {
                $rows[] = [ucfirst($name), MarkdownWriter::code($value)];
            }
        }

        $security = $workflow->main->attributes['security'] ?? null;
        $rows[] = ['Sécurité', null === $security ? MarkdownWriter::EMPTY : implode(', ', array_map(MarkdownWriter::code(...), explode(', ', $security)))];
        $rows[] = ['Préconditions', $preconditions ?? MarkdownWriter::EMPTY];

        return MarkdownWriter::table(['Élément', 'Valeur'], $rows);
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
     * The factual journey: the entry point, its file, and the files it reaches. The order of calls is not
     * known statically — that is what Claude's sequence diagram adds.
     *
     * Past a dozen files the diagram stops being read: a real controller reaches thirty entities and helpers,
     * and a flat fan of thirty boxes says less than a count per role. The files themselves are all listed,
     * one by one, under "Composants impliqués".
     */
    private function journey(Workflow $workflow): string
    {
        // Configuration declares the workflow, it is not traversed by it: listed as a component, not drawn.
        $files = array_values(array_filter($workflow->files, static fn (FileRef $file): bool => FileRole::Config !== $file->role));
        $entry = array_values(array_filter($files, static fn (FileRef $file): bool => $file->samePathAs($workflow->main->declaredIn)));
        $reached = array_values(array_filter($files, static fn (FileRef $file): bool => !$file->samePathAs($workflow->main->declaredIn)));

        $labels = [$workflow->title, ...array_map(self::fileLabel(...), $entry)];
        $edges = [] === $entry ? [] : [[0, 1, null]];
        $from = [] === $entry ? 0 : 1;

        foreach (\count($reached) > self::JOURNEY_FILES ? self::byRole($reached) : array_map(self::fileLabel(...), $reached) as $label) {
            $labels[] = $label;
            $edges[] = [$from, \count($labels) - 1, null];
        }

        return MermaidWriter::flowchart('TD', $labels, $edges);
    }

    private static function fileLabel(FileRef $file): string
    {
        return Labels::role($file->role).' · '.$file->path;
    }

    /**
     * `Entité ×9`, one node per role, roles in a stable order.
     *
     * @param list<FileRef> $files
     *
     * @return list<string>
     */
    private static function byRole(array $files): array
    {
        $counts = [];

        foreach ($files as $file) {
            $role = Labels::role($file->role);
            $counts[$role] = ($counts[$role] ?? 0) + 1;
        }

        ksort($counts, \SORT_STRING);

        return array_map(static fn (string $role, int $count): string => \sprintf('%s ×%d', $role, $count), array_keys($counts), array_values($counts));
    }

    private function navigation(Workflow $workflow): string
    {
        $blocks = [];

        if ([] !== $workflow->navigation) {
            $labels = [$workflow->main->name];
            $edges = [];

            foreach ($workflow->navigation as $edge) {
                $labels[] = $edge->target;
                $edges[] = [0, \count($labels) - 1, $edge->label];
            }

            $blocks[] = MermaidWriter::flowchart('LR', $labels, $edges);
        }

        if ($workflow->states instanceof StateMachine) {
            $blocks[] = MermaidWriter::stateDiagram($workflow->states);
        }

        return [] === $blocks ? MarkdownWriter::EMPTY : implode("\n\n", $blocks);
    }

    private function components(Workflow $workflow): string
    {
        $table = MarkdownWriter::table(['Rôle', 'Fichier', 'Notes'], array_map(
            static fn (FileRef $file): array => [Labels::role($file->role), MarkdownWriter::code($file->path), $file->samePathAs($workflow->main->declaredIn) ? 'point d\'entrée' : ''],
            $workflow->files,
        ));

        if ([] === $workflow->packages) {
            return $table;
        }

        return $table."\n\nPaquets : ".implode(', ', array_map(static fn (PackageRef $package): string => MarkdownWriter::code($package->name).' '.$package->version, $workflow->packages));
    }

    private function crossCutting(Workflow $workflow, RenderingContext $context): string
    {
        $events = array_values(array_filter($workflow->dependsOn, static fn (WorkflowId $id): bool => str_starts_with($id->value, 'event.')));

        return [] === $events ? MarkdownWriter::EMPTY : implode("\n", array_map(fn (WorkflowId $id): string => '- '.$this->linkTo($workflow, $id, $context), $events));
    }

    private function tests(Workflow $workflow): string
    {
        return MarkdownWriter::table(['Test', 'Fichier', 'Couvre'], array_map(
            static fn (FileRef $test): array => [basename($test->path, '.php'), MarkdownWriter::code($test->path), MarkdownWriter::EMPTY],
            $workflow->tests,
        ));
    }

    private function related(Workflow $workflow, RenderingContext $context): string
    {
        $related = [];

        foreach ($workflow->dependsOn as $dependency) {
            $related[$dependency->value] = [$dependency, 'dépend de'];
        }

        foreach ($workflow->navigation as $edge) {
            $target = $context->workflowOf($edge->target);

            if ($target instanceof WorkflowId && !$target->equals($workflow->id)) {
                $related[$target->value] ??= [$target, 'navigation'];
            }
        }

        ksort($related, \SORT_STRING);

        return [] === $related ? MarkdownWriter::EMPTY : implode("\n", array_map(fn (array $entry): string => '- '.$this->linkTo($workflow, $entry[0], $context).' — '.$entry[1], $related));
    }

    /**
     * @param list<Revision> $history
     */
    private function history(array $history): string
    {
        return MarkdownWriter::table(['Date', 'Commit', 'Changement'], array_map(
            static fn (Revision $revision): array => [$revision->at->format('Y-m-d'), null === $revision->commit ? MarkdownWriter::EMPTY : substr($revision->commit, 0, 7), $revision->reason],
            $history,
        ));
    }

    private function linkTo(Workflow $from, WorkflowId $target, RenderingContext $context): string
    {
        $page = $context->relativePage($from->type, $target);

        return null === $page ? MarkdownWriter::code($target->value) : MarkdownWriter::link(MarkdownWriter::code($target->value), $page);
    }
}
