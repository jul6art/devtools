<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `.devtools/scenarios/index.xml` (`resources/schemas/scenario-index.xsd`). A project without
 * scenarios has no index, and reads as an empty one.
 */
final readonly class XmlScenarioIndexStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/scenario-index/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, ScenarioIndex $index): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'scenarios', ['schema-version' => (string) self::SCHEMA_VERSION]);

        foreach ($index->entries as $entry) {
            $this->dom->element($root, 'scenario', ['id' => $entry->id, 'origin' => $entry->origin->value, 'sha256' => $entry->sha256, 'revision' => self::date($entry->revision)]);
        }

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): ScenarioIndex
    {
        if (!is_file($path)) {
            return new ScenarioIndex();
        }

        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/scenario-index.xsd'), self::SCHEMA_VERSION));

        return new ScenarioIndex(array_map(
            static fn (\DOMElement $entry): ScenarioIndexEntry => new ScenarioIndexEntry(
                $entry->getAttribute('id'),
                ScenarioOrigin::from($entry->getAttribute('origin')),
                $entry->getAttribute('sha256'),
                self::parseDate($entry->getAttribute('revision'), $path),
            ),
            $this->dom->children($root, 'scenario'),
        ));
    }
}
