<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * What an adapter hands to the graph: one entry point and what only the adapter knows about it.
 *
 * Identifiers, grouping, traversed files, tests and coverage are the builder's job, identical for every
 * adapter — which is what keeps two stacks rendering alike.
 */
final readonly class EntryPointCandidate
{
    /**
     * @param string|null      $method          the method the entry point runs, to scope the first hop
     * @param list<FileRef>    $structuralFiles configuration declaring it, always tracked
     * @param list<EntryPoint> $dependsOn       entry points of workflows this one depends on
     * @param list<Edge>       $navigation
     * @param list<FileRef>    $extraTests      tests the adapter found by other means (a literal URL)
     * @param list<PackageRef> $packages        packages the entry point runs that no file of it names: the controller of
     *                                          a package, routed by the project's configuration (ADR-0052)
     */
    public function __construct(
        public WorkflowType $type,
        public EntryPoint $entryPoint,
        public string $title,
        public ?string $method = null,
        public array $structuralFiles = [],
        public array $dependsOn = [],
        public array $navigation = [],
        public ?StateMachine $states = null,
        public array $extraTests = [],
        public Confidence $confidence = Confidence::Medium,
        public WorkflowSource $source = new WorkflowSource('claude'),
        public array $packages = [],
    ) {
    }

    /**
     * @param list<EntryPoint> $dependsOn
     */
    public function withDependsOn(array $dependsOn): self
    {
        return new self($this->type, $this->entryPoint, $this->title, $this->method, $this->structuralFiles, $dependsOn, $this->navigation, $this->states, $this->extraTests, $this->confidence, $this->source, $this->packages);
    }
}
