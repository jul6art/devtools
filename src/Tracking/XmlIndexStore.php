<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `index.xml` (`resources/schemas/index.xsd`).
 */
final readonly class XmlIndexStore implements IndexReaderInterface, IndexWriterInterface
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/index/1';

    private const int SCHEMA_VERSION = 2;

    private DomBuilder $dom;

    public function __construct(
        private WorkflowTypeRegistry $types,
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    #[\Override]
    public function write(string $path, Index $document): bool
    {
        return $this->writer->write($path, $this->serialize($document));
    }

    #[\Override]
    public function read(string $path): Index
    {
        return $this->unserialize(self::contentOf($path), $path);
    }

    public function serialize(Index $index): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'index', ['schema-version' => (string) self::SCHEMA_VERSION, 'scanned-at' => self::date($index->scannedAt), 'docs' => $index->docs]);
        VcsXml::write($this->dom, $root, 'source', $index->vcs);

        foreach ($index->entries as $entry) {
            $this->dom->element($root, 'workflow', [
                'id' => $entry->id->value,
                'type' => $entry->type->name,
                'title' => $entry->title,
                'status' => $entry->status->value,
                'confidence' => $entry->confidence->value,
                'mode' => $entry->mode->value,
                'updated' => self::date($entry->updated),
                'page' => $entry->page(),
            ]);
        }

        return $this->dom->toXml($xml);
    }

    public function unserialize(string $xml, string $source): Index
    {
        $root = DomBuilder::root($this->loader->loadValidated($xml, $source, Resources::path('schemas/index.xsd'), self::SCHEMA_VERSION));

        return new Index(
            self::parseDate($root->getAttribute('scanned-at'), $source),
            VcsXml::read($this->dom->single($root, 'source')),
            array_map(
                fn (\DOMElement $entry): IndexEntry => new IndexEntry(
                    new WorkflowId($entry->getAttribute('id')),
                    $this->types->get($entry->getAttribute('type')),
                    $entry->getAttribute('title'),
                    TrackingStatus::from($entry->getAttribute('status')),
                    Confidence::from($entry->getAttribute('confidence')),
                    GenerationMode::from($entry->getAttribute('mode')),
                    self::parseDate($entry->getAttribute('updated'), $source),
                ),
                $this->dom->children($root, 'workflow'),
            ),
            // An index written before schema-version 2 does not say: the documentation moves to the default,
            // and the pages left in .devtools/workflows/ are the previous version's, to delete by hand.
            '' === $root->getAttribute('docs') ? DevToolsDirectory::DEFAULT_DOCS : $root->getAttribute('docs'),
        );
    }
}
