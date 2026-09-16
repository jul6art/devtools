<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Xml\DomBuilder;

/**
 * @internal
 */
final class VcsXml
{
    public static function write(DomBuilder $dom, \DOMElement $parent, string $name, VcsState $vcs): void
    {
        if (!$vcs->isVersioned()) {
            $dom->element($parent, $name, ['vcs' => 'none']);

            return;
        }

        $attributes = ['vcs' => 'git', 'commit' => (string) $vcs->commit];

        if (null !== $vcs->branch) {
            $attributes['branch'] = $vcs->branch;
        }

        $dom->element($parent, $name, [...$attributes, 'dirty' => $vcs->dirty ? 'true' : 'false']);
    }

    public static function read(\DOMElement $element): VcsState
    {
        if ('none' === $element->getAttribute('vcs')) {
            return VcsState::none();
        }

        return new VcsState(
            $element->getAttribute('commit'),
            $element->hasAttribute('branch') ? $element->getAttribute('branch') : null,
            'true' === $element->getAttribute('dirty'),
        );
    }
}
