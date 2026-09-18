<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;

/**
 * Renders the page of a group — `routes/<controller>/README.md` (ADR-0045).
 *
 * It lists the routes of a controller and links to them; each of them keeps its own page, with the ten
 * sections of ADR-0043. The only section Claude writes here is the summary, and it is bounded on purpose:
 * an index that explains as much as a page stops being an index.
 *
 * No date of the run appears anywhere: two renderings of an unchanged group produce the same bytes.
 */
final class GroupPageRenderer
{
    /**
     * @param non-empty-list<Workflow> $workflows the workflows of this group
     * @param ParsedPage|null          $written   the current page, when Claude wrote its summary
     */
    public function render(WorkflowGroup $group, array $workflows, ?ParsedPage $written = null): string
    {
        $factual = [
            GroupPageSection::Summary->value => MarkdownWriter::EMPTY,
            GroupPageSection::Routes->value => self::routes($group, $workflows),
            GroupPageSection::States->value => self::states($group, $workflows),
        ];

        $lines = [
            '# '.$group->title,
            \sprintf(
                '%s · type : %s · %d %s · %s',
                MarkdownWriter::code($group->directory),
                $workflows[0]->type->name,
                \count($workflows),
                1 === \count($workflows) ? 'route' : 'routes',
                MarkdownWriter::code($group->declaredIn->path),
            ),
        ];

        foreach (GroupPageSection::cases() as $section) {
            $content = $factual[$section->value];

            if ($section->writtenByClaude() && null !== ($sections = $written?->sections[$section->value] ?? null)) {
                $content = $sections;
            }

            $lines[] = '';
            $lines[] = '## '.$section->value;
            $lines[] = '';
            $lines[] = '' === trim($content) ? MarkdownWriter::EMPTY : $content;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The same page with another summary: what `workflows:apply` writes when Claude sends its draft.
     *
     * The facts are not re-rendered — `apply` has the page, not the model — so they are kept exactly as the
     * inspection wrote them. A fact corrected by hand there is still lost at the next inspection, which is
     * the rule of every page (ADR-0043).
     */
    public static function withSummary(ParsedPage $page, string $summary): string
    {
        $lines = ['# '.$page->title, $page->header];

        foreach (GroupPageSection::cases() as $section) {
            $content = GroupPageSection::Summary === $section ? $summary : ($page->sections[$section->value] ?? MarkdownWriter::EMPTY);

            $lines[] = '';
            $lines[] = '## '.$section->value;
            $lines[] = '';
            $lines[] = '' === trim($content) ? MarkdownWriter::EMPTY : trim($content);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The table a reader comes for: one line per route, and the link that opens its page.
     *
     * @param non-empty-list<Workflow> $workflows
     */
    private static function routes(WorkflowGroup $group, array $workflows): string
    {
        $rows = [];

        foreach ($workflows as $workflow) {
            $attributes = $workflow->main->attributes;
            $methods = $attributes['methods'] ?? null;
            $security = $attributes['security'] ?? null;

            $rows[] = [
                // The link is relative to this page, which lives in the same directory as the pages it lists.
                MarkdownWriter::link(MarkdownWriter::code($workflow->main->name), $group->leafOf($workflow->id).'.md'),
                null === ($attributes['path'] ?? null) ? MarkdownWriter::EMPTY : MarkdownWriter::code($attributes['path']),
                null === $methods ? MarkdownWriter::EMPTY : MarkdownWriter::code($methods),
                null === $security ? MarkdownWriter::EMPTY : implode(', ', array_map(MarkdownWriter::code(...), explode(', ', $security))),
            ];
        }

        return MarkdownWriter::table(['Route', 'Chemin', 'Méthodes', 'Sécurité'], $rows);
    }

    /**
     * The state machine the resource drives, when one of its routes carries one: it is the one thing the
     * grouped page of ADR-0006 said better than a page per route, and it stays here.
     *
     * @param non-empty-list<Workflow> $workflows
     */
    private static function states(WorkflowGroup $group, array $workflows): string
    {
        $machine = $group->states;

        foreach ($workflows as $workflow) {
            $machine ??= $workflow->states;
        }

        return $machine instanceof StateMachine ? MermaidWriter::stateDiagram($machine) : MarkdownWriter::EMPTY;
    }
}
