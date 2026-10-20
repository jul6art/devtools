<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Visual;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;

use const Dom\HTML_NO_DEFAULT_NS;

/**
 * A DOM as a list of lines that differ only when its structure does (ADR-0022): one line per element with its
 * attributes sorted, one per text; scripts and styles dropped; what a framework generates anew on every
 * request — an identifier with a random part, a token — masked.
 */
final class DomNormalizer
{
    /**
     * Attributes whose value is never the same twice, whatever the page.
     */
    private const array VOLATILE = ['nonce', 'integrity', 'data-csrf', 'csrf-token'];

    /**
     * @return list<string>
     */
    public function normalize(string $html): array
    {
        $document = HTMLDocument::createFromString($html, \LIBXML_NOERROR | HTML_NO_DEFAULT_NS);
        $lines = [];
        $root = $document->documentElement;

        if ($root instanceof Element) {
            self::walk($root, 0, $lines);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function walk(Element $element, int $depth, array &$lines): void
    {
        $tag = strtolower($element->localName);

        if (\in_array($tag, ['script', 'style', 'noscript', 'template'], true)) {
            return;
        }

        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[strtolower($attribute->name)] = self::value($tag, strtolower($attribute->name), $attribute->value, $element);
        }

        ksort($attributes, \SORT_STRING);
        $lines[] = str_repeat('  ', $depth).'<'.$tag.implode('', array_map(static fn (string $name, string $value): string => \sprintf(' %s="%s"', $name, $value), array_keys($attributes), $attributes)).'>';

        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                self::walk($child, $depth + 1, $lines);
            } elseif ($child instanceof Text) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $child->data));

                if ('' !== $text) {
                    $lines[] = str_repeat('  ', $depth + 1).'"'.$text.'"';
                }
            }
        }
    }

    private static function value(string $tag, string $name, string $value, Element $element): string
    {
        $hidden = 'input' === $tag && 'hidden' === strtolower((string) $element->getAttribute('type'));

        if (\in_array($name, self::VOLATILE, true) || ($hidden && 'value' === $name) || str_contains($name, 'token')) {
            return '…';
        }

        // An identifier with a generated part — select2-order_product-container-a3f9, ember1234 — keeps its shape.
        if (\in_array($name, ['id', 'for', 'aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns', 'aria-activedescendant', 'data-select2-id'], true)) {
            return (string) preg_replace('/(?:[0-9a-f]{6,}|\d{2,})/i', '#', $value);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
