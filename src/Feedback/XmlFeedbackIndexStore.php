<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `.devtools/feedback/index.xml` (`resources/schemas/feedback-index.xsd`). No index: no feedback.
 */
final readonly class XmlFeedbackIndexStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/feedback-index/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, FeedbackIndex $index): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'feedback-index', ['schema-version' => (string) self::SCHEMA_VERSION]);

        foreach ($index->entries as $entry) {
            $this->dom->element($root, 'feedback', array_filter([
                'id' => $entry->id,
                'status' => $entry->status->value,
                'category' => $entry->category,
                'reported-at' => self::date($entry->reportedAt),
                'by' => $entry->by->value,
                'occurrences' => (string) $entry->occurrences,
                'path' => $entry->path,
                'summary' => $entry->summary,
                'workflow' => $entry->target->workflow,
                'selector' => $entry->target->selector,
                'file' => $entry->target->file,
                'commit' => $entry->commit,
                'promoted-to' => $entry->promotedTo,
            ], \is_string(...)));
        }

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): FeedbackIndex
    {
        if (!is_file($path)) {
            return new FeedbackIndex();
        }

        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/feedback-index.xsd'), self::SCHEMA_VERSION));
        $optional = static fn (\DOMElement $entry, string $name): ?string => $entry->hasAttribute($name) ? $entry->getAttribute($name) : null;

        return new FeedbackIndex(array_map(
            static fn (\DOMElement $entry): FeedbackIndexEntry => new FeedbackIndexEntry(
                $entry->getAttribute('id'),
                FeedbackStatus::from($entry->getAttribute('status')),
                $entry->getAttribute('category'),
                self::parseDate($entry->getAttribute('reported-at'), $path),
                FeedbackSource::from($entry->getAttribute('by')),
                (int) $entry->getAttribute('occurrences'),
                $entry->getAttribute('path'),
                $entry->getAttribute('summary'),
                new FeedbackTarget($optional($entry, 'workflow'), $optional($entry, 'selector'), $optional($entry, 'file')),
                $optional($entry, 'commit'),
                $optional($entry, 'promoted-to'),
            ),
            $this->dom->children($root, 'feedback'),
        ));
    }
}
