<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

final readonly class XmlConfigReader implements ConfigReaderInterface
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/config/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    #[\Override]
    public function read(string $path): Config
    {
        if (!is_file($path)) {
            return new Config();
        }

        $content = file_get_contents($path);

        if (false === $content) {
            throw InvalidXml::refused($path, 'the file cannot be read.');
        }

        $root = DomBuilder::root($this->loader->loadValidated($content, $path, Resources::path('schemas/config.xsd'), self::SCHEMA_VERSION));
        $defaults = new Config();

        $aliases = [];

        foreach ($this->dom->children($this->dom->optional($root, 'aliases'), 'alias') as $alias) {
            $aliases[$alias->getAttribute('entrypoint')] = $alias->getAttribute('id');
        }

        $groups = [];

        foreach ($this->dom->children($this->dom->optional($root, 'groups'), 'group') as $group) {
            $groups[$group->getAttribute('main')] = array_map(static fn (\DOMElement $satellite): string => trim($satellite->textContent), $this->dom->children($group, 'satellite'));
        }

        $graph = $this->dom->optional($root, 'graph');
        $identifiers = $this->dom->optional($root, 'identifiers');
        $symfony = $this->dom->optional($root, 'symfony');
        $language = $this->dom->optional($root, 'language');
        $php = $this->dom->optional($root, 'php');
        $routes = $this->dom->optional($root, 'routes');
        $knowledge = $this->dom->optional($root, 'knowledge');

        return new Config(
            excludes: array_map(static fn (\DOMElement $exclude): string => trim($exclude->textContent), $this->dom->children($this->dom->optional($root, 'paths'), 'exclude')),
            graphDepth: $graph instanceof \DOMElement ? (int) $graph->getAttribute('depth') : $defaults->graphDepth,
            routePrefix: $identifiers instanceof \DOMElement ? $identifiers->getAttribute('route-prefix') : $defaults->routePrefix,
            aliases: $aliases,
            groups: $groups,
            customTypes: array_map(
                static fn (\DOMElement $type): WorkflowType => WorkflowType::custom($type->getAttribute('name'), $type->getAttribute('prefix')),
                $this->dom->children($this->dom->optional($root, 'types'), 'type'),
            ),
            symfonyConsole: $symfony?->hasAttribute('console') ? $symfony->getAttribute('console') : $defaults->symfonyConsole,
            symfonyEnv: $symfony?->hasAttribute('env') ? $symfony->getAttribute('env') : $defaults->symfonyEnv,
            symfonyTimeout: $symfony?->hasAttribute('timeout') ? (float) $symfony->getAttribute('timeout') : $defaults->symfonyTimeout,
            pagesLanguage: $language instanceof \DOMElement ? $language->getAttribute('pages') : $defaults->pagesLanguage,
            routeGrouping: $routes?->hasAttribute('group') ? $routes->getAttribute('group') : $defaults->routeGrouping,
            phpWebRoot: $php?->hasAttribute('web-root') ? trim($php->getAttribute('web-root'), '/') : $defaults->phpWebRoot,
            knowledgeLibrary: $knowledge?->hasAttribute('library') ? trim($knowledge->getAttribute('library')) : $defaults->knowledgeLibrary,
            shareKnowledge: $knowledge?->hasAttribute('share') ? 'false' !== $knowledge->getAttribute('share') && '0' !== $knowledge->getAttribute('share') : $defaults->shareKnowledge,
        );
    }
}
