<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Impact;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * The xml format of `workflows:impact` (`impact.xsd`), validated before it leaves DevTools like every XML
 * it writes.
 */
final readonly class XmlImpactWriter
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/impact/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function serialize(Impact $impact): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'impact', ['schema-version' => (string) self::SCHEMA_VERSION]);

        if (null !== $impact->warning()) {
            $this->dom->element($root, 'warning', text: $impact->warning());
        }

        foreach ($impact->workflows as $workflow) {
            $element = $this->dom->element($root, 'workflow', [
                'id' => $workflow->id->value,
                'type' => $workflow->type->name,
                'title' => $workflow->title,
                'relation' => $workflow->relation->value,
                'page' => $workflow->page,
                'status' => $workflow->status->value,
            ]);

            foreach ($workflow->files as $file) {
                $this->dom->element($element, 'file', ['path' => $file]);
            }
        }

        foreach ($impact->uncovered as $file) {
            $this->dom->element($root, 'uncovered', ['path' => $file]);
        }

        $this->loader->validate($xml, Resources::path('schemas/impact.xsd'), 'impact');

        return $this->dom->toXml($xml);
    }
}
