<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `scenarios:generate` (ADR-0020): a skeleton from the facts for each route that allows one, a brief for
 * Claude for each workflow without a scenario of its own or whose scenarios its rewrite put to review —
 * and nothing for a scenario a human touched.
 *
 * It reads the index and the tracking files, never the code.
 */
final readonly class ScenarioGenerator
{
    /**
     * Routes and components: what a browser reaches. Commands and messages have no scenario (ADR-0020).
     */
    public const array TYPES = ['routes', 'ui'];

    public const string PROMPT_VERSION = 'v1';

    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    /**
     * @param WorkflowId|null $only   one workflow instead of all
     * @param bool            $noAi   skeletons only, no brief
     * @param list<string>    $review scenarios a human reviewed: recorded as theirs, against the current revision, and nothing else is done
     *
     * @throws ScenariosUnavailable when the project was never inspected, or the workflow asked for has no scenario
     */
    public function generate(DevToolsDirectory $directory, WorkflowTypeRegistry $types, ?WorkflowId $only = null, bool $noAi = false, array $review = []): ScenarioGeneration
    {
        if (!is_file($directory->indexFile())) {
            throw ScenariosUnavailable::notInspected();
        }

        $index = new XmlIndexStore($types)->read($directory->indexFile());
        $result = new ScenarioGeneration();
        $indexStore = new XmlScenarioIndexStore($this->writer);
        $scenarios = $this->synchronise($directory, $index, $indexStore->read($directory->scenarioIndexFile()), $result);

        if ([] !== $review) {
            $scenarios = $this->review($directory, $index, $scenarios, $review, $result);
        } else {
            $tracking = new XmlTrackingStore($types);

            foreach ($this->candidates($index, $only) as $entry) {
                $scenarios = $this->generateFor($directory, $entry, $tracking->read($directory->trackingFile($entry->type, $entry->id)), $scenarios, $noAi, $result);
            }
        }

        if ([] !== $scenarios->entries || is_file($directory->scenarioIndexFile())) {
            $indexStore->write($directory->scenarioIndexFile(), $scenarios);
        }

        return $result;
    }

    /**
     * The index as the disk is: a file written by hand is a scenario of a human, a file that no longer matches
     * its hash was modified by one, a file gone is a scenario gone.
     */
    private function synchronise(DevToolsDirectory $directory, Index $index, ScenarioIndex $scenarios, ScenarioGeneration $result): ScenarioIndex
    {
        $store = new XmlScenarioStore();
        $found = [];

        foreach (glob($directory->path('scenarios/*/*.xml')) ?: [] as $file) {
            $id = basename(\dirname($file)).'/'.basename($file, '.xml');
            $relative = $directory->root->relative($file);

            try {
                $scenario = $store->read($file);
            } catch (InvalidXml $invalid) {
                $result->errors[$relative] = $invalid->getMessage();
                $found[$id] = true;

                continue;
            }

            if ($scenario->id !== $id) {
                $result->errors[$relative] = \sprintf('the scenario "%s" belongs in %s.', $scenario->id, $directory->root->relative($directory->scenarioFile($scenario->workflow, $scenario->name)));

                continue;
            }

            $found[$id] = true;
            $sha256 = (string) hash_file('sha256', $file);
            $entry = $scenarios->entry($id);

            if (!$entry instanceof ScenarioIndexEntry) {
                $scenarios = $scenarios->with(new ScenarioIndexEntry($id, ScenarioOrigin::Human, $sha256, self::revisionOf($index, $scenario->workflow)));
            } elseif ($entry->sha256 !== $sha256) {
                $scenarios = $scenarios->with(new ScenarioIndexEntry($id, ScenarioOrigin::Human, $sha256, $entry->revision));
            }
        }

        foreach ($scenarios->entries as $entry) {
            if (!isset($found[$entry->id])) {
                $scenarios = $scenarios->without($entry->id);
            }
        }

        return $scenarios;
    }

    /**
     * @param list<string> $ids
     */
    private function review(DevToolsDirectory $directory, Index $index, ScenarioIndex $scenarios, array $ids, ScenarioGeneration $result): ScenarioIndex
    {
        foreach ($ids as $id) {
            $entry = $scenarios->entry($id);

            if (!$entry instanceof ScenarioIndexEntry) {
                $result->errors[$id] = 'no such scenario in .devtools/scenarios/.';

                continue;
            }

            $sha256 = (string) hash_file('sha256', $directory->scenarioFile($entry->workflow, $entry->name));
            $scenarios = $scenarios->with(new ScenarioIndexEntry($id, ScenarioOrigin::Human, $sha256, self::revisionOf($index, $entry->workflow)));
            $result->reviewed[] = $id;
        }

        return $scenarios;
    }

    /**
     * @return list<IndexEntry>
     */
    private function candidates(Index $index, ?WorkflowId $only): array
    {
        $candidates = array_values(array_filter($index->entries, static fn (IndexEntry $entry): bool => \in_array($entry->type->name, self::TYPES, true) && TrackingStatus::Orphaned !== $entry->status && (!$only instanceof WorkflowId || $entry->id->equals($only))));

        if ($only instanceof WorkflowId && [] === $candidates) {
            throw ScenariosUnavailable::noScenarioFor($only);
        }

        return $candidates;
    }

    private function generateFor(DevToolsDirectory $directory, IndexEntry $entry, TrackingDocument $workflow, ScenarioIndex $scenarios, bool $noAi, ScenarioGeneration $result): ScenarioIndex
    {
        $skeleton = ScenarioSkeleton::of($workflow);
        $existing = $scenarios->of($entry->id);
        $brief = null;

        if ([] === $existing && $skeleton instanceof Scenario) {
            $scenarios = $this->write($directory, $skeleton, ScenarioOrigin::Skeleton, $entry->updated, $scenarios, $result);
        }

        foreach ($existing as $scenario) {
            if ($scenario->revision >= $entry->updated) {
                continue;
            }

            if (ScenarioOrigin::Skeleton === $scenario->origin && $skeleton?->name === $scenario->name) {
                $scenarios = $this->write($directory, $skeleton, ScenarioOrigin::Skeleton, $entry->updated, $scenarios, $result);
            } elseif (ScenarioOrigin::Human === $scenario->origin) {
                $result->kept[$scenario->id] = \sprintf('modified by hand, never regenerated: review it, then scenarios:generate --reviewed=%s', $scenario->id);
            } else {
                $brief ??= \sprintf('%s was rewritten on %s: its scenarios are to review.', $entry->id->value, $entry->updated->format('Y-m-d'));
            }
        }

        if ([] === array_filter($scenarios->of($entry->id), static fn (ScenarioIndexEntry $scenario): bool => ScenarioOrigin::Skeleton !== $scenario->origin)) {
            $brief ??= $skeleton instanceof Scenario ? 'The workflow has only the skeleton DevTools derives from its facts: write the scenarios that say what it does.' : 'The workflow has no scenario yet.';
        }

        if ($noAi && [] === $scenarios->of($entry->id)) {
            $result->skipped[] = $entry->id->value;
        }

        $briefFile = $directory->path('pending/'.ScenarioBrief::fileName($entry->id, 'brief.xml'));

        if ($noAi || null === $brief) {
            // A brief nobody needs any more must not be answered.
            new Filesystem()->remove($briefFile);

            return $scenarios;
        }

        new XmlScenarioBriefStore($this->writer)->write($briefFile, $this->brief($directory, $entry, $workflow, $scenarios, $brief));
        $result->briefs[] = $entry->id->value;

        return $scenarios;
    }

    private function write(DevToolsDirectory $directory, Scenario $scenario, ScenarioOrigin $origin, \DateTimeImmutable $revision, ScenarioIndex $scenarios, ScenarioGeneration $result): ScenarioIndex
    {
        $content = new XmlScenarioStore()->serialize($scenario);
        $this->writer->write($directory->scenarioFile($scenario->workflow, $scenario->name), $content);
        $result->written[] = $scenario->id;

        return $scenarios->with(new ScenarioIndexEntry($scenario->id, $origin, hash('sha256', $content), $revision));
    }

    private function brief(DevToolsDirectory $directory, IndexEntry $entry, TrackingDocument $workflow, ScenarioIndex $scenarios, string $reason): ScenarioBrief
    {
        $route = static fn (EntryPoint $point): array => ['name' => $point->name, 'path' => $point->attributes['path'] ?? null, 'methods' => $point->attributes['methods'] ?? null];

        return new ScenarioBrief(
            $entry->id,
            $entry->updated,
            self::PROMPT_VERSION,
            Resources::path('prompts/scenario/'.self::PROMPT_VERSION.'.md'),
            $reason,
            $directory->pageRelativePath($entry->type, $entry->id, $entry->group),
            $directory->root->relative($directory->trackingFile($entry->type, $entry->id)),
            $this->knowledge($directory, $workflow),
            Resources::path('schemas/scenario.xsd'),
            array_map($route, [$workflow->main, ...$workflow->satellites]),
            ScenarioSkeleton::role($workflow->main->attributes['security'] ?? null),
            array_map(static fn (ScenarioIndexEntry $scenario): array => ['path' => $directory->root->relative($directory->scenarioFile($scenario->workflow, $scenario->name)), 'origin' => $scenario->origin->value], $scenarios->of($entry->id)),
            DevToolsDirectory::NAME.'/pending/'.ScenarioBrief::fileName($entry->id, 'draft.xml'),
        );
    }

    /**
     * The knowledge sheet of the stack the workflow is declared in, when the project has it.
     */
    private function knowledge(DevToolsDirectory $directory, TrackingDocument $workflow): ?string
    {
        if (!is_file($directory->stackFile())) {
            return null;
        }

        $declared = $workflow->main->declaredIn->path;
        $stacks = array_filter(new XmlStackStore()->read($directory->stackFile())->stacks, static fn (StackProfile $stack): bool => null !== $stack->knowledgeKey && ('.' === $stack->root || str_starts_with($declared, $stack->root.'/')));
        usort($stacks, static fn (StackProfile $a, StackProfile $b): int => \strlen($b->root) <=> \strlen($a->root));
        $file = DevToolsDirectory::NAME.'/knowledge/'.($stacks[0]->knowledgeKey ?? '').'.md';

        return [] !== $stacks && is_file($directory->root->absolute($file)) ? $file : null;
    }

    private static function revisionOf(Index $index, WorkflowId $workflow): \DateTimeImmutable
    {
        return array_find($index->entries, static fn (IndexEntry $entry): bool => $entry->id->equals($workflow))->updated ?? $index->scannedAt;
    }
}
