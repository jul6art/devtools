<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes the brief of a group page (`resources/schemas/group-brief.xsd`).
 */
final readonly class XmlGroupBriefStore
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/group-brief/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private WorkflowTypeRegistry $types = new WorkflowTypeRegistry(),
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, GroupBrief $brief): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'group-brief', [
            'schema-version' => (string) self::SCHEMA_VERSION,
            'type' => $brief->type->name,
            'directory' => $brief->directory,
            'title' => $brief->title,
        ]);
        $this->dom->element($root, 'prompt', ['version' => $brief->promptVersion, 'path' => $brief->promptPath]);
        $this->dom->element($root, 'language', text: $brief->language);
        $this->dom->element($root, 'page', ['path' => $brief->pagePath]);

        foreach ($brief->routes as $route) {
            $this->dom->element($root, 'route', ['name' => $route['route'], 'title' => $route['title'], 'page' => $route['page']]);
        }

        $this->dom->element($root, 'draft', ['path' => $brief->draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): GroupBrief
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content) {
            throw InvalidXml::refused($path, 'the brief does not exist.');
        }

        $root = DomBuilder::root($this->loader->loadValidated($content, $path, Resources::path('schemas/group-brief.xsd'), self::SCHEMA_VERSION));
        $prompt = $this->dom->single($root, 'prompt');

        return new GroupBrief(
            $this->types->get($root->getAttribute('type')),
            $root->getAttribute('directory'),
            $root->getAttribute('title'),
            $prompt->getAttribute('version'),
            $prompt->getAttribute('path'),
            $this->dom->single($root, 'language')->textContent,
            $this->dom->single($root, 'page')->getAttribute('path'),
            array_map(
                static fn (\DOMElement $route): array => [
                    'route' => $route->getAttribute('name'),
                    'title' => $route->getAttribute('title'),
                    'page' => $route->getAttribute('page'),
                ],
                $this->dom->children($root, 'route'),
            ),
            $this->dom->single($root, 'draft')->getAttribute('path'),
        );
    }
}
