<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\ReadsDocumentFiles;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes scenarios (`resources/schemas/scenario.xsd`): one per file in `.devtools/scenarios/`,
 * several under `<scenarios>` in the draft Claude writes (ADR-0020).
 */
final readonly class XmlScenarioStore
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/scenario/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(private SafeXmlLoader $loader = new SafeXmlLoader())
    {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function serialize(Scenario $scenario): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'scenario', ['schema-version' => (string) self::SCHEMA_VERSION, 'id' => $scenario->id, 'workflow' => $scenario->workflow->value]);

        if (null !== $scenario->login || [] !== $scenario->fixtures) {
            $given = $this->dom->element($root, 'given');

            if (null !== $scenario->login) {
                $this->dom->element($given, 'login', ['role' => $scenario->login]);
            }

            foreach ($scenario->fixtures as $fixture) {
                $this->dom->element($given, 'fixture', ['name' => $fixture]);
            }
        }

        $steps = $this->dom->element($root, 'steps');

        foreach ($scenario->steps as $step) {
            $element = $this->dom->element($steps, $step->action, $step->arguments);

            foreach ($step->parameters as $name => $value) {
                $this->dom->element($element, 'param', ['name' => $name, 'value' => $value]);
            }
        }

        return $this->dom->toXml($xml);
    }

    /**
     * @throws InvalidXml when the file is not a valid scenario
     */
    public function read(string $path): Scenario
    {
        return $this->unserialize(self::contentOf($path), $path);
    }

    /**
     * @throws InvalidXml when the content is not a valid scenario
     */
    public function unserialize(string $xml, string $source): Scenario
    {
        $root = $this->root($xml, $source, 'scenario');

        return $this->scenario($root, $source);
    }

    /**
     * The scenarios of a draft.
     *
     * @return list<Scenario>
     *
     * @throws InvalidXml when the draft is not valid
     */
    public function readDraft(string $path): array
    {
        $root = $this->root(self::contentOf($path), $path, 'scenarios');

        return array_map(fn (\DOMElement $scenario): Scenario => $this->scenario($scenario, $path), $this->dom->children($root, 'scenario'));
    }

    private function root(string $xml, string $source, string $expected): \DOMElement
    {
        $root = DomBuilder::root($this->loader->loadValidated($xml, $source, Resources::path('schemas/scenario.xsd'), self::SCHEMA_VERSION));

        if ($expected !== $root->localName) {
            throw InvalidXml::refused($source, \sprintf('expected <%s>, found <%s>.', $expected, $root->localName));
        }

        return $root;
    }

    private function scenario(\DOMElement $element, string $source): Scenario
    {
        $id = $element->getAttribute('id');
        $workflow = $element->getAttribute('workflow');

        if (!str_starts_with($id, $workflow.'/')) {
            throw InvalidXml::refused($source, \sprintf('the scenario "%s" belongs to the workflow its identifier starts with, not "%s".', $id, $workflow));
        }

        $given = $this->dom->optional($element, 'given');
        $steps = [];

        foreach ($this->dom->single($element, 'steps')->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $arguments = [];

            foreach ($node->attributes as $attribute) {
                $arguments[$attribute->nodeName] = $attribute->nodeValue ?? '';
            }

            $parameters = [];

            foreach ($this->dom->children($node, 'param') as $parameter) {
                $parameters[$parameter->getAttribute('name')] = $parameter->getAttribute('value');
            }

            try {
                $steps[] = new Step((string) $node->localName, $arguments, $parameters);
            } catch (\InvalidArgumentException $invalid) {
                throw InvalidXml::refused($source, \sprintf('in "%s": %s', $id, $invalid->getMessage()));
            }
        }

        try {
            return new Scenario(
                new WorkflowId($workflow),
                substr($id, \strlen($workflow) + 1),
                $steps,
                $given instanceof \DOMElement ? $this->dom->optional($given, 'login')?->getAttribute('role') : null,
                array_map(static fn (\DOMElement $fixture): string => $fixture->getAttribute('name'), $this->dom->children($given, 'fixture')),
            );
        } catch (InvalidModel|\InvalidArgumentException $invalid) {
            throw InvalidXml::refused($source, $invalid->getMessage());
        }
    }
}
