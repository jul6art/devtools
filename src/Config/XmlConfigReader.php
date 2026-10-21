<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Link\LinkedProject;
use Jul6Art\DevTools\Link\LinkRole;
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
        $laravel = $this->dom->optional($root, 'laravel');
        $node = $this->dom->optional($root, 'node');
        $ai = $this->dom->optional($root, 'ai');
        $flag = static fn (?\DOMElement $element, string $name, bool $default): bool => $element?->hasAttribute($name) ? \in_array($element->getAttribute($name), ['true', '1'], true) : $default;
        $language = $this->dom->optional($root, 'language');
        $php = $this->dom->optional($root, 'php');
        $routes = $this->dom->optional($root, 'routes');
        $knowledge = $this->dom->optional($root, 'knowledge');
        $scenarios = $this->dom->optional($root, 'scenarios');

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
            laravelArtisan: $laravel?->hasAttribute('artisan') ? $laravel->getAttribute('artisan') : $defaults->laravelArtisan,
            laravelEnv: $laravel?->hasAttribute('env') ? $laravel->getAttribute('env') : $defaults->laravelEnv,
            laravelTimeout: $laravel?->hasAttribute('timeout') ? (float) $laravel->getAttribute('timeout') : $defaults->laravelTimeout,
            nodeBinary: $node?->hasAttribute('binary') ? $node->getAttribute('binary') : $defaults->nodeBinary,
            nodeTimeout: $node?->hasAttribute('timeout') ? (float) $node->getAttribute('timeout') : $defaults->nodeTimeout,
            aiEnabled: $flag($ai, 'enabled', $defaults->aiEnabled),
            aiModel: $ai?->hasAttribute('model') ? $ai->getAttribute('model') : $defaults->aiModel,
            aiMaxTokens: $ai?->hasAttribute('max-tokens') ? (int) $ai->getAttribute('max-tokens') : $defaults->aiMaxTokens,
            aiBudgetTokens: $ai?->hasAttribute('budget-tokens') ? (int) $ai->getAttribute('budget-tokens') : $defaults->aiBudgetTokens,
            aiEffort: $ai?->hasAttribute('effort') ? $ai->getAttribute('effort') : $defaults->aiEffort,
            aiWebSearch: $flag($ai, 'web-search', $defaults->aiWebSearch),
            pagesLanguage: $language instanceof \DOMElement ? $language->getAttribute('pages') : $defaults->pagesLanguage,
            routeGrouping: $routes?->hasAttribute('group') ? $routes->getAttribute('group') : $defaults->routeGrouping,
            phpWebRoot: $php?->hasAttribute('web-root') ? trim($php->getAttribute('web-root'), '/') : $defaults->phpWebRoot,
            knowledgeLibrary: $knowledge?->hasAttribute('library') ? trim($knowledge->getAttribute('library')) : $defaults->knowledgeLibrary,
            shareKnowledge: $knowledge?->hasAttribute('share') ? 'false' !== $knowledge->getAttribute('share') && '0' !== $knowledge->getAttribute('share') : $defaults->shareKnowledge,
            scenarios: $scenarios instanceof \DOMElement ? $this->scenarios($scenarios) : $defaults->scenarios,
            feedbackCategories: array_map(static fn (\DOMElement $category): string => $category->getAttribute('name'), $this->dom->children($this->dom->optional($root, 'feedback'), 'category')),
            links: array_map(static fn (\DOMElement $project): LinkedProject => new LinkedProject(
                $project->getAttribute('name'),
                $project->getAttribute('path'),
                LinkRole::from($project->getAttribute('role')),
                $project->hasAttribute('base-url') ? $project->getAttribute('base-url') : null,
            ), $this->dom->children($this->dom->optional($root, 'links'), 'project')),
        );
    }

    private function scenarios(\DOMElement $scenarios): ScenarioSettings
    {
        $optional = static fn (\DOMElement $element, string $name): ?string => $element->hasAttribute($name) ? $element->getAttribute($name) : null;

        return new ScenarioSettings(
            $optional($scenarios, 'base-url'),
            (int) ($optional($scenarios, 'timeout') ?? ScenarioSettings::DEFAULT_TIMEOUT),
            array_map(
                static fn (\DOMElement $login): LoginSettings => new LoginSettings(
                    $login->getAttribute('role'),
                    $login->getAttribute('type'),
                    $login->getAttribute('username-env'),
                    $login->getAttribute('password-env'),
                    $optional($login, 'url'),
                    $optional($login, 'username-field') ?? '_username',
                    $optional($login, 'password-field') ?? '_password',
                    $optional($login, 'submit'),
                ),
                $this->dom->children($scenarios, 'login'),
            ),
            array_map(static fn (\DOMElement $fixture): FixtureSettings => new FixtureSettings($fixture->getAttribute('name'), $fixture->getAttribute('command')), $this->dom->children($scenarios, 'fixture')),
            $this->visual($this->dom->optional($scenarios, 'visual')),
        );
    }

    private function visual(?\DOMElement $visual): VisualSettings
    {
        if (!$visual instanceof \DOMElement) {
            return new VisualSettings();
        }

        return new VisualSettings(
            $visual->hasAttribute('threshold') ? (float) rtrim($visual->getAttribute('threshold'), '%') : VisualSettings::DEFAULT_THRESHOLD,
            array_map(static fn (\DOMElement $mask): string => $mask->getAttribute('selector'), $this->dom->children($visual, 'mask')),
        );
    }
}
