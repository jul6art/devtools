<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\SortedList;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * The tracking file of one workflow (specs § 4.6.1): what it was generated from, so that a re-scan
 * knows whether it changed without rewriting it.
 */
final readonly class TrackingDocument
{
    public string $title;

    /**
     * @var list<EntryPoint>
     */
    public array $satellites;

    /**
     * @var list<TrackedFile>
     */
    public array $files;

    /**
     * @var list<FileRef>
     */
    public array $tests;

    /**
     * @var list<PackageRef>
     */
    public array $packages;

    /**
     * @var list<WorkflowId>
     */
    public array $dependsOn;

    /**
     * @var non-empty-list<Revision>
     */
    public array $history;

    /**
     * @param list<EntryPoint>  $satellites
     * @param list<TrackedFile> $files
     * @param list<FileRef>     $tests
     * @param list<PackageRef>  $packages
     * @param list<WorkflowId>  $dependsOn
     * @param list<Revision>    $history    oldest first
     */
    public function __construct(
        public WorkflowId $id,
        public WorkflowType $type,
        string $title,
        public Generation $generated,
        public VcsState $vcs,
        public EntryPoint $main,
        array $satellites = [],
        array $files = [],
        array $tests = [],
        array $packages = [],
        array $dependsOn = [],
        public Confidence $confidence = Confidence::Medium,
        public WorkflowSource $producer = new WorkflowSource('claude'),
        public TrackingStatus $status = TrackingStatus::Fresh,
        array $history = [],
    ) {
        if ($id->prefix() !== $type->idPrefix) {
            throw new InvalidModel(\sprintf('The tracked workflow "%s" is of type "%s": its identifier must start with "%s".', $id, $type->name, $type->idPrefix));
        }

        if ([] === $history) {
            throw new InvalidModel(\sprintf('The tracking file of "%s" needs at least its initial revision.', $id));
        }

        $this->title = trim($title);

        if ('' === $this->title) {
            throw new InvalidModel(\sprintf('The tracked workflow "%s" needs a title.', $id));
        }

        $this->satellites = SortedList::of($satellites, static fn (EntryPoint $e): string => $e->kind."\0".$e->name."\0".$e->declaredIn->path, \sprintf('satellite of "%s"', $id));
        $this->files = SortedList::of($files, static fn (TrackedFile $file): string => $file->file->path, \sprintf('tracked file of "%s"', $id));
        $this->tests = SortedList::of($tests, static fn (FileRef $test): string => $test->path, \sprintf('test of "%s"', $id));
        $this->packages = SortedList::of($packages, static fn (PackageRef $package): string => $package->name, \sprintf('package of "%s"', $id));
        $this->dependsOn = SortedList::of($dependsOn, static fn (WorkflowId $dependency): string => $dependency->value, \sprintf('dependency of "%s"', $id));
        $this->history = $history;
    }

    public function lastRevision(): Revision
    {
        return array_last($this->history);
    }
}
