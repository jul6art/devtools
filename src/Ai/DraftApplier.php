<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Adapter\Claude\Discovery;
use Jul6Art\DevTools\Inspection\Adapter\Claude\XmlDiscoveryBriefStore;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Serialization\ModelXmlSerializer;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdAssigner;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdCollision;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdDeriver;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\GroupPageRenderer;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Rendering\PageRenderer;
use Jul6Art\DevTools\Rendering\PageSection;
use Jul6Art\DevTools\Rendering\ParsedPage;
use Jul6Art\DevTools\Rendering\RenderingContext;
use Jul6Art\DevTools\Stack\Knowledge\DepositOutcome;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeCanvas;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\XmlKnowledgeBriefStore;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Generation;
use Jul6Art\DevTools\Tracking\GenerationMode;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\TrackingDocument;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use Jul6Art\DevTools\Version;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `workflows:apply`: validates each draft Claude wrote and, when it passes, rebuilds the page — the facts
 * from the model, the descriptive sections from the draft (ADR-0011).
 */
final readonly class DraftApplier
{
    public function __construct(
        private Clock $clock = new SystemClock(),
        private AtomicFileWriter $writer = new AtomicFileWriter(),
        private PageDraftValidator $validator = new PageDraftValidator(),
    ) {
    }

    /**
     * @param bool $share false for `--no-share`: the knowledge stays in the project (ADR-0041)
     */
    public function apply(ProjectRoot $root, bool $share = true): ApplyResult
    {
        $result = new ApplyResult();
        $directory = new DevToolsDirectory($root, self::documentedIn($root));
        $config = new XmlConfigReader()->read($directory->configFile());
        $library = KnowledgeLibrary::forProject($root, $config, $this->writer);

        // Knowledge first: page briefs of a stack are only written once its knowledge exists.
        foreach (glob($directory->path('pending/knowledge.*.draft.md')) ?: [] as $draftFile) {
            $this->applyKnowledge($directory, $draftFile, $result, $library, $share && $config->shareKnowledge);
        }

        // Then discoveries: pages of the Claude path only exist once a discovery says what they are.
        foreach (glob($directory->path('pending/discovery.*.draft.xml')) ?: [] as $draftFile) {
            $this->applyDiscovery($directory, $draftFile, $result, $config);
        }

        // Then the group pages: their summary is written against the pages of the routes they list, so they
        // are applied after the drafts of those routes, and they need neither model nor tracking.
        foreach (glob($directory->path('pending/group.*.draft.md')) ?: [] as $draftFile) {
            $this->applyGroup($directory, $draftFile, $result);
        }

        $drafts = glob($directory->path('pending/page.*.draft.md')) ?: [];

        if ([] === $drafts) {
            return $result;
        }

        $types = new WorkflowTypeRegistry($config->customTypes);
        $tracking = new XmlTrackingStore($types, $this->writer);
        $documents = [];

        foreach (glob($directory->path('workflows/*/*.xml')) ?: [] as $file) {
            $document = $tracking->read($file);
            $documents[$document->id->value] = $document;
        }

        foreach ($drafts as $draftFile) {
            $id = substr(basename($draftFile), \strlen('page.'), -\strlen('.draft.md'));

            try {
                $documents[$id] = $this->applyOne($directory, $id, $draftFile, $documents, $types, $tracking, $result);
            } catch (InvalidXml|InvalidModel $invalid) {
                $result->refused[$id] = [$invalid->getMessage()];
            }
        }

        if ([] !== $result->accepted) {
            $this->refreshIndex($directory, $types, array_values($documents));
        }

        return $result;
    }

    /**
     * The summary Claude wrote for a group page (ADR-0045).
     *
     * Only that section is replaced: the table of routes and the state machine stay exactly as the
     * inspection rendered them, because `apply` has the page and not the model.
     */
    private function applyGroup(DevToolsDirectory $directory, string $draftFile, ApplyResult $result): void
    {
        $name = substr(basename($draftFile), \strlen('group.'), -\strlen('.draft.md'));
        $id = 'group.'.$name;

        try {
            $brief = new XmlGroupBriefStore()->read($directory->path('pending/'.$id.'.brief.xml'));
        } catch (InvalidXml $invalid) {
            $result->refused[$id] = [$invalid->getMessage()];

            return;
        }

        $draft = PageDraft::parse((string) file_get_contents($draftFile));
        $problems = $this->validator->validateGroup($draft, $brief);
        $pageFile = $directory->root->absolute($brief->pagePath);

        if (!is_file($pageFile)) {
            $problems[] = \sprintf('The page "%s" does not exist any more: run workflows:inspect again.', $brief->pagePath);
        }

        if ([] !== $problems) {
            $result->refused[$id] = $problems;

            return;
        }

        $page = new PageParser()->parse((string) file_get_contents($pageFile));
        $this->writer->write($pageFile, GroupPageRenderer::withSummary($page, trim($draft->sections[PageSection::Summary->value]['content'])));

        new Filesystem()->remove([$draftFile, $directory->path('pending/'.$id.'.brief.xml')]);
        $result->accepted[] = $id;
    }

    /**
     * Where the last inspection wrote the pages: `apply` never guesses, it reads the index.
     */
    private static function documentedIn(ProjectRoot $root): ?string
    {
        $index = new DevToolsDirectory($root)->indexFile();

        return is_file($index) ? new XmlIndexStore(new WorkflowTypeRegistry())->read($index)->docs : null;
    }

    /**
     * @param array<string, TrackingDocument> $documents
     */
    private function applyOne(DevToolsDirectory $directory, string $id, string $draftFile, array $documents, WorkflowTypeRegistry $types, XmlTrackingStore $tracking, ApplyResult $result): TrackingDocument
    {
        $brief = new XmlPageBriefStore($types)->read($directory->path('pending/'.basename($draftFile, '.draft.md').'.brief.xml'));
        $document = $documents[$id] ?? throw new InvalidModel(\sprintf('No tracking file documents "%s": run the inspection again.', $id));
        $workflow = new ModelXmlSerializer($types)->deserialize((string) file_get_contents($directory->root->absolute($brief->modelPath)), $brief->modelPath)->workflows[0]
            ?? throw new InvalidModel(\sprintf('%s holds no workflow: run the inspection again.', $brief->modelPath));

        // The group travels in the tracking, not in the model: without it the page would link to its
        // neighbours as if it lived directly under its type (ADR-0045).
        $workflow = $workflow->inGroup($document->group);
        $draft = PageDraft::parse((string) file_get_contents($draftFile));

        $errors = $this->validator->validate($draft, $workflow, $brief->revision);

        if ($brief->revision != $document->lastRevision()->at && [] === array_filter($errors, static fn (string $error): bool => str_contains($error, 'outdated'))) {
            $errors[] = \sprintf('The draft is outdated: the brief was written for revision %s, the workflow is now at revision %s. Run the inspection again for a new brief.', $brief->revision->format(\DATE_ATOM), $document->lastRevision()->at->format(\DATE_ATOM));
        }

        if ([] !== $errors) {
            $result->refused[$id] = $errors;

            return $document;
        }

        $now = $this->clock->now();
        $change = (string) $draft->content(PageDraft::CHANGE);
        $history = $document->history;

        if ($brief->amend) {
            $last = array_pop($history);
            $history[] = new Revision($last->at, $last->commit, 'initial' === $last->reason ? $change : $change.' ('.$last->reason.')');
        } else {
            $history[] = new Revision($now, $document->vcs->commit, $change);
        }

        $written = new TrackingDocument(
            $document->id,
            $document->type,
            $document->title,
            new Generation($now, 'devtools '.Version::current(), GenerationMode::Ai, $draft->metadata['model'] ?? null, $brief->promptVersion),
            $document->vcs,
            $document->main,
            $document->satellites,
            $document->files,
            $document->tests,
            $document->packages,
            $document->dependsOn,
            $document->confidence,
            $document->producer,
            $document->status,
            $history,
            $document->decisions,
            $document->mechanisms,
            $document->group,
        );

        $page = new PageRenderer()->render($workflow, $history, RenderingContext::fromTracking(array_values($documents)), self::writtenSections($draft));

        // The path comes from the brief, never from the model: the serialized model carries no group
        // (ADR-0045), so recomputing it here wrote a grouped project's pages back at the flat path —
        // beside the page the reader opens, which stayed empty.
        $this->writer->write($directory->root->absolute($brief->pagePath), $page);
        $tracking->write($directory->trackingFile($workflow->type, $workflow->id), $written);

        new Filesystem()->remove([$draftFile, $directory->root->absolute($brief->modelPath), $directory->path('pending/'.PageBrief::fileName($workflow->id, 'brief.xml'))]);
        $result->accepted[] = $id;

        return $written;
    }

    /**
     * A discovery draft is untrusted: every file it names must exist inside the stack, identifiers are recomputed
     * from the entry points, and confidence is set to medium whatever it declares (ADR-0013).
     */
    private function applyDiscovery(DevToolsDirectory $directory, string $draftFile, ApplyResult $result, Config $config): void
    {
        $id = basename($draftFile, '.draft.xml');

        try {
            $brief = new XmlDiscoveryBriefStore($this->writer)->read($directory->path('pending/'.$id.'.brief.xml'));
            $types = new WorkflowTypeRegistry($config->customTypes);
            $serializer = new ModelXmlSerializer($types);
            $draft = $serializer->deserialize((string) file_get_contents($draftFile), $brief->draftPath);
            $discoveryFile = Discovery::file($directory, $brief->slug);
            $previous = is_file($discoveryFile) ? $serializer->deserialize((string) file_get_contents($discoveryFile), $discoveryFile) : null;

            $errors = $this->missingOrOutside($directory, $brief->root, $draft);

            if ([] !== $errors) {
                $result->refused[$id] = $errors;

                return;
            }

            $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver($config->routePrefix), $config->aliases);
            $workflows = [];
            $claimed = [];

            // Within the draft itself, one identifier belongs to one workflow — even with an identical entry point.
            foreach ($draft->workflows as $workflow) {
                $derived = new WorkflowIdDeriver($config->routePrefix)->derive($workflow->type, $workflow->main);

                if (isset($claimed[$derived->value]) && !isset($config->aliases[$workflow->main->name])) {
                    throw WorkflowIdCollision::between($derived, $claimed[$derived->value], $workflow->main);
                }

                $claimed[$derived->value] = $workflow->main;
            }

            foreach ([...$previous->workflows ?? [], ...$draft->workflows] as $workflow) {
                $derived = $assigner->assign($workflow->type, $workflow->main);
                $existing = $workflows[$derived->value] ?? null;

                // A limited discovery may attach files to an existing workflow, never take any away.
                $workflows[$derived->value] = new Workflow(
                    id: $derived,
                    type: $workflow->type,
                    title: $workflow->title,
                    main: $workflow->main,
                    satellites: $existing->satellites ?? $workflow->satellites,
                    files: self::union($existing->files ?? [], $workflow->files),
                    packages: $existing->packages ?? $workflow->packages,
                    dependsOn: $existing->dependsOn ?? $workflow->dependsOn,
                    tests: self::union($existing->tests ?? [], $workflow->tests),
                    navigation: $existing->navigation ?? $workflow->navigation,
                    states: $existing->states ?? $workflow->states,
                    confidence: Confidence::Medium,
                    source: new WorkflowSource('claude'),
                );
            }

            $referenced = [];

            foreach ($workflows as $workflow) {
                foreach ([...$workflow->files, ...$workflow->tests] as $file) {
                    $referenced[$file->path] = true;
                }
            }

            // A file Claude was shown and did not attach anywhere is uncovered, not asked about again.
            $uncovered = array_filter([...$previous->uncovered ?? [], ...$draft->uncovered, ...array_map(static fn (string $path): FileRef => new FileRef($path), $brief->limitedTo)], static fn (FileRef $file): bool => !isset($referenced[$file->path]));
            $uncoveredByPath = [];

            foreach ($uncovered as $file) {
                $uncoveredByPath[$file->path] ??= $file;
            }

            $this->writer->write($discoveryFile, $serializer->serialize(new InspectionResult($draft->stack, array_values($workflows), array_values($uncoveredByPath))));
        } catch (InvalidXml|InvalidModel|WorkflowIdCollision $invalid) {
            $result->refused[$id] = [$invalid->getMessage()];

            return;
        }

        new Filesystem()->remove([$draftFile, $directory->path('pending/'.$id.'.brief.xml')]);
        $result->accepted[] = $id;
    }

    /**
     * @return list<string>
     */
    private function missingOrOutside(DevToolsDirectory $directory, string $root, InspectionResult $draft): array
    {
        $errors = [];

        foreach ($draft->workflows as $workflow) {
            foreach ([$workflow->main->declaredIn, ...array_map(static fn (EntryPoint $satellite): FileRef => $satellite->declaredIn, $workflow->satellites), ...$workflow->files, ...$workflow->tests] as $file) {
                if ('.' !== $root && !str_starts_with($file->path, $root.'/')) {
                    $errors[] = \sprintf('%s is outside the stack root "%s" (workflow "%s").', $file->path, $root, $workflow->main->name);
                } elseif (!is_file($directory->root->absolute($file))) {
                    $errors[] = \sprintf('%s does not exist (workflow "%s"): name only files you opened.', $file->path, $workflow->main->name);
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param list<FileRef> $first
     * @param list<FileRef> $second
     *
     * @return list<FileRef>
     */
    private static function union(array $first, array $second): array
    {
        $byPath = [];

        foreach ([...$first, ...$second] as $file) {
            $byPath[$file->path] ??= $file;
        }

        return array_values($byPath);
    }

    private function applyKnowledge(DevToolsDirectory $directory, string $draftFile, ApplyResult $result, KnowledgeLibrary $library, bool $share): void
    {
        $id = basename($draftFile, '.draft.md');

        try {
            $brief = new XmlKnowledgeBriefStore($this->writer)->read($directory->path('pending/'.$id.'.brief.xml'));
        } catch (InvalidXml $invalid) {
            $result->refused[$id] = [$invalid->getMessage()];

            return;
        }

        $draft = (string) file_get_contents($draftFile);
        $problems = new KnowledgeCanvas()->problems($draft);

        if ([] !== $problems) {
            $result->refused[$id] = $problems;

            return;
        }

        $this->writer->write($directory->path('knowledge/'.$brief->key.'.md'), $draft);

        // The library grows only with a sheet that passed the canvas, and never overwrites one it holds.
        $outcome = $share ? $library->deposit($brief->key, $draft, basename($directory->root->path)) : DepositOutcome::Declined;

        if (DepositOutcome::Deposited === $outcome) {
            $result->deposited[$brief->key] = $library->path.'/'.$brief->key.'.md';
        }

        if (DepositOutcome::NotWritable === $outcome) {
            $result->warnings[] = \sprintf('The knowledge library "%s" cannot be written: "%s" stays in this project only.', $library->path, $brief->key);
        }

        new Filesystem()->remove([$draftFile, $directory->path('pending/'.$id.'.brief.xml')]);
        $result->accepted[] = $id;
    }

    private static function writtenSections(PageDraft $draft): ParsedPage
    {
        $sections = [];

        foreach (PageSection::cases() as $section) {
            if ($section->writtenByClaude()) {
                $sections[$section->value] = (string) $draft->content($section->value);
            }
        }

        $sections[PageSection::Trigger->value] = '| Preconditions | '.str_replace('|', '\|', (string) $draft->content(PageDraft::PRECONDITIONS)).' |';

        return new ParsedPage('', '', $sections);
    }

    /**
     * @param list<TrackingDocument> $documents
     */
    private function refreshIndex(DevToolsDirectory $directory, WorkflowTypeRegistry $types, array $documents): void
    {
        $store = new XmlIndexStore($types, $this->writer);

        if (!is_file($directory->indexFile())) {
            return;
        }

        $previous = $store->read($directory->indexFile());
        $store->write($directory->indexFile(), Index::fromTracking($previous->scannedAt, $previous->vcs, $documents, $directory->docs));
    }
}
