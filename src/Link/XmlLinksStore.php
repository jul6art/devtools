<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Link;

use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * `.devtools/graph/links.xml` (ADR-0038), validated by `links.xsd` both ways.
 */
final readonly class XmlLinksStore
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/links/1';

    public const string FILE = 'graph/links.xml';

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader(), private DomBuilder $dom = new DomBuilder(self::NAMESPACE))
    {
    }

    public function read(string $path): Links
    {
        if (!is_file($path)) {
            return new Links();
        }

        $root = DomBuilder::root($this->loader->loadValidated((string) file_get_contents($path), $path, Resources::path('schemas/links.xsd'), 1));
        $optional = static fn (\DOMElement $element, string $name): ?string => $element->hasAttribute($name) ? $element->getAttribute($name) : null;

        return new Links(array_map(fn (\DOMElement $project): ProjectLinks => new ProjectLinks(
            $project->getAttribute('name'),
            $project->getAttribute('path'),
            LinkRole::from($project->getAttribute('role')),
            array_map(static fn (\DOMElement $call): Call => new Call(
                $call->getAttribute('endpoint'),
                $call->getAttribute('client'),
                $optional($call, 'server'),
                $optional($call, 'client-page'),
                $optional($call, 'server-page'),
            ), $this->dom->children($project, 'call')),
        ), $this->dom->children($root, 'project')));
    }

    public function write(string $path, Links $links): bool
    {
        return $this->writer->write($path, $this->serialize($links));
    }

    public function serialize(Links $links): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'links', ['schema-version' => '1']);

        foreach ($links->projects as $project) {
            $element = $this->dom->element($root, 'project', ['name' => $project->name, 'path' => $project->path, 'role' => $project->role->value]);

            foreach ($project->calls as $call) {
                $this->dom->element($element, 'call', array_filter([
                    'endpoint' => $call->endpoint,
                    'client' => $call->client,
                    'server' => $call->server,
                    'client-page' => $call->clientPage,
                    'server-page' => $call->serverPage,
                ], \is_string(...)));
            }
        }

        $this->loader->validate($xml, Resources::path('schemas/links.xsd'), 'links');

        return $this->dom->toXml($xml);
    }
}
