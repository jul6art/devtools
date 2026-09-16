<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Claude;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

final readonly class XmlDiscoveryBriefStore
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/discovery-brief/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, DiscoveryBrief $brief): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'discovery-brief', ['schema-version' => (string) self::SCHEMA_VERSION, 'stack' => $brief->slug, 'root' => $brief->root]);
        $this->dom->element($root, 'prompt', ['version' => 'discovery/1', 'path' => $brief->promptPath]);
        $this->dom->element($root, 'schema', ['path' => $brief->schemaPath]);

        if (null !== $brief->knowledgePath) {
            $this->dom->element($root, 'knowledge', ['path' => $brief->knowledgePath]);
        }

        foreach (['source' => $brief->sourceDirectories, 'exclude' => $brief->excludes, 'type' => $brief->types, 'limited-to' => $brief->limitedTo] as $name => $values) {
            foreach ($values as $value) {
                $this->dom->element($root, $name, text: $value);
            }
        }

        $this->dom->element($root, 'draft', ['path' => $brief->draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): DiscoveryBrief
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content) {
            throw InvalidXml::refused($path, 'the brief does not exist.');
        }

        $root = DomBuilder::root($this->loader->loadValidated($content, $path, Resources::path('schemas/discovery-brief.xsd'), self::SCHEMA_VERSION));
        $texts = fn (string $name): array => array_map(static fn (\DOMElement $element): string => $element->textContent, $this->dom->children($root, $name));

        return new DiscoveryBrief(
            $root->getAttribute('stack'),
            $root->getAttribute('root'),
            $texts('source'),
            $texts('exclude'),
            $this->dom->optional($root, 'knowledge')?->getAttribute('path'),
            $texts('type'),
            $this->dom->single($root, 'schema')->getAttribute('path'),
            $this->dom->single($root, 'prompt')->getAttribute('path'),
            $texts('limited-to'),
            $this->dom->single($root, 'draft')->getAttribute('path'),
        );
    }
}
