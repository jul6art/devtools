<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes the brief of the scenarios of a workflow (`resources/schemas/scenario-brief.xsd`).
 */
final readonly class XmlScenarioBriefStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/scenario-brief/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter(), private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function write(string $path, ScenarioBrief $brief): bool
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'scenario-brief', ['schema-version' => (string) self::SCHEMA_VERSION, 'workflow' => $brief->workflow->value, 'revision' => self::date($brief->revision)]);
        $this->dom->element($root, 'prompt', ['version' => $brief->promptVersion, 'path' => $brief->promptPath]);
        $this->dom->element($root, 'reason', text: $brief->reason);
        $this->dom->element($root, 'page', ['path' => $brief->pagePath]);
        $this->dom->element($root, 'tracking', ['path' => $brief->trackingPath]);

        if (null !== $brief->knowledgePath) {
            $this->dom->element($root, 'knowledge', ['path' => $brief->knowledgePath]);
        }

        $this->dom->element($root, 'vocabulary', ['path' => $brief->vocabularyPath]);

        foreach ($brief->routes as $route) {
            $this->dom->element($root, 'route', array_filter($route, \is_string(...)));
        }

        if (null !== $brief->login) {
            $this->dom->element($root, 'login', ['role' => $brief->login]);
        }

        foreach ($brief->scenarios as $scenario) {
            $this->dom->element($root, 'scenario', $scenario);
        }

        $this->dom->element($root, 'draft', ['path' => $brief->draftPath]);

        return $this->writer->write($path, $this->dom->toXml($xml));
    }

    public function read(string $path): ScenarioBrief
    {
        $root = DomBuilder::root($this->loader->loadValidated(self::contentOf($path), $path, Resources::path('schemas/scenario-brief.xsd'), self::SCHEMA_VERSION));
        $prompt = $this->dom->single($root, 'prompt');
        $optional = static fn (\DOMElement $element, string $name): ?string => $element->hasAttribute($name) ? $element->getAttribute($name) : null;

        return new ScenarioBrief(
            new WorkflowId($root->getAttribute('workflow')),
            self::parseDate($root->getAttribute('revision'), $path),
            $prompt->getAttribute('version'),
            $prompt->getAttribute('path'),
            $this->dom->single($root, 'reason')->textContent,
            $this->dom->single($root, 'page')->getAttribute('path'),
            $this->dom->single($root, 'tracking')->getAttribute('path'),
            $this->dom->optional($root, 'knowledge')?->getAttribute('path'),
            $this->dom->single($root, 'vocabulary')->getAttribute('path'),
            array_map(static fn (\DOMElement $route): array => ['name' => $route->getAttribute('name'), 'path' => $optional($route, 'path'), 'methods' => $optional($route, 'methods')], $this->dom->children($root, 'route')),
            $this->dom->optional($root, 'login')?->getAttribute('role'),
            array_map(static fn (\DOMElement $scenario): array => ['path' => $scenario->getAttribute('path'), 'origin' => $scenario->getAttribute('origin')], $this->dom->children($root, 'scenario')),
            $this->dom->single($root, 'draft')->getAttribute('path'),
        );
    }
}
