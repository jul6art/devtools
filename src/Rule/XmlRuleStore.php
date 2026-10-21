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
 * Reads and writes one rule (`resources/schemas/rule.xsd`) — a draft of Claude's as well as a rule of the project.
 */
final readonly class XmlRuleStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/rule/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, Rule $rule): bool
    {
        return $this->writer->write($path, $this->serialize($rule));
    }

    public function serialize(Rule $rule): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'rule', ['schema-version' => (string) self::SCHEMA_VERSION, 'id' => $rule->id, 'status' => $rule->status->value, 'created' => $rule->created]);
        $this->dom->element($root, 'origin', ['feedback' => implode(' ', $rule->origin)]);
        $appliesTo = $this->dom->element($root, 'applies-to');

        foreach ($rule->appliesTo->globs as $glob) {
            $this->dom->element($appliesTo, 'files', ['glob' => $glob]);
        }

        foreach ($rule->appliesTo->workflowTypes as $type) {
            $this->dom->element($appliesTo, 'workflow-type', text: $type);
        }

        if (null !== $rule->appliesTo->category) {
            $this->dom->element($appliesTo, 'category', text: $rule->appliesTo->category);
        }

        $this->dom->element($root, 'instruction', text: $rule->instruction);

        if (null !== $rule->assertion) {
            $this->dom->element($root, 'assertion', ['ref' => $rule->assertion]);
        }

        $document = $this->dom->toXml($xml);
        $this->loader->validate($this->loader->load($document, $rule->id), Resources::path('schemas/rule.xsd'), $rule->id);

        return $document;
    }

    /**
     * @throws InvalidXml when the file is not a rule
     */
    public function read(string $path): Rule
    {
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/rule.xsd'), self::SCHEMA_VERSION));
        $appliesTo = $this->dom->single($root, 'applies-to');
        $origin = preg_split('/\s+/', trim($this->dom->single($root, 'origin')->getAttribute('feedback')), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if ([] === $origin) {
            throw InvalidXml::refused($path, 'a rule is promoted from at least one feedback.');
        }

        return new Rule(
            $root->getAttribute('id'),
            RuleStatus::from($root->getAttribute('status')),
            $root->getAttribute('created'),
            $origin,
            new AppliesTo(
                array_map(static fn (\DOMElement $files): string => $files->getAttribute('glob'), $this->dom->children($appliesTo, 'files')),
                array_map(static fn (\DOMElement $type): string => trim($type->textContent), $this->dom->children($appliesTo, 'workflow-type')),
                ($category = $this->dom->optional($appliesTo, 'category')) instanceof \DOMElement ? trim($category->textContent) : null,
            ),
            self::text($this->dom->single($root, 'instruction')->textContent),
            $this->dom->optional($root, 'assertion')?->getAttribute('ref'),
        );
    }

    /**
     * Written on several lines, an instruction is still one text: its whitespace is collapsed, as the schema
     * reads it.
     */
    public static function text(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
