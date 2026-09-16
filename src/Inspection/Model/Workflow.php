<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * One workflow of the intermediate model: what triggers it, which files it traverses, what it depends
 * on and where it leads.
 *
 * Every list is sorted at construction and refuses duplicates, so that two scans of an unchanged
 * project build an identical object — the property freshness and snapshots both rely on.
 */
final readonly class Workflow
{
    public string $title;

    /**
     * @var list<EntryPoint>
     */
    public array $satellites;

    /**
     * @var list<FileRef>
     */
    public array $files;

    /**
     * @var list<PackageRef>
     */
    public array $packages;

    /**
     * @var list<WorkflowId>
     */
    public array $dependsOn;

    /**
     * @var list<FileRef>
     */
    public array $tests;

    /**
     * @var list<Edge>
     */
    public array $navigation;

    /**
     * @param list<EntryPoint> $satellites
     * @param list<FileRef>    $files
     * @param list<PackageRef> $packages
     * @param list<WorkflowId> $dependsOn
     * @param list<FileRef>    $tests
     * @param list<Edge>       $navigation
     */
    public function __construct(
        public WorkflowId $id,
        public WorkflowType $type,
        string $title,
        public EntryPoint $main,
        array $satellites = [],
        array $files = [],
        array $packages = [],
        array $dependsOn = [],
        array $tests = [],
        array $navigation = [],
        public ?StateMachine $states = null,
        public Confidence $confidence = Confidence::Medium,
        public WorkflowSource $source = new WorkflowSource('claude'),
    ) {
        if ($id->prefix() !== $type->idPrefix) {
            throw new InvalidModel(\sprintf('The workflow "%s" is of type "%s": its identifier must start with "%s".', $id, $type->name, $type->idPrefix));
        }

        $this->title = NonEmpty::string($title, \sprintf('title of workflow "%s"', $id));
        $this->satellites = SortedList::of($satellites, static fn (EntryPoint $e): string => $e->kind."\0".$e->name."\0".$e->declaredIn->path, \sprintf('satellite of "%s"', $id));
        $this->files = SortedList::of($files, static fn (FileRef $file): string => $file->path, \sprintf('file of "%s"', $id));
        $this->packages = SortedList::of($packages, static fn (PackageRef $package): string => $package->name, \sprintf('package of "%s"', $id));
        $this->dependsOn = SortedList::of($dependsOn, static fn (WorkflowId $dependency): string => $dependency->value, \sprintf('dependency of "%s"', $id));
        $this->tests = SortedList::of($tests, static fn (FileRef $test): string => $test->path, \sprintf('test of "%s"', $id));
        $this->navigation = SortedList::of($navigation, static fn (Edge $edge): string => $edge->sortKey(), \sprintf('navigation edge of "%s"', $id));

        foreach ($this->dependsOn as $dependency) {
            if ($dependency->equals($id)) {
                throw new InvalidModel(\sprintf('The workflow "%s" cannot depend on itself.', $id));
            }
        }
    }
}
