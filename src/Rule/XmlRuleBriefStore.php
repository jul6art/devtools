<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes a rule brief (`resources/schemas/rule-brief.xsd`).
 */
final readonly class XmlRuleBriefStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/rule-brief/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, RuleBrief $brief): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'rule-brief', ['schema-version' => (string) self::SCHEMA_VERSION, 'date' => $brief->date]);
        $this->dom->element($root, 'prompt', ['version' => $brief->promptVersion, 'path' => $brief->promptPath]);
        $this->dom->element($root, 'vocabulary', ['path' => $brief->vocabularyPath]);

        foreach ($brief->feedback as $feedback) {
            $this->dom->element($root, 'feedback', array_filter([...$feedback, 'occurrences' => (string) $feedback['occurrences']], \is_string(...)));
        }

        foreach ($brief->files as $file) {
            $this->dom->element($root, 'file', ['path' => $file]);
        }

        foreach ($brief->workflowTypes as $type) {
            $this->dom->element($root, 'workflow-type', ['name' => $type]);
        }

        foreach ($brief->categories as $category) {
            $this->dom->element($root, 'category', ['name' => $category]);
        }

        foreach ($brief->rules as $rule) {
            $this->dom->element($root, 'rule', $rule);
        }

        $this->dom->element($root, 'draft', ['path' => $brief->draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    /**
     * @throws InvalidXml when the file is not a rule brief
     */
    public function read(string $path): RuleBrief
    {
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/rule-brief.xsd'), self::SCHEMA_VERSION));
        $prompt = $this->dom->single($root, 'prompt');
        $optional = static fn (\DOMElement $element, string $name): ?string => $element->hasAttribute($name) ? $element->getAttribute($name) : null;
        $feedback = array_map(
            static fn (\DOMElement $feedback): array => [
                'id' => $feedback->getAttribute('id'),
                'path' => $feedback->getAttribute('path'),
                'category' => $feedback->getAttribute('category'),
                'occurrences' => (int) $feedback->getAttribute('occurrences'),
                'summary' => $feedback->getAttribute('summary'),
                'workflow' => $optional($feedback, 'workflow'),
                'selector' => $optional($feedback, 'selector'),
                'file' => $optional($feedback, 'file'),
            ],
            $this->dom->children($root, 'feedback'),
        );

        if ([] === $feedback) {
            throw InvalidXml::refused($path, 'a rule brief promotes at least one feedback.');
        }

        return new RuleBrief(
            $root->getAttribute('date'),
            $prompt->getAttribute('version'),
            $prompt->getAttribute('path'),
            $this->dom->single($root, 'vocabulary')->getAttribute('path'),
            $feedback,
            array_map(static fn (\DOMElement $file): string => $file->getAttribute('path'), $this->dom->children($root, 'file')),
            array_map(static fn (\DOMElement $type): string => $type->getAttribute('name'), $this->dom->children($root, 'workflow-type')),
            array_map(static fn (\DOMElement $category): string => $category->getAttribute('name'), $this->dom->children($root, 'category')),
            array_map(static fn (\DOMElement $rule): array => ['id' => $rule->getAttribute('id'), 'path' => $rule->getAttribute('path'), 'status' => $rule->getAttribute('status')], $this->dom->children($root, 'rule')),
            $this->dom->single($root, 'draft')->getAttribute('path'),
        );
    }
}
