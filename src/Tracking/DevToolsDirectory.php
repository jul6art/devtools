<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\WorkflowGroup;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * Where everything lives (ADR-0004). No other class builds one of these paths.
 *
 * Two directories, and the difference is who reads them: the **documentation** — the pages and the menu,
 * Markdown a human opens — lives where the project wants it, `docs/workflows/` by default; `.devtools/`
 * keeps the machinery — configuration, stack, tracking files, index, graph, knowledge, work areas.
 */
final readonly class DevToolsDirectory
{
    public const string NAME = '.devtools';

    /**
     * Created by `init`, in this order.
     */
    public const array FOLDERS = ['workflows', 'knowledge', 'graph', 'schemas', 'pending', 'reports'];

    /**
     * Work areas never committed, added to the project's `.gitignore` by `init`.
     */
    public const array IGNORED = ['pending', 'reports'];

    public const string DEFAULT_DOCS = 'docs/workflows';

    public string $docs;

    public function __construct(public ProjectRoot $root, ?string $docs = null)
    {
        $docs = trim($docs ?? self::DEFAULT_DOCS, '/');

        if ('' === $docs || str_starts_with($docs, '/') || \in_array('..', explode('/', $docs), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a directory of the project: the pages are written inside it.', $docs));
        }

        $this->docs = $docs;
    }

    public function path(string $relative = ''): string
    {
        return $this->root->absolute('' === $relative ? self::NAME : self::NAME.'/'.$relative);
    }

    public function configFile(): string
    {
        return $this->path('config.xml');
    }

    public function stackFile(): string
    {
        return $this->path('stack.xml');
    }

    public function indexFile(): string
    {
        return $this->path('index.xml');
    }

    /**
     * The menu, first page a reader opens.
     */
    public function menuFile(): string
    {
        return $this->root->absolute($this->docs.'/workflows.md');
    }

    public function filesToWorkflowsFile(): string
    {
        return $this->path('graph/files-to-workflows.xml');
    }

    public function overviewFile(): string
    {
        return $this->path('graph/workflows.mermaid');
    }

    public function pageFile(WorkflowType $type, WorkflowId $id, ?string $group = null): string
    {
        return $this->root->absolute($this->pageRelativePath($type, $id, $group));
    }

    /**
     * `route.order.create` → `docs/workflows/routes/order.create.md`, relative to the project.
     */
    public function pageRelativePath(WorkflowType $type, WorkflowId $id, ?string $group = null): string
    {
        return $this->docs.'/'.self::pageInDocs($type, $id, $group);
    }

    /**
     * The page of a group — the one a reader opens to see the routes of a controller (ADR-0045).
     */
    public function groupPageFile(WorkflowType $type, string $group): string
    {
        return $this->root->absolute($this->docs.'/'.self::groupPageInDocs($type, $group));
    }

    /**
     * A file of `.devtools/`, as a link written in the menu: `stack.xml` read from `docs/workflows/` is
     * `../../.devtools/stack.xml`.
     */
    public static function machineryLink(string $docs, string $inside): string
    {
        return str_repeat('../', substr_count(trim($docs, '/'), '/') + 1).self::NAME.'/'.$inside;
    }

    public function trackingFile(WorkflowType $type, WorkflowId $id): string
    {
        return $this->path(\sprintf('workflows/%s/%s.xml', $type->name, $id->pageName()));
    }

    /**
     * `route.order.create` → `routes/order.create.md`, relative to the documentation directory.
     *
     * In a group (ADR-0045), the page moves under the group's directory and keeps only what its
     * identifier says beyond it: `route.admin.user.edit` of the group `admin.user` is
     * `routes/admin.user/edit.md`.
     */
    public static function pageInDocs(WorkflowType $type, WorkflowId $id, ?string $group = null): string
    {
        if (null === $group) {
            return \sprintf('%s/%s.md', $type->name, $id->pageName());
        }

        return \sprintf('%s/%s/%s.md', $type->name, $group, WorkflowGroup::leaf($group, $id));
    }

    /**
     * `routes/admin.user/README.md`, relative to the documentation directory.
     *
     * `README.md` and not `index.md`: `route.<resource>.index` is the list route of almost every
     * controller, so the collision would be the rule; and a forge renders the `README.md` of a directory
     * when it is opened, so the link to the directory already shows the table of routes.
     */
    public static function groupPageInDocs(WorkflowType $type, string $group): string
    {
        return \sprintf('%s/%s/README.md', $type->name, $group);
    }
}
