<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection;

use Jul6Art\DevTools\Ai\GroupBrief;
use Jul6Art\DevTools\Ai\PageBrief;
use Jul6Art\DevTools\Ai\PageDraft;
use Jul6Art\DevTools\Ai\XmlGroupBriefStore;
use Jul6Art\DevTools\Ai\XmlPageBriefStore;
use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Config\InvalidConfig;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Adapter\AdapterInterface;
use Jul6Art\DevTools\Inspection\Adapter\Claude\Discovery;
use Jul6Art\DevTools\Inspection\Adapter\Claude\DiscoveryBrief;
use Jul6Art\DevTools\Inspection\Adapter\Claude\XmlDiscoveryBriefStore;
use Jul6Art\DevTools\Inspection\Diff\WorkflowChange;
use Jul6Art\DevTools\Inspection\Diff\WorkflowDiffer;
use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\Freshness\FileHasher;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessDecision;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessResolver;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Freshness\GitState;
use Jul6Art\DevTools\Inspection\Freshness\ReasonKind;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Serialization\ModelXmlSerializer;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdCollision;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Inspection\Progress\NullProgressReporter;
use Jul6Art\DevTools\Inspection\Progress\ProgressReporter;
use Jul6Art\DevTools\Project\ProjectLock;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\GraphRenderer;
use Jul6Art\DevTools\Rendering\GroupPageRenderer;
use Jul6Art\DevTools\Rendering\GroupPageSection;
use Jul6Art\DevTools\Rendering\MarkdownWriter;
use Jul6Art\DevTools\Rendering\MenuRenderer;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Rendering\PageRenderer;
use Jul6Art\DevTools\Rendering\PageSection;
use Jul6Art\DevTools\Rendering\ParsedPage;
use Jul6Art\DevTools\Rendering\RenderingContext;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeBrief;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeCanvas;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeProvider;
use Jul6Art\DevTools\Stack\Knowledge\XmlKnowledgeBriefStore;
use Jul6Art\DevTools\Stack\StackDetectionFailed;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Stack\StackDocument;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\FilesToWorkflows;
use Jul6Art\DevTools\Tracking\Generation;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\Initializer;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\VcsState;
use Jul6Art\DevTools\Tracking\XmlFilesToWorkflowsStore;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Jul6Art\DevTools\Version;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `workflows:inspect` from end to end (specs § 4.3, ADR-0009): stack, entry points, graph, pages, tracking
 * files, index, menu, report.
 *
 * A workflow is rewritten only when freshness says so (ADR-0010): a re-run on an unchanged project
 * modifies no file outside `reports/`.
 */
