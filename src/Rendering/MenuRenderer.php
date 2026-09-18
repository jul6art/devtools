<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Stack\StackDocument;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\TrackingStatus;

/**
 * Renders `workflows.md`, the home page and menu of `.devtools/` (specs § 4.7).
 *
 * Counters are shown even at zero, and "À vérifier" and "Non couvert" are always present: they are the
 * guard rails that make visible what the analysis did not understand.
 */
final class MenuRenderer
{
    /**
     * Above this many entries of one type, the menu gains a third level.
     */
    private const int FLAT_ENTRIES = 12;

    /**
     * @param list<FileRef>      $uncovered
     * @param list<WorkflowId>   $pendingRedaction workflows with a draft waiting for Claude (ADR-0011)
     * @param list<WorkflowType> $customTypes
     */
    public function render(StackDocument $stacks, Index $index, array $uncovered, array $pendingRedaction = [], array $customTypes = [], string $docs = DevToolsDirectory::DEFAULT_DOCS): string
    {
        // The menu lives with the pages; everything it links to outside them lives in .devtools/.
        $machinery = static fn (string $path): string => DevToolsDirectory::machineryLink($docs, $path);
        $lines = [
            '# Workflows — '.$stacks->projectName,
            '',
            \sprintf(
                'Stack : %s · %d workflows · dernier scan : %s%s',
                implode(' + ', array_map(self::stack(...), $stacks->stacks)),
                \count($index->entries),
                $index->scannedAt->format('Y-m-d'),
                null === $index->vcs->commit ? '' : ' (commit '.substr($index->vcs->commit, 0, 7).')',
            ),
            '',
            implode(' · ', [
                MarkdownWriter::link('Vue d\'ensemble', $machinery('graph/workflows.mermaid')),
                MarkdownWriter::link('Stack', $machinery('stack.xml')),
                ...array_map(static fn (StackProfile $stack): string => MarkdownWriter::link('Connaissances '.$stack->knowledgeKey, $machinery('knowledge/'.$stack->knowledgeKey.'.md')), array_values(array_filter($stacks->stacks, static fn (StackProfile $stack): bool => null !== $stack->knowledgeKey))),
            ]),
        ];

        foreach ([...array_map(WorkflowType::native(...), array_keys(WorkflowType::NATIVE)), ...$customTypes] as $type) {
            $entries = array_values(array_filter($index->entries, static fn (IndexEntry $entry): bool => $entry->type->name === $type->name));
            $lines = [...$lines, '', \sprintf('## %s (%d)', Labels::type($type), \count($entries)), ''];
            $lines = [...$lines, ...([] === $entries ? [MarkdownWriter::EMPTY] : self::listing($entries))];
        }

        $pending = array_map(static fn (WorkflowId $id): string => $id->value, $pendingRedaction);
        $attention = [];

        foreach ($index->entries as $entry) {
            $reasons = [];

            if (TrackingStatus::Stale === $entry->status) {
                $reasons[] = 'périmé';
            }

            if (TrackingStatus::Orphaned === $entry->status) {
                $reasons[] = 'orphelin : le point d\'entrée a disparu';
            }

            if (\in_array($entry->id->value, $pending, true)) {
                $reasons[] = 'rédaction en attente';
            }

            if ([] !== $reasons) {
                $attention[] = '- ⚠ '.MarkdownWriter::link(MarkdownWriter::code($entry->id->value), $entry->page()).' — '.implode(', ', $reasons);
            }
        }

        $lines = [...$lines, '', '## À vérifier', '', ...([] === $attention ? [MarkdownWriter::EMPTY] : $attention)];

        $uncoveredPaths = array_map(static fn (FileRef $file): string => $file->path, $uncovered);
        sort($uncoveredPaths, \SORT_STRING);
        $lines = [...$lines, '', '## Non couvert', '', ...([] === $uncoveredPaths ? [MarkdownWriter::EMPTY] : array_map(static fn (string $path): string => '- '.MarkdownWriter::code($path).' — aucun workflow ne référence ce fichier', $uncoveredPaths))];

        return implode("\n", $lines)."\n";
    }

    private static function stack(StackProfile $stack): string
    {
        $name = null === $stack->framework ? ucfirst($stack->language) : ucfirst($stack->framework).(null === $stack->version ? '' : ' '.$stack->version);

        return '.' === $stack->root ? $name : $name.' ('.$stack->root.')';
    }

    /**
     * The entries of a type, flat — or, past a dozen, a third level: one sub-heading per family, read from
     * the first segment of the identifier (`route.admin.…` → `admin`). A back-office has fifty resources,
     * and fifty lines under one heading is a list nobody scrolls.
     *
     * @param non-empty-list<IndexEntry> $entries
     *
     * @return list<string>
     */
    private static function listing(array $entries): array
    {
        $families = [];

        foreach ($entries as $entry) {
            $families[self::family($entry)][] = $entry;
        }

        if (\count($entries) <= self::FLAT_ENTRIES || 2 > \count($families)) {
            return array_map(self::entry(...), $entries);
        }

        ksort($families, \SORT_STRING);
        $lines = [];

        foreach ($families as $family => $group) {
            $lines = [...$lines, \sprintf('### %s (%d)', $family, \count($group)), '', ...array_map(self::entry(...), $group), ''];
        }

        return \array_slice($lines, 0, -1);
    }

    /**
     * `route.admin.work.order` → `admin`; an identifier with a single segment is its own family.
     */
    private static function family(IndexEntry $entry): string
    {
        $segments = explode('.', $entry->id->value);

        return $segments[1] ?? $entry->id->value;
    }

    private static function entry(IndexEntry $entry): string
    {
        return \sprintf(
            '- %s — %s · MAJ %s%s',
            MarkdownWriter::link($entry->title, $entry->page()),
            MarkdownWriter::code($entry->id->value),
            $entry->updated->format('Y-m-d'),
            Confidence::High === $entry->confidence ? '' : ' · confiance '.(Confidence::Medium === $entry->confidence ? 'moyenne' : 'faible'),
        );
    }
}
