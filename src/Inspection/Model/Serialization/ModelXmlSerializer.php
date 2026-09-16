<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model\Serialization;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Transition;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes the intermediate model as XML (`resources/schemas/inspection-model.xsd`).
 *
 * Reading validates against the schema first, then rebuilds the model through its constructors, so
 * the invariants a schema cannot express are enforced too. Writing omits empty containers and relies
 * on the model's sorted lists, which makes the output byte-stable.
 */
final readonly class ModelXmlSerializer
{
    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/inspection-model/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private WorkflowTypeRegistry $types,
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    public function serialize(InspectionResult $result): string
    {
        $document = $this->dom->document();
        $root = $this->dom->element($document, 'inspection', ['schema-version' => (string) self::SCHEMA_VERSION, 'stack' => $result->stack]);
        $workflows = $this->dom->element($root, 'workflows');

        foreach ($result->workflows as $workflow) {
            $this->writeWorkflow($workflows, $workflow);
        }

        $this->writeFiles($root, 'uncovered', $result->uncovered);

        return $this->dom->toXml($document);
    }

    public function deserialize(string $xml, string $source = 'inspection model'): InspectionResult
    {
        $root = DomBuilder::root($this->loader->loadValidated($xml, $source, Resources::path('schemas/inspection-model.xsd'), self::SCHEMA_VERSION));
        $workflows = [];

        foreach ($this->dom->children($this->dom->single($root, 'workflows'), 'workflow') as $workflow) {
            $workflows[] = $this->readWorkflow($workflow);
        }

        return new InspectionResult($root->getAttribute('stack'), $workflows, $this->readFiles($root, 'uncovered'));
    }

    private function writeWorkflow(\DOMElement $parent, Workflow $workflow): void
    {
        $element = $this->dom->element($parent, 'workflow', [
            'id' => $workflow->id->value,
            'type' => $workflow->type->name,
            'confidence' => $workflow->confidence->value,
            'source' => $workflow->source->value,
        ]);

        $this->dom->element($element, 'title', text: $workflow->title);
        EntryPointXml::write($this->dom, $element, 'main', $workflow->main);

        if ([] !== $workflow->satellites) {
            $satellites = $this->dom->element($element, 'satellites');

            foreach ($workflow->satellites as $satellite) {
                EntryPointXml::write($this->dom, $satellites, 'entrypoint', $satellite);
            }
        }

        $this->writeFiles($element, 'files', $workflow->files);

        if ([] !== $workflow->packages) {
            $packages = $this->dom->element($element, 'packages');

            foreach ($workflow->packages as $package) {
                $this->dom->element($packages, 'package', ['name' => $package->name, 'version' => $package->version]);
            }
        }

        if ([] !== $workflow->dependsOn) {
            $dependsOn = $this->dom->element($element, 'depends-on');

            foreach ($workflow->dependsOn as $dependency) {
                $this->dom->element($dependsOn, 'workflow', ['ref' => $dependency->value]);
            }
        }

        $this->writeFiles($element, 'tests', $workflow->tests);

        if ([] !== $workflow->navigation) {
            $navigation = $this->dom->element($element, 'navigation');

            foreach ($workflow->navigation as $edge) {
                $this->dom->element($navigation, 'edge', null === $edge->label ? ['target' => $edge->target] : ['target' => $edge->target, 'label' => $edge->label]);
            }
        }

        if ($workflow->states instanceof StateMachine) {
            $states = $this->dom->element($element, 'states', ['name' => $workflow->states->name]);

            foreach ($workflow->states->places as $place) {
                $this->dom->element($states, 'place', ['name' => $place]);
            }

            foreach ($workflow->states->transitions as $transition) {
                $transitionElement = $this->dom->element($states, 'transition', ['name' => $transition->name]);

                foreach ($transition->from as $place) {
                    $this->dom->element($transitionElement, 'from', ['place' => $place]);
                }

                foreach ($transition->to as $place) {
                    $this->dom->element($transitionElement, 'to', ['place' => $place]);
                }
            }
        }
    }

    /**
     * @param list<FileRef> $files
     */
    private function writeFiles(\DOMElement $parent, string $name, array $files): void
    {
        if ([] === $files) {
            return;
        }

        $container = $this->dom->element($parent, $name);

        foreach ($files as $file) {
            $this->dom->element($container, 'file', ['path' => $file->path, 'role' => $file->role->value]);
        }
    }

    private function readWorkflow(\DOMElement $element): Workflow
    {
        $satellites = array_map(
            fn (\DOMElement $satellite): EntryPoint => EntryPointXml::read($this->dom, $satellite),
            $this->dom->children($this->dom->optional($element, 'satellites'), 'entrypoint'),
        );

        $packages = array_map(
            static fn (\DOMElement $package): PackageRef => new PackageRef($package->getAttribute('name'), $package->getAttribute('version')),
            $this->dom->children($this->dom->optional($element, 'packages'), 'package'),
        );

        $dependsOn = array_map(
            static fn (\DOMElement $dependency): WorkflowId => new WorkflowId($dependency->getAttribute('ref')),
            $this->dom->children($this->dom->optional($element, 'depends-on'), 'workflow'),
        );

        $navigation = array_map(
            static fn (\DOMElement $edge): Edge => new Edge($edge->getAttribute('target'), $edge->hasAttribute('label') ? $edge->getAttribute('label') : null),
            $this->dom->children($this->dom->optional($element, 'navigation'), 'edge'),
        );

        return new Workflow(
            id: new WorkflowId($element->getAttribute('id')),
            type: $this->types->get($element->getAttribute('type')),
            title: $this->dom->single($element, 'title')->textContent,
            main: EntryPointXml::read($this->dom, $this->dom->single($element, 'main')),
            satellites: $satellites,
            files: $this->readFiles($element, 'files'),
            packages: $packages,
            dependsOn: $dependsOn,
            tests: $this->readFiles($element, 'tests'),
            navigation: $navigation,
            states: $this->readStates($this->dom->optional($element, 'states')),
            confidence: Confidence::from($element->getAttribute('confidence')),
            source: new WorkflowSource($element->getAttribute('source')),
        );
    }

    /**
     * @return list<FileRef>
     */
    private function readFiles(\DOMElement $parent, string $name): array
    {
        return array_map(
            static fn (\DOMElement $file): FileRef => new FileRef($file->getAttribute('path'), FileRole::from($file->getAttribute('role'))),
            $this->dom->children($this->dom->optional($parent, $name), 'file'),
        );
    }

    private function readStates(?\DOMElement $element): ?StateMachine
    {
        if (!$element instanceof \DOMElement) {
            return null;
        }

        $places = array_map(static fn (\DOMElement $place): string => $place->getAttribute('name'), $this->dom->children($element, 'place'));
        $transitions = [];

        foreach ($this->dom->children($element, 'transition') as $transition) {
            $transitions[] = new Transition(
                $transition->getAttribute('name'),
                array_map(static fn (\DOMElement $from): string => $from->getAttribute('place'), $this->dom->children($transition, 'from')),
                array_map(static fn (\DOMElement $to): string => $to->getAttribute('place'), $this->dom->children($transition, 'to')),
            );
        }

        return new StateMachine($element->getAttribute('name'), $places, $transitions);
    }
}
