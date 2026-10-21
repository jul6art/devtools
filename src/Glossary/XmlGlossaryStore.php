<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * `.devtools/glossary.xml` (ADR-0037), validated by `glossary.xsd` both ways.
 */
final readonly class XmlGlossaryStore
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/glossary/1';

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader(), private DomBuilder $dom = new DomBuilder(self::NAMESPACE))
    {
    }

    public function read(string $path): Glossary
    {
        if (!is_file($path)) {
            return new Glossary();
        }

        $root = DomBuilder::root($this->loader->loadValidated((string) file_get_contents($path), $path, Resources::path('schemas/glossary.xsd'), 1));
        $texts = fn (\DOMElement $term, string $name): array => array_map(static fn (\DOMElement $element): string => $element->textContent, $this->dom->children($term, $name));

        return new Glossary(array_map(fn (\DOMElement $term): Term => new Term(
            $term->getAttribute('name'),
            TermStatus::from($term->getAttribute('status')),
            (int) $term->getAttribute('occurrences'),
            $this->dom->optional($term, 'definition')?->textContent,
            $this->dom->optional($term, 'label')?->textContent,
            $texts($term, 'synonym'),
            $texts($term, 'workflow'),
            $texts($term, 'file'),
        ), $this->dom->children($root, 'term')));
    }

    public function write(string $path, Glossary $glossary): bool
    {
        return $this->writer->write($path, $this->serialize($glossary));
    }

    public function serialize(Glossary $glossary): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'glossary', ['schema-version' => '1']);

        foreach ($glossary->terms as $term) {
            $element = $this->dom->element($root, 'term', ['name' => $term->name, 'status' => $term->status->value, 'occurrences' => (string) $term->occurrences]);

            if (null !== $term->definition) {
                $this->dom->element($element, 'definition', text: $term->definition);
            }

            if (null !== $term->label) {
                $this->dom->element($element, 'label', text: $term->label);
            }

            foreach (['synonym' => $term->synonyms, 'workflow' => $term->workflows, 'file' => $term->files] as $name => $values) {
                foreach ($values as $value) {
                    $this->dom->element($element, $name, text: $value);
                }
            }
        }

        return $this->dom->toXml($xml);
    }
}
