<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes an assertion brief (`resources/schemas/assertion-brief.xsd`).
 */
final readonly class XmlAssertionBriefStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/assertion-brief/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, AssertionBrief $brief): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'assertion-brief', ['schema-version' => (string) self::SCHEMA_VERSION]);
        $this->dom->element($root, 'prompt', ['version' => $brief->promptVersion, 'path' => $brief->promptPath]);
        $this->dom->element($root, 'vocabulary', ['path' => $brief->vocabularyPath]);
        $rule = $this->dom->element($root, 'rule', ['id' => $brief->rule['id'], 'path' => $brief->rule['path'], 'globs' => implode(' ', $brief->rule['globs']), 'workflow-types' => implode(' ', $brief->rule['workflowTypes'])]);
        $this->dom->element($rule, 'instruction', text: $brief->rule['instruction']);

        foreach ($brief->feedback as $feedback) {
            $this->dom->element($root, 'feedback', array_filter($feedback, \is_string(...)));
        }

        foreach ($brief->before as $commit) {
            $this->dom->element($root, 'before', ['commit' => $commit]);
        }

        foreach ($brief->files as $file) {
            $this->dom->element($root, 'file', ['path' => $file]);
        }

        foreach ($brief->captures as $capture) {
            $this->dom->element($root, 'capture', $capture);
        }

        foreach ($brief->tools as $tool) {
            $this->dom->element($root, 'tool', $tool);
        }

        $this->dom->element($root, 'draft', ['path' => $brief->draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    /**
     * @throws InvalidXml when the file is not an assertion brief
     */
    public function read(string $path): AssertionBrief
    {
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/assertion-brief.xsd'), self::SCHEMA_VERSION));
        $prompt = $this->dom->single($root, 'prompt');
        $rule = $this->dom->single($root, 'rule');
        $optional = static fn (\DOMElement $element, string $name): ?string => $element->hasAttribute($name) ? $element->getAttribute($name) : null;
        $words = static fn (string $value): array => array_values(array_filter(explode(' ', $value), static fn (string $word): bool => '' !== $word));
        $feedback = array_map(
            static fn (\DOMElement $feedback): array => [
                'id' => $feedback->getAttribute('id'),
                'path' => $feedback->getAttribute('path'),
                'summary' => $feedback->getAttribute('summary'),
                'commit' => $optional($feedback, 'commit'),
                'workflow' => $optional($feedback, 'workflow'),
                'selector' => $optional($feedback, 'selector'),
                'file' => $optional($feedback, 'file'),
            ],
            $this->dom->children($root, 'feedback'),
        );

        if ([] === $feedback) {
            throw InvalidXml::refused($path, 'an assertion brief names the feedback of its rule.');
        }

        return new AssertionBrief(
            $prompt->getAttribute('version'),
            $prompt->getAttribute('path'),
            $this->dom->single($root, 'vocabulary')->getAttribute('path'),
            ['id' => $rule->getAttribute('id'), 'path' => $rule->getAttribute('path'), 'instruction' => $this->dom->single($rule, 'instruction')->textContent, 'globs' => $words($rule->getAttribute('globs')), 'workflowTypes' => $words($rule->getAttribute('workflow-types'))],
            $feedback,
            array_map(static fn (\DOMElement $before): string => $before->getAttribute('commit'), $this->dom->children($root, 'before')),
            array_map(static fn (\DOMElement $file): string => $file->getAttribute('path'), $this->dom->children($root, 'file')),
            array_map(static fn (\DOMElement $capture): array => ['scenario' => $capture->getAttribute('scenario'), 'name' => $capture->getAttribute('name'), 'dom' => $capture->getAttribute('dom')], $this->dom->children($root, 'capture')),
            array_map(static fn (\DOMElement $tool): array => ['name' => $tool->getAttribute('name'), 'path' => $tool->getAttribute('path')], $this->dom->children($root, 'tool')),
            $this->dom->single($root, 'draft')->getAttribute('path'),
        );
    }
}
