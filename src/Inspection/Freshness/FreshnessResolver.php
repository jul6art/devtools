<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;

/**
 * Decides which workflows need their page rewritten, and why (specs § 4.6.2, ADR-0010).
 *
 * Git narrows the files worth hashing; the hash decides. A false "unchanged" is worse than a needless
 * rewrite — every later guarantee (impact, gate) is built on this decision.
 */
final readonly class FreshnessResolver
{
    public function __construct(private FileHasher $hasher)
    {
    }

    /**
     * @param list<Workflow>                  $current
     * @param array<string, TrackingDocument> $previous   by identifier
     * @param list<string>|null               $candidates files git reports as changed; null to hash every file
     * @param list<string>                    $forced     identifiers to rewrite whatever their state
     *
     * @return array<string, FreshnessDecision> by identifier, orphans included
     */
    public function resolve(ProjectRoot $root, array $current, array $previous, ?array $candidates, array $forced = [], bool $forceAll = false): array
    {
        $decisions = [];

        foreach ($current as $workflow) {
            $old = $previous[$workflow->id->value] ?? null;
            unset($previous[$workflow->id->value]);
            $decisions[$workflow->id->value] = $this->decide($root, $workflow, $old, $candidates, $forceAll || \in_array($workflow->id->value, $forced, true));
        }

        foreach ($previous as $id => $old) {
            $decisions[$id] = new FreshnessDecision($old->id, DecisionKind::Orphan, [Reason::entryPointGone()]);
        }

        ksort($decisions, \SORT_STRING);

        return $decisions;
    }

    /**
     * @param list<string>|null $candidates
     */
    private function decide(ProjectRoot $root, Workflow $workflow, ?TrackingDocument $old, ?array $candidates, bool $forced): FreshnessDecision
    {
        if (!$old instanceof TrackingDocument) {
            return new FreshnessDecision($workflow->id, DecisionKind::Create, [Reason::noPreviousTracking()]);
        }

        $reasons = $forced ? [Reason::forced()] : [];

        if (TrackingStatus::Orphaned === $old->status) {
            $reasons[] = Reason::entryPointBack();
        }

        $recorded = [];

        foreach ($old->files as $file) {
            $recorded[$file->file->path] = $file->sha256;
        }

        $currentPaths = array_map(static fn (FileRef $file): string => $file->path, $workflow->files);
        $common = array_values(array_intersect($currentPaths, array_keys($recorded)));
        $worthHashing = null === $candidates ? $common : array_values(array_intersect($common, $candidates));
        $changed = array_values(array_filter($worthHashing, fn (string $path): bool => $this->hasher->hash($root->absolute($path)) !== $recorded[$path]));
        $removed = array_values(array_diff(array_keys($recorded), $currentPaths));
        $added = array_values(array_diff($currentPaths, array_keys($recorded)));

        foreach ([[$changed, Reason::filesChanged(...)], [$removed, Reason::filesRemoved(...)], [$added, Reason::filesAdded(...)]] as [$paths, $reason]) {
            if ([] !== $paths) {
                sort($paths, \SORT_STRING);
                $reasons[] = $reason($paths);
            }
        }

        $reasons = [...$reasons, ...$this->majorChanges($old->packages, $workflow->packages)];

        if (TrackingStatus::Manual === $old->status) {
            return new FreshnessDecision($workflow->id, [] === $reasons ? DecisionKind::Keep : DecisionKind::ManualStale, $reasons);
        }

        return new FreshnessDecision($workflow->id, [] === $reasons ? DecisionKind::Keep : DecisionKind::Rewrite, $reasons);
    }

    /**
     * @param list<PackageRef> $before
     * @param list<PackageRef> $after
     *
     * @return list<Reason>
     */
    private function majorChanges(array $before, array $after): array
    {
        $versions = [];

        foreach ($before as $package) {
            $versions[$package->name] = $package->version;
        }

        $reasons = [];

        foreach ($after as $package) {
            $from = $versions[$package->name] ?? null;

            if (null !== $from && self::major($from) !== self::major($package->version)) {
                $reasons[] = Reason::packageMajor($package->name, $from, $package->version);
            }
        }

        return $reasons;
    }

    private static function major(string $version): string
    {
        return 1 === preg_match('/(\d+)/', $version, $matches) ? $matches[1] : $version;
    }
}
