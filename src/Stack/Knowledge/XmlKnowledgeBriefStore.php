<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

final readonly class XmlKnowledgeBriefStore
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/knowledge-brief/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, KnowledgeBrief $brief): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'knowledge-brief', ['schema-version' => (string) self::SCHEMA_VERSION, 'key' => $brief->key]);
        $this->dom->element($root, 'stack', array_filter(['language' => $brief->language, 'framework' => $brief->framework, 'version' => $brief->version], static fn (?string $value): bool => null !== $value));
        $this->dom->element($root, 'prompt', ['version' => 'knowledge/2', 'path' => $brief->promptPath]);
        $this->dom->element($root, 'canvas', ['path' => $brief->canvasPath]);
        $this->dom->element($root, 'draft', ['path' => $brief->draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): KnowledgeBrief
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content) {
            throw InvalidXml::refused($path, 'the brief does not exist.');
        }

        $root = DomBuilder::root($this->loader->loadValidated($content, $path, Resources::path('schemas/knowledge-brief.xsd'), self::SCHEMA_VERSION));
        $stack = $this->dom->single($root, 'stack');

        return new KnowledgeBrief(
            $root->getAttribute('key'),
            $stack->getAttribute('language'),
            $stack->hasAttribute('framework') ? $stack->getAttribute('framework') : null,
            $stack->hasAttribute('version') ? $stack->getAttribute('version') : null,
            $this->dom->single($root, 'canvas')->getAttribute('path'),
            $this->dom->single($root, 'prompt')->getAttribute('path'),
            $this->dom->single($root, 'draft')->getAttribute('path'),
        );
    }
}
