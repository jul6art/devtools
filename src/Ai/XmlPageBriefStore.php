<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

final readonly class XmlPageBriefStore
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/page-brief/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private WorkflowTypeRegistry $types = new WorkflowTypeRegistry(),
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, PageBrief $brief): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'page-brief', [
            'schema-version' => (string) self::SCHEMA_VERSION,
            'workflow' => $brief->workflow->value,
            'type' => $brief->type->name,
            'revision' => $brief->revision->format(\DATE_ATOM),
            'amend' => $brief->amend ? 'true' : 'false',
        ]);
        $this->dom->element($root, 'prompt', ['version' => $brief->promptVersion, 'path' => $brief->promptPath]);
        $this->dom->element($root, 'language', text: $brief->language);
        $this->dom->element($root, 'model', ['path' => $brief->modelPath]);
        $this->dom->element($root, 'page', ['path' => $brief->pagePath]);

        if (null !== $brief->knowledgePath) {
            $this->dom->element($root, 'knowledge', ['path' => $brief->knowledgePath]);
        }

        foreach ($brief->reasons as $reason) {
            $this->dom->element($root, 'reason', text: $reason);
        }

        foreach ($brief->changes as $change) {
            $this->dom->element($root, 'change', ['path' => $change['path'], 'kind' => $change['change']]);
        }

        foreach ($brief->facts as $fact) {
            $this->dom->element($root, 'fact', text: $fact);
        }

        foreach ($brief->sections as $section) {
            $this->dom->element($root, 'section', text: $section);
        }

        $this->dom->element($root, 'draft', ['path' => $brief->draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): PageBrief
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content) {
            throw InvalidXml::refused($path, 'the brief does not exist.');
        }

        $root = DomBuilder::root($this->loader->loadValidated($content, $path, Resources::path('schemas/page-brief.xsd'), self::SCHEMA_VERSION));
        $revision = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $root->getAttribute('revision')) ?: throw InvalidXml::refused($path, 'the revision is not a date.');
        $prompt = $this->dom->single($root, 'prompt');

        return new PageBrief(
            new WorkflowId($root->getAttribute('workflow')),
            $this->types->get($root->getAttribute('type')),
            $revision,
            'true' === $root->getAttribute('amend'),
            $prompt->getAttribute('version'),
            $prompt->getAttribute('path'),
            $this->dom->single($root, 'language')->textContent,
            $this->dom->single($root, 'model')->getAttribute('path'),
            $this->dom->single($root, 'page')->getAttribute('path'),
            $this->dom->optional($root, 'knowledge')?->getAttribute('path'),
            array_map(static fn (\DOMElement $reason): string => $reason->textContent, $this->dom->children($root, 'reason')),
            array_map(static fn (\DOMElement $section): string => $section->textContent, $this->dom->children($root, 'section')),
            array_map(static fn (\DOMElement $change): array => ['path' => $change->getAttribute('path'), 'change' => $change->getAttribute('kind')], $this->dom->children($root, 'change')),
            $this->dom->single($root, 'draft')->getAttribute('path'),
            array_map(static fn (\DOMElement $fact): string => $fact->textContent, $this->dom->children($root, 'fact')),
        );
    }
}
