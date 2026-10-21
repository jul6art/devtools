<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `rules/index.xml` (`resources/schemas/rule-index.xsd`).
 */
final readonly class XmlRuleIndexStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/rule-index/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, RuleIndex $index): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'rules', ['schema-version' => (string) self::SCHEMA_VERSION]);

        foreach ($index->entries as $entry) {
            $rule = $this->dom->element($root, 'rule', array_filter([
                'id' => $entry->id,
                'status' => $entry->status->value,
                'created' => $entry->created,
                'origin' => implode(' ', $entry->origin),
                'category' => $entry->appliesTo->category,
                'assertion' => $entry->assertion,
            ], \is_string(...)));

            foreach ($entry->appliesTo->globs as $glob) {
                $this->dom->element($rule, 'files', ['glob' => $glob]);
            }

            foreach ($entry->appliesTo->workflowTypes as $type) {
                $this->dom->element($rule, 'workflow-type', text: $type);
            }

            $this->dom->element($rule, 'instruction', text: $entry->instruction);
        }

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): RuleIndex
    {
        if (!is_file($path)) {
            return new RuleIndex();
        }

        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/rule-index.xsd'), self::SCHEMA_VERSION));

        return new RuleIndex(array_map(
            fn (\DOMElement $rule): RuleIndexEntry => new RuleIndexEntry(
                $rule->getAttribute('id'),
                RuleStatus::from($rule->getAttribute('status')),
                $rule->getAttribute('created'),
                array_values(array_filter(explode(' ', $rule->getAttribute('origin')))) ?: [$rule->getAttribute('origin')],
                new AppliesTo(
                    array_map(static fn (\DOMElement $files): string => $files->getAttribute('glob'), $this->dom->children($rule, 'files')),
                    array_map(static fn (\DOMElement $type): string => trim($type->textContent), $this->dom->children($rule, 'workflow-type')),
                    $rule->hasAttribute('category') ? $rule->getAttribute('category') : null,
                ),
                $this->dom->single($rule, 'instruction')->textContent,
                $rule->hasAttribute('assertion') ? $rule->getAttribute('assertion') : null,
            ),
            $this->dom->children($root, 'rule'),
        ));
    }
}