final readonly class InspectionPipeline
{
    public function __construct(
        private AdapterResolver $adapters = new AdapterResolver(),
        private Clock $clock = new SystemClock(),
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private GitClient $git = new GitClient(),
        private FileHasher $hasher = new FileHasher(),
        private WorkflowDiffer $differ = new WorkflowDiffer(),
    ) {
    }

    public function run(InspectionOptions $options, ProgressReporter $progress = new NullProgressReporter()): InspectionReport
    {
        $report = new InspectionReport($this->clock->now());
        $started = microtime(true);
        $lock = null;

        try {
            $directory = new DevToolsDirectory(new ProjectRoot($options->path), $options->docs);

            if (!$options->dryRun) {
                new Initializer($this->writer)->initialize($directory);
                $lock = ProjectLock::acquire($directory->path());
            }

            $this->inspect($directory, $options, $report, $progress);
        } catch (InvalidXml|InvalidConfig|InvalidModel|WorkflowIdCollision|StackDetectionFailed|\InvalidArgumentException|\RuntimeException $error) {
            $report->errors[] = $error->getMessage();
        } finally {
            $progress->finish();
            $lock?->release();
        }

        $report->seconds = microtime(true) - $started;
        $report->filesHashed = $this->hasher->hashes();
        $report->gitProcesses = $this->git->processes();
        $report->peakMemoryBytes = memory_get_peak_usage(true);

        if (!$options->dryRun && isset($directory) && is_dir($directory->path())) {
            $report->path = $directory->path('reports/inspect-'.$report->startedAt->format('Y-m-d-His').'.md');
            $this->writer->write($report->path, $report->toMarkdown());
        }

        return $report;
    }

    private function inspect(DevToolsDirectory $directory, InspectionOptions $options, InspectionReport $report, ProgressReporter $progress): void
    {
        $progress->stage('Configuration et stack');
        $config = new XmlConfigReader()->read($directory->configFile());
        $types = new WorkflowTypeRegistry($config->customTypes);

        if (null !== $options->only) {
            $types->get($options->only);
        }

        $stackStore = new XmlStackStore($this->writer);
        $stacks = new StackDetector()->detect($directory->root, $config, is_file($directory->stackFile()) ? $stackStore->read($directory->stackFile()) : null);
        $report->projectName = $stacks->projectName;

        if (!$options->dryRun) {
            $stackStore->write($directory->stackFile(), $stacks);
        }

        [$workflows, $uncovered, $stackOf, $discoveries] = $this->workflowsOf($directory->root, $stacks, $config, $report, $progress);

        if ([] !== $report->errors) {
            return;
        }

        $progress->stage('Fraîcheur');
        $now = $this->clock->now();
        $this->hasher->reset();
        $tracking = new XmlTrackingStore($types, $this->writer);
        $previous = $this->previousTracking($directory, $tracking);
        [$vcs, $candidates] = $this->changes($directory->root, $previous, $options, $directory->docs);
        $report->decisions = $decisions = new FreshnessResolver($this->hasher)->resolve($directory->root, $workflows, $previous, $candidates, $options->force, $options->forceAll);

        foreach (array_diff($options->force, array_map(static fn (Workflow $workflow): string => $workflow->id->value, $workflows)) as $unknown) {
            $report->warnings[] = \sprintf('--force=%s names no workflow of this project; nothing was forced for it.', $unknown);
        }
        $context = RenderingContext::of($workflows);
        $documents = [];
        $written = static fn (Workflow|TrackingDocument $workflow): bool => !$options->dryRun && (null === $options->only || $options->only === $workflow->type->name);

        $progress->stage('Rendu des pages', \count($workflows));

        foreach ($workflows as $workflow) {
            $progress->advance($workflow->id->value);
            $old = $previous[$workflow->id->value] ?? null;
            $decision = $decisions[$workflow->id->value];
            // ⚠️ A page that changes directory has changed, whatever the freshness says of its code: the
            // grouping of ADR-0045 moved it, and leaving it out would keep the old file for ever — the
            // index points at the new one, so nothing would ever delete it.
            $moved = $old instanceof TrackingDocument && $old->group?->directory !== $workflow->group?->directory;

            $report->entryFiles[$workflow->id->value] = $workflow->main->declaredIn->path;

            if ($old instanceof TrackingDocument && GenerationMode::NoAi === $old->generated->mode) {
                $report->neverWritten[] = $workflow->id->value;
            }

            // ADR-0046: the two versions are in hand here and nowhere else — comparing them anywhere else
            // would mean parsing the code or reading the tracking files a second time.
            if ($old instanceof TrackingDocument) {
                $changes = $this->differ->between($old, $workflow);

                if ([] !== $changes) {
                    $report->changes[$workflow->id->value] = $changes;

                    if (null !== $old->vcs->commit) {
                        $report->writtenFrom[$workflow->id->value] = $old->vcs->commit;
                    }
                }
            }

            // ⚠️ ADR-0047: accepting one fact must not rewrite the page of a workflow nobody accepted.
            // Its tracking file keeps the old state, so it stays in drift until someone decides on it.
            if ([] !== $options->restrictTo && !\in_array($workflow->id->value, $options->restrictTo, true)) {
                if ($old instanceof TrackingDocument) {
                    $documents[] = $old;
                    $report->record($workflow->type->name, 'unchanged');
                }

                continue;
            }

            if (DecisionKind::Keep === $decision->kind && !$moved && $old instanceof TrackingDocument) {
                $documents[] = $old;
                $report->record($workflow->type->name, 'unchanged');

                continue;
            }

            $history = match (true) {
                DecisionKind::Create === $decision->kind => [new Revision($now, $vcs->commit, 'initial')],
                DecisionKind::ManualStale === $decision->kind => $old->history ?? [],
                DecisionKind::Keep === $decision->kind => [...($old->history ?? []), new Revision($now, $vcs->commit, 'regroupement : la page change de dossier')],
                default => [...($old->history ?? []), new Revision($now, $vcs->commit, self::why($decision, $report->changes[$workflow->id->value] ?? []))],
            };
            $document = $this->trackingOf($directory->root, $workflow, $old, $now, $vcs, $history);
            $documents[] = $document;

            if (DecisionKind::ManualStale === $decision->kind) {
                $report->warnings[] = \sprintf('%s is marked manual and its code changed (%s): its page was not rewritten.', $workflow->id, $decision->describe());
                $report->record($workflow->type->name, 'unchanged');

                if ($written($workflow)) {
                    $tracking->write($directory->trackingFile($workflow->type, $workflow->id), $document);
                }

                continue;
            }

            $report->record($workflow->type->name, DecisionKind::Create === $decision->kind ? 'created' : 'updated');

            if ($written($workflow)) {
                $pageFile = $directory->pageFile($workflow->type, $workflow->id, $workflow->group?->directory);

                // The page moved: what Claude wrote is read from where it was, and the old file goes.
                $from = $moved && $old instanceof TrackingDocument ? $directory->pageFile($workflow->type, $workflow->id, $old->group?->directory) : $pageFile;
                $page = new PageRenderer()->render($workflow, $document->history, $context, self::writtenPage($from));

                if ($from !== $pageFile) {
                    new Filesystem()->remove([$from]);
                }

                if ($this->writer->write($pageFile, $page)) {
                    ++$report->pagesWritten;
                    $report->bytesWritten += \strlen($page);
                }

                $tracking->write($directory->trackingFile($workflow->type, $workflow->id), $document);
            }
        }

        // An entry point that disappeared: its page is kept and flagged; only --prune deletes it.
        $orphans = [];

        foreach ($previous as $id => $old) {
            if (isset($decisions[$id]) && DecisionKind::Orphan !== $decisions[$id]->kind) {
                continue;
            }

            $report->record($old->type->name, 'orphaned');

            // --only names the type being written: --prune must not delete the pages of the others.
            if ($options->prune && (null === $options->only || $options->only === $old->type->name)) {
                if (!$options->dryRun) {
                    new Filesystem()->remove([$directory->pageFile($old->type, $old->id, $old->group?->directory), $directory->trackingFile($old->type, $old->id)]);
                }

                continue;
            }

            $orphans[] = $orphan = TrackingStatus::Orphaned === $old->status ? $old : new TrackingDocument($old->id, $old->type, $old->title, $old->generated, $old->vcs, $old->main, $old->satellites, $old->files, $old->tests, $old->packages, $old->dependsOn, $old->confidence, $old->producer, TrackingStatus::Orphaned, $old->history, $old->decisions, $old->mechanisms, $old->group);

            if ($written($orphan)) {
                $tracking->write($directory->trackingFile($orphan->type, $orphan->id), $orphan);
            }
        }

        if (!$options->dryRun) {
            $this->writeGroupPages($directory, $workflows, $orphans, $options, $report);
        }

        $progress->finish();

        if ($options->dryRun) {
            return;
        }

        $progress->stage('Connaissances et briefs');
        $knowledge = $this->knowledge($directory, $stacks, $config, $options, $report);

        if (!$options->noAi) {
            $this->writeDiscoveryBriefs($directory, $config, $discoveries, $knowledge, $report);
        }
        $pending = $options->noAi ? [] : $this->writeBriefs($directory, $config, $knowledge, $stackOf, $workflows, $decisions, $documents, $types, $options, $report);

        $progress->stage('Index, menu et graphe');
        $indexStore = new XmlIndexStore($types, $this->writer);
        $index = Index::fromTracking($now, $vcs, [...$documents, ...$orphans], $directory->docs);

        // The index keeps its date and commit while its entries do not change. Recording HEAD instead would
        // make committing the documentation change the documentation, forever.
        if (is_file($directory->indexFile())) {
            $previousIndex = $indexStore->read($directory->indexFile());
            $unchanged = new Index($previousIndex->scannedAt, $previousIndex->vcs, $index->entries, $directory->docs, $index->groups);

            if ($indexStore->serialize($unchanged) === $indexStore->serialize($previousIndex)) {
                $index = $previousIndex;
            }
        }

        $indexStore->write($directory->indexFile(), $index);
        new XmlFilesToWorkflowsStore($this->writer)->write($directory->filesToWorkflowsFile(), FilesToWorkflows::fromTracking($documents));
        $this->writer->write($directory->menuFile(), new MenuRenderer()->render($stacks, $index, $uncovered, $pending, $config->customTypes, $directory->docs));
        $this->writer->write($directory->overviewFile(), new GraphRenderer()->render($workflows));
    }

    /**
     * The version-control state, and the files git reports as changed since the documentation was written
     * — or null when every file must be hashed: no git, no previous commit found (a rebase, a copy), or
     * `--since`. At most four git processes, whatever the size of the project.
     *
     * @param array<string, TrackingDocument> $previous
     *
     * @return array{VcsState, list<string>|null}
     */
    private function changes(ProjectRoot $root, array $previous, InspectionOptions $options, string $docs): array
    {
        $state = $this->git->state($root);

        if (!$state instanceof GitState) {
            return [VcsState::none(), null];
        }

        $workingTree = $this->git->workingTree($root, $state, [DevToolsDirectory::NAME, $docs]);
        $vcs = new VcsState($state->commit, $state->branch, [] !== $workingTree);
        $recorded = array_values(array_unique(array_filter(array_map(static fn (TrackingDocument $document): ?string => $document->vcs->commit, $previous))));

        if (null !== $options->since || [] === $recorded) {
            return [$vcs, null];
        }

        // The oldest recorded commit covers every newer one: one diff for all workflows.
        $base = $this->oldest($root, $recorded);

        if (null === $base) {
            return [$vcs, null];
        }

        return [$vcs, array_values(array_unique([...$this->git->changedSince($root, $base), ...$workingTree]))];
    }

    /**
     * @param non-empty-list<string> $commits
     */
    private function oldest(ProjectRoot $root, array $commits): ?string
    {
        // Workflows are rewritten together, so documents almost always share one commit; when they do not,
        // the first recorded one is checked, and a missing commit means hashing everything.
        sort($commits, \SORT_STRING);
        $commit = $commits[0];

        return 1 === \count($commits) && $this->git->commitExists($root, $commit) ? $commit : null;
    }

    /**
     * Why a page is rewritten, in the words of ADR-0046 when a fact moved: « modified decision
     * App\Entity\User::email » says more than « files changed: src/Entity/User.php », which names a file
     * holding twenty other things.
     *
     * @param list<WorkflowChange> $changes
     */
    private static function why(FreshnessDecision $decision, array $changes): string
    {
        if ([] === $changes) {
            return $decision->describe();
        }

        $first = self::describe($changes[0]);

        return 1 === \count($changes) ? $first : \sprintf('%s (+%d)', $first, \count($changes) - 1);
    }

    private static function describe(WorkflowChange $change): string
    {
        return trim(\sprintf(
            '%s %s %s%s',
            $change->nature->value,
            $change->subject->value,
            $change->target,
            null === $change->before && null === $change->after ? '' : \sprintf(': %s → %s', $change->before ?? '—', $change->after ?? '—'),
        ));
    }

    /**
     * The files of the workflow that moved since its last revision, from the freshness decision.
     *
     * @return list<array{path: string, change: string}>
     */
    private static function changedFiles(?FreshnessDecision $decision): array
    {
        $changes = [];

        foreach ($decision->reasons ?? [] as $reason) {
            $kind = match ($reason->kind) {
                ReasonKind::FilesAdded => 'added',
                ReasonKind::FilesRemoved => 'removed',
                ReasonKind::FilesChanged => 'changed',
                default => null,
            };

            if (null === $kind) {
                continue;
            }

            foreach ($reason->paths as $path) {
                $changes[] = ['path' => $path, 'change' => $kind];
            }
        }

        return $changes;
    }

    /**
     * @return array{list<Workflow>, list<FileRef>, array<string, StackProfile>, list<array{StackProfile, list<string>}>} workflows, uncovered files, the stack of each workflow, and the discoveries Claude has to make
     */
    private function workflowsOf(ProjectRoot $root, StackDocument $stacks, Config $config, InspectionReport $report, ProgressReporter $progress): array
    {
        $workflows = [];
        $uncovered = [];
        $stackOf = [];
        $discoveries = [];
        $inspected = 0;
        $progress->stage('Extraction', \count($stacks->stacks));

        foreach ($stacks->stacks as $stack) {
            $label = self::label($stack);
            $progress->advance($label);
            $adapter = $this->adapters->for($stack->adapter);

            if (!$adapter instanceof AdapterInterface) {
                $report->warnings[] = \sprintf('%s is not documented: no adapter "%s" in this version of DevTools.', $label, $stack->adapter);

                continue;
            }

            ++$inspected;
            $extractor = new PhpReferenceExtractor();
            $found = $adapter->extract($root, $stack, $config, $extractor);
            $report->consoleCalls += $found->consoleCalls;

            if (null !== $found->fallbackCause) {
                $report->fallbacks[] = ['stack' => $label, 'cause' => $found->fallbackCause];
            }

            if (null !== $found->discovery) {
                $discoveries[] = [$stack, $found->discovery];
            }

            // The Claude path hands over complete workflows; native adapters hand over entry points.
            if (null !== $found->workflows) {
                [$stackWorkflows, $stackUncovered, $buildWarnings] = [$found->workflows, $found->uncovered, []];
            } else {
                $built = new WorkflowBuilder($config)->build($root, $stack, $found->candidates, $found->templateDirectories, $extractor, $found->mechanisms);
                [$stackWorkflows, $stackUncovered, $buildWarnings] = [$built->result->workflows, $built->result->uncovered, $built->warnings];
            }

            $report->stacks[] = [
                'stack' => $label,
                'adapter' => $adapter->name(),
                'confidence' => null === $found->fallbackCause && null === $found->workflows ? 'high' : 'medium',
                'uncovered' => \count($stackUncovered),
            ];
            $report->warnings = [...$report->warnings, ...$found->warnings, ...$buildWarnings];
            $workflows = [...$workflows, ...$stackWorkflows];

            foreach ($stackWorkflows as $workflow) {
                $stackOf[$workflow->id->value] = $stack;
            }
            $uncovered = [...$uncovered, ...$stackUncovered];

            $report->filesParsed += $extractor->parsedFiles();

            // The model of this stack is built: what follows reads files, never syntax trees. On a real
            // repository they are hundreds of megabytes, and the bundle runs inside a booted kernel.
            $extractor->release();
        }

        $progress->finish();

        if (0 === $inspected) {
            $report->errors[] = 'No stack of this project has an adapter in this version of DevTools; nothing was documented.';
        }

        return [$workflows, $uncovered, $stackOf, $discoveries];
    }

    /**
     * @param list<Revision> $history
     */
    private function trackingOf(ProjectRoot $root, Workflow $workflow, ?TrackingDocument $old, \DateTimeImmutable $now, VcsState $vcs, array $history): TrackingDocument
    {
        return new TrackingDocument(
            id: $workflow->id,
            type: $workflow->type,
            title: $workflow->title,
            generated: new Generation($now, 'devtools '.Version::current(), GenerationMode::NoAi),
            vcs: $vcs,
            main: $workflow->main,
            satellites: $workflow->satellites,
            files: array_map(fn (FileRef $file): TrackedFile => new TrackedFile($file, $this->hasher->hash($root->absolute($file)) ?? throw new \RuntimeException(\sprintf('The file "%s" of a workflow cannot be read.', $file->path))), $workflow->files),
            tests: $workflow->tests,
            packages: $workflow->packages,
            dependsOn: $workflow->dependsOn,
            confidence: $workflow->confidence,
            producer: $workflow->source,
            status: TrackingStatus::Manual === $old?->status ? TrackingStatus::Manual : TrackingStatus::Fresh,
            history: $history,
            decisions: $workflow->decisions,
            mechanisms: $workflow->mechanisms,
            group: $workflow->group,
        );
    }

    /**
     * @return array<string, TrackingDocument>
     */
    private function previousTracking(DevToolsDirectory $directory, XmlTrackingStore $tracking): array
    {
        $documents = [];

        foreach (glob($directory->path('workflows/*/*.xml')) ?: [] as $file) {
            $document = $tracking->read($file);
            $documents[$document->id->value] = $document;
        }

        ksort($documents, \SORT_STRING);

        return $documents;
    }

    /**
     * A brief for every page Claude has to (re)write: created or rewritten by this run, or never written.
     * Briefs are recomputed on each run; drafts are left for `workflows:apply` to judge.
     *
     * @param array<string, string|null>       $knowledge knowledge key => its file, null while Claude has not written it
     * @param array<string, StackProfile>      $stackOf   workflow identifier => its stack
     * @param list<Workflow>                   $workflows
     * @param array<string, FreshnessDecision> $decisions
     * @param list<TrackingDocument>           $documents
     *
     * @return list<WorkflowId> the workflows waiting for a writing
     */
    private function writeBriefs(DevToolsDirectory $directory, Config $config, array $knowledge, array $stackOf, array $workflows, array $decisions, array $documents, WorkflowTypeRegistry $types, InspectionOptions $options, InspectionReport $report): array
    {
        new Filesystem()->remove([...glob($directory->path('pending/page.*.brief.xml')) ?: [], ...glob($directory->path('pending/page.*.model.xml')) ?: [], ...glob($directory->path('pending/group.*.brief.xml')) ?: []]);

        $byId = [];

        foreach ($documents as $document) {
            $byId[$document->id->value] = $document;
        }

        $briefs = new XmlPageBriefStore($types, $this->writer);
        $models = new ModelXmlSerializer($types);
        $pending = [];

        foreach ($workflows as $workflow) {
            $document = $byId[$workflow->id->value] ?? null;
            $decision = $decisions[$workflow->id->value];
            $changedNow = \in_array($decision->kind, [DecisionKind::Create, DecisionKind::Rewrite], true);

            if (!$document instanceof TrackingDocument || DecisionKind::ManualStale === $decision->kind || (!$changedNow && GenerationMode::Ai === $document->generated->mode) || (null !== $options->only && $options->only !== $workflow->type->name)) {
                continue;
            }

            $stack = $stackOf[$workflow->id->value];
            $knowledgePath = null === $stack->knowledgeKey ? null : $knowledge[$stack->knowledgeKey] ?? null;

            // A page is not written without knowing how its stack works: its brief waits for the knowledge.
            if (null !== $stack->knowledgeKey && null === $knowledgePath) {
                continue;
            }

            $modelPath = DevToolsDirectory::NAME.'/pending/'.PageBrief::fileName($workflow->id, 'model.xml');
            $this->writer->write($directory->root->absolute($modelPath), $models->serialize(new InspectionResult($stack->knowledgeKey ?? $stack->language, [$workflow])));

            $briefPath = $directory->path('pending/'.PageBrief::fileName($workflow->id, 'brief.xml'));
            $briefs->write($briefPath, new PageBrief(
                workflow: $workflow->id,
                type: $workflow->type,
                revision: $document->lastRevision()->at,
                // The last revision is still DevTools' own until Claude writes it: the writing completes it,
                // however many inspections ran in between (a brief must not change from one run to the next).
                amend: GenerationMode::NoAi === $document->generated->mode,
                promptVersion: 'page/2',
                promptPath: Resources::path('prompts/page/v2.md'),
                language: $options->language ?? $config->language($options->fallbackLanguage),
                modelPath: $modelPath,
                pagePath: $directory->pageRelativePath($workflow->type, $workflow->id, $workflow->group?->directory),
                knowledgePath: $knowledgePath,
                reasons: [$document->lastRevision()->reason],
                sections: PageDraft::expectedSections(),
                changes: self::changedFiles($decisions[$workflow->id->value] ?? null),
                draftPath: DevToolsDirectory::NAME.'/pending/'.PageBrief::fileName($workflow->id, 'draft.md'),
                facts: array_map(self::describe(...), $report->changes[$workflow->id->value] ?? []),
            ));
            self::countBrief($report, $briefPath, $directory->root->absolute($modelPath));
            $pending[] = $workflow->id;
        }

        $this->writeGroupBriefs($directory, $config, $workflows, $options, $report);

        return $pending;
    }

    /**
     * One brief per group whose summary is still to write (ADR-0045).
     *
     * ⚠️ Only when the summary is missing: the facts of a group page are rewritten by every inspection that
     * touches one of its routes, and asking Claude again each time would make the cheapest page of the
     * documentation the most expensive one.
     *
     * @param list<Workflow> $workflows
     */
    private function writeGroupBriefs(DevToolsDirectory $directory, Config $config, array $workflows, InspectionOptions $options, InspectionReport $report): void
    {
        $groups = [];

        foreach ($workflows as $workflow) {
            if (null === $workflow->group || (null !== $options->only && $options->only !== $workflow->type->name)) {
                continue;
            }

            $groups[$workflow->type->name."\0".$workflow->group->directory][] = $workflow;
        }

        $briefs = new XmlGroupBriefStore();

        foreach ($groups as $members) {
            $group = $members[0]->group ?? throw new \LogicException('A grouped workflow was collected without its group.');
            $type = $members[0]->type;
            $pagePath = $directory->docs.'/'.DevToolsDirectory::groupPageInDocs($type, $group->directory);

            if (self::writtenSummary($directory->root->absolute($pagePath)) instanceof ParsedPage) {
                continue;
            }

            $briefPath = $directory->path('pending/'.GroupBrief::fileName($type, $group->directory, 'brief.xml'));
            $briefs->write($briefPath, new GroupBrief(
                type: $type,
                directory: $group->directory,
                title: $group->title,
                promptVersion: 'group/1',
                promptPath: Resources::path('prompts/group/v1.md'),
                language: $options->language ?? $config->language($options->fallbackLanguage),
                pagePath: $pagePath,
                routes: array_map(static fn (Workflow $member): array => [
                    'route' => $member->main->name,
                    'title' => $member->title,
                    'page' => $directory->docs.'/'.DevToolsDirectory::pageInDocs($member->type, $member->id, $group->directory),
                ], $members),
                draftPath: DevToolsDirectory::NAME.'/pending/'.GroupBrief::fileName($type, $group->directory, 'draft.md'),
            ));
            self::countBrief($report, $briefPath);
        }
    }

    /**
     * The knowledge of every stack: the project's, or the one DevTools ships (copied on first use), or a brief
     * asking Claude to write it.
     *
     * @return array<string, string|null> knowledge key => file relative to the project, null while missing
     */
    private function knowledge(DevToolsDirectory $directory, StackDocument $stacks, Config $config, InspectionOptions $options, InspectionReport $report): array
    {
        if (!$options->noAi) {
            new Filesystem()->remove(glob($directory->path('pending/knowledge.*.brief.xml')) ?: []);
        }

        $library = KnowledgeLibrary::forProject($directory->root, $config, $this->writer);
        $report->knowledgeLibrary = $library->path;
        $provider = new KnowledgeProvider($this->writer, $library);
        $knowledge = [];

        foreach ($stacks->stacks as $stack) {
            if (null === $stack->knowledgeKey || \array_key_exists($stack->knowledgeKey, $knowledge)) {
                continue;
            }

            $knowledge[$stack->knowledgeKey] = $provider->provide($directory, $stack->knowledgeKey);

            if (null === $knowledge[$stack->knowledgeKey] && !$options->noAi) {
                $briefPath = $directory->path('pending/'.KnowledgeBrief::fileName($stack->knowledgeKey, 'brief.xml'));
                new XmlKnowledgeBriefStore($this->writer)->write($briefPath, new KnowledgeBrief(
                    key: $stack->knowledgeKey,
                    language: $stack->language,
                    framework: $stack->framework,
                    version: $stack->version,
                    canvasPath: KnowledgeCanvas::path(),
                    promptPath: Resources::path('prompts/knowledge/v1.md'),
                    draftPath: DevToolsDirectory::NAME.'/pending/'.KnowledgeBrief::fileName($stack->knowledgeKey, 'draft.md'),
                ));
                self::countBrief($report, $briefPath);
            }
        }

        return $knowledge;
    }

    /**
     * A brief for every stack of the Claude path that needs a discovery — whole, or limited to new files — once
     * its knowledge exists.
     *
     * @param list<array{StackProfile, list<string>}> $discoveries
     * @param array<string, string|null>              $knowledge
     */
    private function writeDiscoveryBriefs(DevToolsDirectory $directory, Config $config, array $discoveries, array $knowledge, InspectionReport $report): void
    {
        new Filesystem()->remove(glob($directory->path('pending/discovery.*.brief.xml')) ?: []);

        foreach ($discoveries as [$stack, $limitedTo]) {
            $knowledgePath = null === $stack->knowledgeKey ? null : $knowledge[$stack->knowledgeKey] ?? null;

            if (null !== $stack->knowledgeKey && null === $knowledgePath) {
                continue;
            }

            $slug = Discovery::slug($stack);
            $briefPath = $directory->path('pending/'.DiscoveryBrief::fileName($slug, 'brief.xml'));
            new XmlDiscoveryBriefStore($this->writer)->write($briefPath, new DiscoveryBrief(
                slug: $slug,
                root: $stack->root,
                sourceDirectories: $stack->sourceDirs,
                excludes: $stack->excludedDirs,
                knowledgePath: $knowledgePath,
                types: array_map(static fn (WorkflowType $type): string => $type->name, new WorkflowTypeRegistry($config->customTypes)->all()),
                schemaPath: Resources::path('schemas/inspection-model.xsd'),
                promptPath: Resources::path('prompts/discovery/v1.md'),
                limitedTo: $limitedTo,
                draftPath: DevToolsDirectory::NAME.'/pending/'.DiscoveryBrief::fileName($slug, 'draft.xml'),
            ));
            self::countBrief($report, $briefPath);
        }
    }

    /**
     * A brief counts once written, with the bytes it really takes: the estimate of what the redaction will
     * cost is derived from these, never guessed (ADR-0042).
     */
    private static function countBrief(InspectionReport $report, string ...$paths): void
    {
        foreach ($paths as $path) {
            ++$report->briefsWritten;
            $report->briefBytes += is_file($path) ? (int) filesize($path) : 0;
        }
    }

    /**
     * The page of every group, written after the pages it lists (ADR-0045): the table of its routes, the
     * state machine of the resource, and the summary Claude wrote for it, kept across factual rewrites.
     *
     * A group whose last workflow disappeared loses its page with `--prune`, as its routes do: a directory
     * holding nothing but a README is a link the menu no longer makes.
     *
     * @param list<Workflow>         $workflows
     * @param list<TrackingDocument> $orphans
     */
    private function writeGroupPages(DevToolsDirectory $directory, array $workflows, array $orphans, InspectionOptions $options, InspectionReport $report): void
    {
        $groups = [];

        foreach ($workflows as $workflow) {
            if (null === $workflow->group || (null !== $options->only && $options->only !== $workflow->type->name)) {
                continue;
            }

            $groups[$workflow->type->name."\0".$workflow->group->directory][] = $workflow;
        }

        foreach ($groups as $members) {
            $group = $members[0]->group ?? throw new \LogicException('A grouped workflow was collected without its group.');
            $file = $directory->groupPageFile($members[0]->type, $group->directory);
            $page = new GroupPageRenderer()->render($group, $members, self::writtenSummary($file));

            if ($this->writer->write($file, $page)) {
                ++$report->pagesWritten;
                $report->bytesWritten += \strlen($page);
            }
        }

        if (!$options->prune) {
            return;
        }

        $dead = [];

        foreach ($orphans as $orphan) {
            if (null !== $orphan->group && (null === $options->only || $options->only === $orphan->type->name)) {
                $dead[$orphan->type->name."\0".$orphan->group->directory] = $directory->groupPageFile($orphan->type, $orphan->group->directory);
            }
        }

        new Filesystem()->remove(array_values(array_diff_key($dead, $groups)));
    }

    /**
     * The summary of a group page, when Claude wrote it.
     */
    private static function writtenSummary(string $pageFile): ?ParsedPage
    {
        if (!is_file($pageFile)) {
            return null;
        }

        $page = new PageParser()->parse((string) file_get_contents($pageFile));

        return MarkdownWriter::EMPTY === ($page->sections[GroupPageSection::Summary->value] ?? MarkdownWriter::EMPTY) ? null : $page;
    }

    /**
     * The current page, when Claude wrote parts of it: a factual rewrite keeps them.
     */
    private static function writtenPage(string $pageFile): ?ParsedPage
    {
        if (!is_file($pageFile)) {
            return null;
        }

        $page = new PageParser()->parse((string) file_get_contents($pageFile));

        return MarkdownWriter::EMPTY === $page->section(PageSection::Summary) ? null : $page;
    }

    private static function label(StackProfile $stack): string
    {
        return \sprintf('%s (%s)', ucfirst($stack->framework ?? $stack->language), $stack->root);
    }
}
