<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Xml;

/**
 * Builds and walks the namespaced documents DevTools writes, the same way for every format.
 *
 * Writing goes through one place so that indentation and attribute order — hence the bytes — are
 * identical for identical content: every tracking file must be rewritable without producing a diff.
 */
final readonly class DomBuilder
{
    public function __construct(public string $namespace)
    {
    }

    public function document(): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        return $document;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function element(\DOMNode $parent, string $name, array $attributes = [], ?string $text = null): \DOMElement
    {
        $document = $parent instanceof \DOMDocument ? $parent : $parent->ownerDocument;

        if (!$document instanceof \DOMDocument) {
            throw new \LogicException('An element can only be appended to a node that belongs to a document.');
        }

        $element = $document->createElementNS($this->namespace, $name);

        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }

        if (null !== $text) {
            $element->textContent = $text;
        }

        $parent->appendChild($element);

        return $element;
    }

    public function toXml(\DOMDocument $document): string
    {
        return (string) $document->saveXML();
    }

    /**
     * @return list<\DOMElement>
     */
    public function children(?\DOMElement $parent, string $name): array
    {
        if (!$parent instanceof \DOMElement) {
            return [];
        }

        $children = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $this->namespace === $child->namespaceURI && $name === $child->localName) {
                $children[] = $child;
            }
        }

        return $children;
    }

    public function optional(\DOMElement $parent, string $name): ?\DOMElement
    {
        return $this->children($parent, $name)[0] ?? null;
    }

    public function single(\DOMElement $parent, string $name): \DOMElement
    {
        return $this->optional($parent, $name) ?? throw new \LogicException(\sprintf('The schema guarantees a <%s> element.', $name));
    }

    public static function root(\DOMDocument $document): \DOMElement
    {
        return $document->documentElement ?? throw new \LogicException('A validated document has a root element.');
    }
}
