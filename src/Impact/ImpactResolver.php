<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Impact;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;
use Jul6Art\DevTools\Tracking\XmlIndexStore;

/**
 * Which workflows a set of changed files reaches (ADR-0016), read from the map the last inspection wrote —
 * `graph/files-to-workflows.xml` and `index.xml`, never the code.
 *
 * It is called on every file Claude is about to modify (ADR-0019): two XML files, no parsing, no hash.
 */
final readonly class ImpactResolver
{
    public function __construct(private WorkflowTypeRegistry $types = new WorkflowTypeRegistry())
    {
    }

    /**
     * @param list<string> $files paths relative to the project, or absolute inside it
     * @param int          $depth 1 follows mechanisms and dependencies, 0 only the workflows' own files and tests
     *
     * @throws ImpactUnavailable  when the project was never inspected
     * @throws PathOutsideProject when a file lies outside the project
     */
    public function resolve(DevToolsDirectory $directory, array $files, int $depth = 1): Impact
    {
        foreach ([$directory->filesToWorkflowsFile(), $directory->indexFile()] as $required) {
            if (!is_file($required)) {
                throw ImpactUnavailable::noMap($required);
            }
        }

        $graph = new XmlFilesToWorkflowsStore()->read($directory->filesToWorkflowsFile());
        $index = new XmlIndexStore($this->types)->read($directory->indexFile());
        $entries = [];

        foreach ($index->entries as $entry) {
            $entries[$entry->id->value] = $entry;
        }

        $paths = array_values(array_unique(array_map(static fn (string $file): string => $directory->root->relative($directory->root->absolute($file)), $files)));
        sort($paths, \SORT_STRING);
        $reached = [];
        $uncovered = [];

        foreach ($paths as $path) {
            $links = $graph->linksOf($path);

            if ([] === $links) {
                $uncovered[] = $path;

                continue;
            }

            foreach ($links as $link) {
                $relation = ImpactRelation::of($link->relation);

                if ((0 === $depth && ImpactRelation::Dependency === $relation) || !isset($entries[$link->workflow->value])) {
                    continue;
                }

                $known = $reached[$link->workflow->value] ?? null;
                $reached[$link->workflow->value] = [
                    null === $known || $relation->isStrongerThan($known[0]) ? $relation : $known[0],
                    [...$known[1] ?? [], $path],
                ];
            }
        }

        ksort($reached, \SORT_STRING);

        return new Impact(
            array_values(array_map(
                static fn (string $id, array $reach): ImpactedWorkflow => self::impacted($entries[$id], $reach[0], $reach[1], $index->docs),
                array_keys($reached),
                $reached,
            )),
            $uncovered,
            self::stale($directory, $paths, $graph->links),
        );
    }

    /**
     * @param list<string> $files
     */
    private static function impacted(IndexEntry $entry, ImpactRelation $relation, array $files, string $docs): ImpactedWorkflow
    {
        $files = array_values(array_unique($files));
        sort($files, \SORT_STRING);

        return new ImpactedWorkflow($entry->id, $entry->type, $entry->title, $relation, $files, $docs.'/'.$entry->page(), $entry->status);
    }

    /**
     * The files asked about that changed after the last inspection — or vanished from a map that still
     * names them. The last inspection is its last report, which every run writes, or failing that the index.
     *
     * @param list<string>               $paths
     * @param array<string, list<mixed>> $mapped
     *
     * @return list<string>
     */
    private static function stale(DevToolsDirectory $directory, array $paths, array $mapped): array
    {
        $reports = glob($directory->path('reports/inspect-*.md')) ?: [];
        $scanned = max([(int) filemtime($directory->indexFile()), ...array_map(static fn (string $report): int => (int) filemtime($report), $reports)]);
        $stale = [];

        foreach ($paths as $path) {
            $absolute = $directory->root->absolute($path);
            $changed = is_file($absolute) ? filemtime($absolute) > $scanned : isset($mapped[$path]);

            if ($changed) {
                $stale[] = $path;
            }
        }

        return $stale;
    }
}
