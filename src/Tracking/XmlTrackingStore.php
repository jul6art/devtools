<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Serialization\EntryPointXml;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Xml\DomBuilder;
use Jul6Art\DevTools\Xml\InvalidXml;
use Jul6Art\DevTools\Xml\SafeXmlLoader;

/**
 * Reads and writes `workflows/<type>/<id>.xml` (`resources/schemas/workflow-tracking.xsd`).
 *
 * ⚠️ ADR-0004 names two different things `<source>`: the version-control state of specs § 4.6.1 and
 * the producer of the workflow. The first keeps the name the specs give it; the second is written
 * `<producer>`, since one element cannot mean both.
 */
final readonly class XmlTrackingStore implements TrackingReaderInterface, TrackingWriterInterface
{
    use ReadsDocumentFiles;

    public const string NAMESPACE = 'https://github.com/jul6art/devtools/schema/workflow-tracking/1';

    private const int SCHEMA_VERSION = 1;

    private DomBuilder $dom;

    public function __construct(
        private WorkflowTypeRegistry $types,
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private SafeXmlLoader $loader = new SafeXmlLoader(),
    ) {
        $this->dom = new DomBuilder(self::NAMESPACE);
    }

    #[\Override]
    public function write(string $path, TrackingDocument $document): bool
    {
        return $this->writer->write($path, $this->serialize($document));
    }

    #[\Override]
    public function read(string $path): TrackingDocument
    {
        return $this->unserialize(self::contentOf($path), $path);
    }

    public function serialize(TrackingDocument $document): string
    {
        $xml = $this->dom->document();
        $root = $this->dom->element($xml, 'workflow', ['id' => $document->id->value, 'type' => $document->type->name, 'schema-version' => (string) self::SCHEMA_VERSION]);

        $this->dom->element($root, 'title', text: $document->title);

        $generated = ['at' => self::date($document->generated->at), 'tool' => $document->generated->tool, 'mode' => $document->generated->mode->value];

        if (null !== $document->generated->model) {
            $generated['model'] = $document->generated->model;
        }

        if (null !== $document->generated->prompt) {
            $generated['prompt'] = $document->generated->prompt;
        }

        $this->dom->element($root, 'generated', $generated);
        VcsXml::write($this->dom, $root, 'source', $document->vcs);

        $entrypoints = $this->dom->element($root, 'entrypoints');
        EntryPointXml::write($this->dom, $entrypoints, 'entrypoint', $document->main, ['role' => 'main']);

        foreach ($document->satellites as $satellite) {
            EntryPointXml::write($this->dom, $entrypoints, 'entrypoint', $satellite, ['role' => 'satellite']);
        }

        $files = $this->dom->element($root, 'files');

        foreach ($document->files as $file) {
            $this->dom->element($files, 'file', ['path' => $file->file->path, 'role' => $file->file->role->value, 'sha256' => $file->sha256]);
        }

        if ([] !== $document->tests) {
            $tests = $this->dom->element($root, 'tests');

            foreach ($document->tests as $test) {
                $this->dom->element($tests, 'file', ['path' => $test->path, 'role' => $test->role->value]);
            }
        }

        if ([] !== $document->packages) {
            $packages = $this->dom->element($root, 'packages');

            foreach ($document->packages as $package) {
                $this->dom->element($packages, 'package', ['name' => $package->name, 'version' => $package->version]);
            }
        }

        if ([] !== $document->dependsOn) {
            $dependsOn = $this->dom->element($root, 'depends-on');

            foreach ($document->dependsOn as $dependency) {
                $this->dom->element($dependsOn, 'workflow', ['ref' => $dependency->value]);
            }
        }

        $this->writeDecisions($root, $document->decisions);

        if ([] !== $document->mechanisms) {
            $mechanisms = $this->dom->element($root, 'mechanisms');

            foreach ($document->mechanisms as $mechanism) {
                $node = $this->dom->element($mechanisms, 'mechanism', array_filter([
                    'kind' => $mechanism->kind,
                    'name' => $mechanism->name,
                    'event' => $mechanism->event,
                    'priority' => null === $mechanism->priority ? null : (string) $mechanism->priority,
                ], static fn (?string $value): bool => null !== $value));
                $this->dom->element($node, 'declared-in', ['path' => $mechanism->declaredIn->path, 'role' => $mechanism->declaredIn->role->value]);
                $this->writeDecisions($node, $mechanism->decisions);
            }
        }

        $this->dom->element($root, 'confidence', text: $document->confidence->value);
        $this->dom->element($root, 'producer', text: $document->producer->value);
        $this->dom->element($root, 'status', text: $document->status->value);

        $history = $this->dom->element($root, 'history');

        foreach ($document->history as $revision) {
            $attributes = ['at' => self::date($revision->at)];

            if (null !== $revision->commit) {
                $attributes['commit'] = $revision->commit;
            }

            $this->dom->element($history, 'revision', [...$attributes, 'reason' => $revision->reason]);
        }

        return $this->dom->toXml($xml);
    }

    /**
     * @param list<DecisionPoint> $decisions
     */
    private function writeDecisions(\DOMElement $parent, array $decisions): void
    {
        if ([] === $decisions) {
            return;
        }

        $container = $this->dom->element($parent, 'decisions');

        foreach ($decisions as $decision) {
            $this->dom->element($container, 'decision', array_filter([
                'target' => $decision->target,
                'value' => $decision->value,
                'condition' => $decision->condition,
                'file' => $decision->declaredIn->path,
                'line' => (string) $decision->line,
                'confidence' => $decision->confidence->value,
            ], static fn (?string $value): bool => null !== $value));
        }
    }

    /**
     * @return list<DecisionPoint>
     */
    private function readDecisions(\DOMElement $parent): array
    {
        return array_map(
            static fn (\DOMElement $decision): DecisionPoint => new DecisionPoint(
                $decision->getAttribute('target'),
                $decision->getAttribute('value'),
                $decision->hasAttribute('condition') ? $decision->getAttribute('condition') : null,
                new FileRef($decision->getAttribute('file')),
                (int) $decision->getAttribute('line'),
                Confidence::from($decision->getAttribute('confidence')),
            ),
            $this->dom->children($this->dom->optional($parent, 'decisions'), 'decision'),
        );
    }

    public function unserialize(string $xml, string $source): TrackingDocument
    {
        $root = DomBuilder::root($this->loader->loadValidated($xml, $source, Resources::path('schemas/workflow-tracking.xsd'), self::SCHEMA_VERSION));
        $generated = $this->dom->single($root, 'generated');
        $main = null;
        $satellites = [];

        foreach ($this->dom->children($this->dom->single($root, 'entrypoints'), 'entrypoint') as $entrypoint) {
            if ('main' === $entrypoint->getAttribute('role')) {
                // The schema cannot count attribute values: exactly one main entry point is checked here.
                if ($main instanceof EntryPoint) {
                    throw InvalidXml::refused($source, 'a workflow has exactly one main entry point, this document declares several.');
                }

                $main = EntryPointXml::read($this->dom, $entrypoint);
            } else {
                $satellites[] = EntryPointXml::read($this->dom, $entrypoint);
            }
        }

        return new TrackingDocument(
            id: new WorkflowId($root->getAttribute('id')),
            type: $this->types->get($root->getAttribute('type')),
            title: $this->dom->single($root, 'title')->textContent,
            generated: new Generation(
                self::parseDate($generated->getAttribute('at'), $source),
                $generated->getAttribute('tool'),
                GenerationMode::from($generated->getAttribute('mode')),
                $generated->hasAttribute('model') ? $generated->getAttribute('model') : null,
                $generated->hasAttribute('prompt') ? $generated->getAttribute('prompt') : null,
            ),
            vcs: VcsXml::read($this->dom->single($root, 'source')),
            main: $main ?? throw InvalidXml::refused($source, 'a workflow has exactly one main entry point, this document declares none.'),
            satellites: $satellites,
            files: array_map(
                static fn (\DOMElement $file): TrackedFile => new TrackedFile(new FileRef($file->getAttribute('path'), FileRole::from($file->getAttribute('role'))), $file->getAttribute('sha256')),
                $this->dom->children($this->dom->single($root, 'files'), 'file'),
            ),
            tests: array_map(
                static fn (\DOMElement $file): FileRef => new FileRef($file->getAttribute('path'), FileRole::from($file->getAttribute('role'))),
                $this->dom->children($this->dom->optional($root, 'tests'), 'file'),
            ),
            packages: array_map(
                static fn (\DOMElement $package): PackageRef => new PackageRef($package->getAttribute('name'), $package->getAttribute('version')),
                $this->dom->children($this->dom->optional($root, 'packages'), 'package'),
            ),
            dependsOn: array_map(
                static fn (\DOMElement $dependency): WorkflowId => new WorkflowId($dependency->getAttribute('ref')),
                $this->dom->children($this->dom->optional($root, 'depends-on'), 'workflow'),
            ),
            confidence: Confidence::from($this->dom->single($root, 'confidence')->textContent),
            producer: new WorkflowSource($this->dom->single($root, 'producer')->textContent),
            status: TrackingStatus::from($this->dom->single($root, 'status')->textContent),
            history: array_map(
                static fn (\DOMElement $revision): Revision => new Revision(
                    self::parseDate($revision->getAttribute('at'), $source),
                    $revision->hasAttribute('commit') ? $revision->getAttribute('commit') : null,
                    $revision->getAttribute('reason'),
                ),
                $this->dom->children($this->dom->single($root, 'history'), 'revision'),
            ),
            decisions: $this->readDecisions($root),
            mechanisms: array_map(
                fn (\DOMElement $mechanism): Mechanism => new Mechanism(
                    $mechanism->getAttribute('kind'),
                    $mechanism->getAttribute('name'),
                    $mechanism->getAttribute('event'),
                    new FileRef($this->dom->single($mechanism, 'declared-in')->getAttribute('path'), FileRole::from($this->dom->single($mechanism, 'declared-in')->getAttribute('role'))),
                    $mechanism->hasAttribute('priority') ? (int) $mechanism->getAttribute('priority') : null,
                    $this->readDecisions($mechanism),
                ),
                $this->dom->children($this->dom->optional($root, 'mechanisms'), 'mechanism'),
            ),
        );
    }
}
