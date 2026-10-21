<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

use Jul6Art\DevTools\Glossary\TermStatus;
use Jul6Art\DevTools\Glossary\XmlGlossaryStore;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Rendering\PageSection;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;

/**
 * The search index of a documented project (ADR-0039), from what is on disk: the index, the pages, the
 * tracking files and the glossary — never the code.
 *
 * A word weighs by where it is: the title and the identifier three times, the summary and the words the
 * glossary gives the workflow (ADR-0037) twice, the rest of the page and the names of its files once. The
 * history and the related workflows are left out — the one says when, the other names other pages — but
 * the related workflows are kept apart, as what a selection widens to.
 */
final class SearchIndexBuilder
{
    private const float TITLE = 3.0;

    private const float SUMMARY = 2.0;

    private const float BODY = 1.0;

    public function build(DevToolsDirectory $directory, WorkflowTypeRegistry $types): SearchIndex
    {
        $index = new XmlIndexStore($types)->read($directory->indexFile());
        $glossary = new XmlGlossaryStore()->read($directory->path('glossary.xml'))->withStatus(TermStatus::Defined);
        $tracking = new XmlTrackingStore($types);
        $documents = [];

        foreach ($index->entries as $entry) {
            $id = $entry->id->value;
            $file = $directory->trackingFile($entry->type, $entry->id);
            $document = is_file($file) ? $tracking->read($file) : null;
            $pageFile = $directory->pageFile($entry->type, $entry->id, $entry->group);
            $page = new PageParser()->parse(is_file($pageFile) ? (string) file_get_contents($pageFile) : '');
            $terms = [];
            $add = static function (string $text, float $weight) use (&$terms): void {
                foreach (Tokenizer::tokens($text) as $token) {
                    $terms[$token] = ($terms[$token] ?? 0.0) + $weight;
                }
            };

            $add($entry->title.' '.str_replace('.', ' ', $id), self::TITLE);
            $add(self::claude($page->section(PageSection::Summary)), self::SUMMARY);

            foreach (PageSection::cases() as $section) {
                if (!\in_array($section, [PageSection::Summary, PageSection::Related, PageSection::History], true)) {
                    $add(self::claude($page->section($section)), self::BODY);
                }
            }

            $add(implode(' ', array_map(static fn (TrackedFile $tracked): string => pathinfo($tracked->file->path, \PATHINFO_FILENAME), $document->files ?? [])), self::BODY);

            foreach ($glossary as $term) {
                if (\in_array($id, $term->workflows, true)) {
                    $add(implode(' ', [$term->name, $term->label ?? '', ...$term->synonyms]), self::SUMMARY);
                }
            }

            ksort($terms, \SORT_STRING);
            preg_match_all('/^- \[?`([a-z0-9.-]+)`.* — navigation$/m', $page->section(PageSection::Related) ?? '', $navigation);
            $related = array_values(array_unique([...array_map(static fn (WorkflowId $dependency): string => $dependency->value, $document->dependsOn ?? []), ...$navigation[1]]));
            sort($related, \SORT_STRING);

            $documents[$id] = [
                'title' => $entry->title,
                'type' => $entry->type->name,
                'page' => $directory->pageRelativePath($entry->type, $entry->id, $entry->group),
                'status' => $entry->status->value,
                'updated' => $entry->updated->format('Y-m-d'),
                'related' => $related,
                'length' => array_sum($terms),
                'terms' => $terms,
            ];
        }

        ksort($documents, \SORT_STRING);

        return new SearchIndex($documents, self::fingerprint($directory, $index));
    }

    /**
     * What the index is built from, summed up: the index itself, the glossary, and the size and date of
     * every page — a page Claude rewrote since makes the index stale.
     */
    public static function fingerprint(DevToolsDirectory $directory, Index $index): string
    {
        $parts = [];

        foreach ([$directory->indexFile(), $directory->path('glossary.xml'), ...array_map(static fn (IndexEntry $entry): string => $directory->pageFile($entry->type, $entry->id, $entry->group), $index->entries)] as $file) {
            $parts[] = is_file($file) ? $file.':'.filesize($file).':'.filemtime($file) : $file;
        }

        return hash('xxh128', implode("\n", $parts));
    }

    /**
     * A section's text without its Markdown punctuation, `—` when nothing is written.
     */
    private static function claude(?string $content): string
    {
        return null === $content || '—' === trim($content) ? '' : (string) preg_replace('/\]\([^)]*\)/', ']', $content);
    }
}
