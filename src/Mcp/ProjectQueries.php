<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Mcp;

use Jul6Art\DevTools\Ask\PageSelector;
use Jul6Art\DevTools\Ask\SearchIndexStore;
use Jul6Art\DevTools\Ask\SelectedPage;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeLibrary;
use Jul6Art\DevTools\Stack\Knowledge\KnowledgeProvider;
use Jul6Art\DevTools\Stack\StackProfile;
use Jul6Art\DevTools\Stack\XmlStackStore;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\Revision;
use Jul6Art\DevTools\Tracking\TrackedFile;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;

/**
 * What the MCP server answers (ADR-0018), without the SDK: text Claude reads, one string per block of the
 * answer.
 *
 * Read only, and narrow: a call reads the index, one workflow's page and tracking file, the impact map or a
 * knowledge sheet — never the code, never a write, never an inspection. A project never inspected gets the
 * command to run instead of an answer.
 */
final readonly class ProjectQueries
{
    private WorkflowTypeRegistry $types;

    private KnowledgeProvider $knowledge;

    public function __construct(public ProjectRoot $root, private GitClient $git = new GitClient())
    {
        $config = new XmlConfigReader()->read(new DevToolsDirectory($root)->configFile());
        $this->types = new WorkflowTypeRegistry($config->customTypes);
        $this->knowledge = new KnowledgeProvider(library: KnowledgeLibrary::forProject($root, $config));
    }

    /**
     * The workflow types this project knows, custom ones included: what `list_workflows` filters on.
     *
     * @return list<string>
     */
    public function typeNames(): array
    {
        return array_map(static fn (WorkflowType $type): string => $type->name, $this->types->all());
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_map(static fn (TrackingStatus $status): string => $status->value, TrackingStatus::cases());
    }

    /**
     * @return list<string>
     */
    public function listWorkflows(?string $type = null, ?string $status = null): array
    {
        [$directory, $index] = $this->index();
        $entries = array_values(array_filter($index->entries, static fn (IndexEntry $entry): bool => (null === $type || $entry->type->name === $type) && (null === $status || $entry->status->value === $status)));

        return [self::json([
            'count' => \count($entries),
            'workflows' => array_map(static fn (IndexEntry $entry): array => [
                'id' => $entry->id->value,
                'type' => $entry->type->name,
                'title' => $entry->title,
                'status' => $entry->status->value,
                'confidence' => $entry->confidence->value,
                'page' => $directory->docs.'/'.$entry->page(),
            ], $entries),
        ])];
    }

    /**
     * The page as a reader opens it, then what the tracking file knows and the page does not show.
     *
     * @return list<string>
     */
    public function getWorkflow(string $id): array
    {
        [$directory, $index] = $this->index();
        $entry = array_find($index->entries, static fn (IndexEntry $entry): bool => $entry->id->value === $id) ?? throw ToolRefused::unknownWorkflow($id);
        $tracking = new XmlTrackingStore($this->types)->read($directory->trackingFile($entry->type, $entry->id));
        $page = $directory->pageFile($entry->type, $entry->id, $entry->group);

        return [
            is_file($page) ? (string) file_get_contents($page) : \sprintf('No page yet for %s: run `devtools workflows:inspect`.', $id),
            self::json([
                'id' => $entry->id->value,
                'type' => $entry->type->name,
                'title' => $entry->title,
                'status' => $entry->status->value,
                'confidence' => $entry->confidence->value,
                'page' => $directory->pageRelativePath($entry->type, $entry->id, $entry->group),
                'tracking' => $this->root->relative($directory->trackingFile($entry->type, $entry->id)),
                'files' => array_map(static fn (TrackedFile $file): array => ['path' => $file->file->path, 'role' => $file->file->role->value], $tracking->files),
                'tests' => array_map(static fn (FileRef $test): string => $test->path, $tracking->tests),
                'dependsOn' => array_map(static fn (WorkflowId $dependency): string => $dependency->value, $tracking->dependsOn),
                'mechanisms' => array_map(static fn (Mechanism $mechanism): array => ['kind' => $mechanism->kind, 'name' => $mechanism->name, 'event' => $mechanism->event], $tracking->mechanisms),
                'history' => array_map(static fn (Revision $revision): array => ['date' => $revision->at->format('Y-m-d'), 'commit' => $revision->commit, 'change' => $revision->reason], $tracking->history),
            ]),
        ];
    }

    /**
     * The pages that answer a question (ADR-0039), as `devtools ask --pages --format=json` lists them: selected
     * without AI, best first, then those they lead to; the stale ones said.
     *
     * @return list<string>
     */
    public function searchWorkflows(string $question, int $limit = PageSelector::LIMIT): array
    {
        if ('' === trim($question) || $limit < 1 || $limit > PageSelector::LIMIT) {
            throw ToolRefused::search();
        }

        [$directory] = $this->index();
        // Read only, as every tool: a stale index is built again in memory, never written.
        $pages = new PageSelector()->select(new SearchIndexStore()->current($directory, $this->types, save: false), $question, $limit);

        return [self::json([
            'question' => $question,
            'pages' => array_map(static fn (SelectedPage $page): array => $page->toArray(), $pages),
            'warnings' => array_values(array_map(static fn (SelectedPage $page): string => \sprintf('%s is %s: its page may describe the code as it was (revised %s).', $page->id, $page->status, $page->updated), array_filter($pages, static fn (SelectedPage $page): bool => $page->isStale()))),
        ])];
    }

    /**
     * The answer of `workflows:impact --format=json` (ADR-0016).
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public function impact(array $files = [], bool $changed = false): array
    {
        if (([] === $files) !== $changed) {
            throw ToolRefused::impactInput();
        }

        $files = $changed ? $this->git->workingTreeChanges($this->root) ?? throw ToolRefused::noGit() : $files;

        return [self::json(new ImpactResolver($this->types)->resolve(DevToolsDirectory::documented($this->root), $files)->toArray())];
    }

    /**
     * The knowledge of the project's stacks — or of the one named by its root or its key — as the inspection
     * reads it: the project's sheet, else the shared library's, else the one DevTools ships.
     *
     * @return list<string>
     */
    public function knowledge(?string $stack = null): array
    {
        $directory = new DevToolsDirectory($this->root);

        if (!is_file($directory->stackFile())) {
            throw ToolRefused::notInspected($this->root->relative($directory->stackFile()));
        }

        $sheets = [];

        foreach (new XmlStackStore()->read($directory->stackFile())->stacks as $profile) {
            if (null !== $profile->knowledgeKey) {
                $sheets[$profile->knowledgeKey][] = $profile;
            }
        }

        $asked = array_filter($sheets, static fn (array $profiles, string $key): bool => null === $stack || $key === $stack || array_any($profiles, static fn (StackProfile $profile): bool => $profile->root === $stack), \ARRAY_FILTER_USE_BOTH);

        if ([] === $asked) {
            throw ToolRefused::unknownStack($stack, array_keys($sheets));
        }

        $answer = [];

        foreach ($asked as $key => $profiles) {
            $source = $this->knowledge->source($directory, $key);
            $roots = implode(', ', array_map(static fn (StackProfile $profile): string => $profile->root, $profiles));
            $answer[] = null === $source
                ? \sprintf('No knowledge for %s (stack %s) yet: `devtools workflows:inspect` asks Claude to write it.', $key, $roots)
                : \sprintf("<!-- knowledge %s · stack %s · %s -->\n\n%s", $key, $roots, $this->origin($source, $key), file_get_contents($source));
        }

        return $answer;
    }

    private function origin(string $source, string $key): string
    {
        return match (true) {
            str_starts_with($source, $this->root->path.'/') => $this->root->relative($source),
            Resources::path('knowledge/'.$key.'.md') === $source => 'shipped with DevTools',
            default => 'shared library: '.$source,
        };
    }

    /**
     * @return array{DevToolsDirectory, Index}
     */
    private function index(): array
    {
        $file = new DevToolsDirectory($this->root)->indexFile();

        if (!is_file($file)) {
            throw ToolRefused::notInspected($this->root->relative($file));
        }

        $index = new XmlIndexStore($this->types)->read($file);

        return [new DevToolsDirectory($this->root, $index->docs), $index];
    }

    /**
     * Compact: what Claude Code reads counts against its context, and an MCP answer past 25,000 tokens is cut.
     *
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
