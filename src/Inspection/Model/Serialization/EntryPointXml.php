<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model\Serialization;

use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Xml\DomBuilder;

/**
 * The XML form of an entry point, shared by the model and the tracking file so both describe it
 * identically.
 */
final class EntryPointXml
{
    /**
     * @param array<string, string> $extraAttributes
     */
    public static function write(DomBuilder $dom, \DOMElement $parent, string $name, EntryPoint $entryPoint, array $extraAttributes = []): \DOMElement
    {
        $element = $dom->element($parent, $name, ['kind' => $entryPoint->kind, 'name' => $entryPoint->name, ...$extraAttributes]);
        $dom->element($element, 'declared-in', ['path' => $entryPoint->declaredIn->path, 'role' => $entryPoint->declaredIn->role->value]);

        foreach ($entryPoint->attributes as $attribute => $value) {
            $dom->element($element, 'attribute', ['name' => $attribute, 'value' => $value]);
        }

        return $element;
    }

    public static function read(DomBuilder $dom, \DOMElement $element): EntryPoint
    {
        $attributes = [];

        foreach ($dom->children($element, 'attribute') as $attribute) {
            $attributes[$attribute->getAttribute('name')] = $attribute->getAttribute('value');
        }

        $declaredIn = $dom->single($element, 'declared-in');

        return new EntryPoint(
            $element->getAttribute('kind'),
            $element->getAttribute('name'),
            new FileRef($declaredIn->getAttribute('path'), FileRole::from($declaredIn->getAttribute('role'))),
            $attributes,
        );
    }
}
