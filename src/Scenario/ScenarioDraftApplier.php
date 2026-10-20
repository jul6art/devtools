<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The scenarios Claude wrote (ADR-0020), applied by `workflows:apply`: a draft passes the schema, names its
 * own workflow, still documented and not rewritten since its brief, goes only to routes the project has, and
 * rewrites no scenario a human touched — or nothing of it is written.
 */
final readonly class ScenarioDraftApplier
{
    public function __construct(private AtomicFileWriter $writer = new AtomicFileWriter())
    {
    }

    /**
     * @return array{accepted: list<string>, refused: array<string, list<string>>} by workflow
     */
    public function apply(DevToolsDirectory $directory, WorkflowTypeRegistry $types): array
    {
        $drafts = glob($directory->path('pending/scenario.*.draft.xml')) ?: [];
        $outcome = ['accepted' => [], 'refused' => []];

        if ([] === $drafts || !is_file($directory->indexFile())) {
            return $outcome;
        }

        $index = new XmlIndexStore($types)->read($directory->indexFile());
        $routes = $this->routes($directory, $types);
        $indexStore = new XmlScenarioIndexStore($this->writer);
        $scenarios = $indexStore->read($directory->scenarioIndexFile());

        foreach ($drafts as $draft) {
            $key = 'scenario.'.substr(basename($draft), \strlen('scenario.'), -\strlen('.draft.xml'));
            $brief = $directory->path('pending/'.substr(basename($draft), 0, -\strlen('draft.xml')).'brief.xml');

            try {
                [$errors, $scenarios] = $this->applyOne($directory, $draft, $brief, $index->entries, $routes, $scenarios);
            } catch (InvalidXml $invalid) {
                $errors = [$invalid->getMessage()];
            }

            if ([] === $errors) {
                new Filesystem()->remove([$draft, $brief]);
                $outcome['accepted'][] = $key;
            } else {
                $outcome['refused'][$key] = $errors;
            }
        }

        if ([] !== $outcome['accepted']) {
            $indexStore->write($directory->scenarioIndexFile(), $scenarios);
        }

        return $outcome;
    }

    /**
     * @param list<IndexEntry> $workflows
     * @param list<string>     $routes
     *
     * @return array{list<string>, ScenarioIndex} the broken rules, and the index with what was written
     */
    private function applyOne(DevToolsDirectory $directory, string $draft, string $briefFile, array $workflows, array $routes, ScenarioIndex $scenarios): array
    {
        if (!is_file($briefFile)) {
            return [['The draft has no brief: run scenarios:generate for one.'], $scenarios];
        }

        $brief = new XmlScenarioBriefStore()->read($briefFile);
        $written = new XmlScenarioStore()->readDraft($draft);
        $workflow = array_find($workflows, static fn (IndexEntry $entry): bool => $entry->id->equals($brief->workflow));
        $errors = [];

        if (!$workflow instanceof IndexEntry || TrackingStatus::Orphaned === $workflow->status) {
            return [[\sprintf('The workflow "%s" is no longer documented: discard the draft.', $brief->workflow->value)], $scenarios];
        }

        if ($workflow->updated != $brief->revision) {
            $errors[] = \sprintf('The draft is outdated: %s was rewritten after its brief. Run scenarios:generate for a new one.', $brief->workflow->value);
        }

        $names = [];

        foreach ($written as $scenario) {
            if (!$scenario->workflow->equals($brief->workflow)) {
                $errors[] = \sprintf('"%s" belongs to another workflow: this draft writes the scenarios of %s only.', $scenario->id, $brief->workflow->value);
            }

            if (isset($names[$scenario->id])) {
                $errors[] = \sprintf('"%s" is written twice.', $scenario->id);
            }

            $names[$scenario->id] = true;

            foreach (array_diff($scenario->routes(), $routes) as $unknown) {
                $errors[] = \sprintf('"%s" names the route "%s", which this project does not have.', $scenario->id, $unknown);
            }

            if (self::isHumans($directory->scenarioFile($scenario->workflow, $scenario->name), $scenarios->entry($scenario->id))) {
                $errors[] = \sprintf('"%s" was written or modified by hand: DevTools never rewrites it. Give the scenario another name.', $scenario->id);
            }
        }

        if ([] !== $errors) {
            return [$errors, $scenarios];
        }

        $store = new XmlScenarioStore();

        foreach ($written as $scenario) {
            $content = $store->serialize($scenario);
            $this->writer->write($directory->scenarioFile($scenario->workflow, $scenario->name), $content);
            $scenarios = $scenarios->with(new ScenarioIndexEntry($scenario->id, ScenarioOrigin::Claude, hash('sha256', $content), $brief->revision));
        }

        return [[], $scenarios];
    }

    /**
     * A file a human wrote, or touched since DevTools recorded it — even if `scenarios:generate` has not seen it
     * yet.
     */
    private static function isHumans(string $file, ?ScenarioIndexEntry $entry): bool
    {
        return is_file($file) && (!$entry instanceof ScenarioIndexEntry || !$entry->isOwned() || hash_file('sha256', $file) !== $entry->sha256);
    }

    /**
     * Every route name the project has: the entry points of its route workflows, satellites included.
     *
     * @return list<string>
     */
    private function routes(DevToolsDirectory $directory, WorkflowTypeRegistry $types): array
    {
        $store = new XmlTrackingStore($types);
        $routes = [];

        foreach (glob($directory->path('workflows/routes/*.xml')) ?: [] as $file) {
            $document = $store->read($file);

            foreach ([$document->main, ...$document->satellites] as $point) {
                $routes[] = $point->name;
            }
        }

        return $routes;
    }
}
