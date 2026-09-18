<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Review;

use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * What `workflows:accept`, `workflows:reject` and `workflows:review` all do (ADR-0047): read the changed
 * facts, pick some of them, then either write the documentation or say how to undo the code.
 *
 * ⚠️ Accepting writes the tracking files — nothing else records a decision. Refusing writes nothing at
 * all: the drift stays visible until the code comes back.
 */
final readonly class ChangeReview
{
    public function __construct(
        private InspectionPipeline $pipeline = new InspectionPipeline(),
        private GitClient $git = new GitClient(),
    ) {
    }

    /**
     * @return array{InspectionReport, list<ChangeGroup>}
     */
    public function read(string $path, ?string $only = null): array
    {
        $report = $this->pipeline->run(new InspectionOptions($path, only: $only, dryRun: true, noAi: true));

        return [$report, $report->changeGroups()];
    }

    /**
     * The groups whose fact is named by one of these targets.
     *
     * @param list<ChangeGroup> $groups
     * @param list<string>      $targets
     *
     * @return list<ChangeGroup>
     */
    public static function select(array $groups, array $targets): array
    {
        return array_values(array_filter($groups, static fn (ChangeGroup $group): bool => \in_array($group->change->target, $targets, true)));
    }

    /**
     * @param list<ChangeGroup> $groups
     *
     * @return list<string>
     */
    public static function targets(array $groups): array
    {
        return array_values(array_unique(array_map(static fn (ChangeGroup $group): string => $group->change->target, $groups)));
    }

    /**
     * Accepting: the pages of the workflows carrying these facts are rewritten, and **only** those.
     *
     * @param list<ChangeGroup> $accepted
     */
    public function accept(string $path, array $accepted, bool $noAi = false, ?string $only = null): InspectionReport
    {
        $ids = [];

        foreach ($accepted as $group) {
            foreach ($group->workflows as $id) {
                $ids[$id] = true;
            }
        }

        // `force` makes them stale whatever the freshness says; `restrictTo` keeps every other workflow
        // out of the rewrite, including those whose own code changed and that nobody has accepted.
        return $this->pipeline->run(new InspectionOptions($path, only: $only, force: array_keys($ids), noAi: $noAi, restrictTo: array_keys($ids)));
    }

    /**
     * Refusing: the command that brings each file back to the state the page was written from.
     *
     * @param list<ChangeGroup> $refused
     *
     * @return array<string, string> file => the `git restore` that undoes it
     */
    public function restoreCommands(InspectionReport $report, array $refused): array
    {
        $commands = [];

        foreach (self::filesOf($report, $refused) as $file => $commit) {
            $commands[$file] = \sprintf('git restore --source=%s -- %s', substr($commit, 0, 8), $file);
        }

        return $commands;
    }

    /**
     * ⚠️ A file that carries another changed fact — one nobody refused — is not restored: bringing it
     * back would undo that one too, silently. The command is printed instead, and the human decides.
     *
     * @param list<ChangeGroup> $refused
     * @param list<ChangeGroup> $all     every changed fact, refused or not
     *
     * @return array{list<string>, list<string>} the files restored, and those left to the human
     */
    public function restore(string $path, InspectionReport $report, array $refused, array $all): array
    {
        $root = new ProjectRoot($path);
        $refusedFiles = self::filesOf($report, $refused);
        $kept = self::filesOf($report, array_values(array_filter($all, static fn (ChangeGroup $group): bool => !\in_array($group, $refused, true))));

        $restored = [];
        $left = [];

        foreach ($refusedFiles as $file => $commit) {
            if (isset($kept[$file]) || !$this->git->restore($root, $commit, [$file])) {
                $left[] = $file;

                continue;
            }

            $restored[] = $file;
        }

        return [$restored, $left];
    }

    /**
     * @param list<ChangeGroup> $groups
     *
     * @return array<string, string> file => the commit its pages were written from
     */
    private static function filesOf(InspectionReport $report, array $groups): array
    {
        $files = [];

        foreach ($groups as $group) {
            $file = $group->change->declaredIn?->path;

            if (null === $file) {
                continue;
            }

            foreach ($group->workflows as $id) {
                if (isset($report->writtenFrom[$id])) {
                    $files[$file] ??= $report->writtenFrom[$id];

                    break;
                }
            }
        }

        return $files;
    }
}
