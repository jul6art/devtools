<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * Where everything lives inside `.devtools/` (ADR-0004). No other class builds one of these paths.
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

    public function __construct(public ProjectRoot $root)
    {
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

    public function menuFile(): string
    {
        return $this->path('workflows.md');
    }

    public function filesToWorkflowsFile(): string
    {
        return $this->path('graph/files-to-workflows.xml');
    }

    public function overviewFile(): string
    {
        return $this->path('graph/workflows.mermaid');
    }

    public function pageFile(WorkflowType $type, WorkflowId $id): string
    {
        return $this->path(self::pageRelativePath($type, $id));
    }

    public function trackingFile(WorkflowType $type, WorkflowId $id): string
    {
        return $this->path(\sprintf('workflows/%s/%s.xml', $type->name, $id->pageName()));
    }

    /**
     * `route.order.create` → `workflows/routes/order.create.md` (specs § 4.4).
     */
    public static function pageRelativePath(WorkflowType $type, WorkflowId $id): string
    {
        return \sprintf('workflows/%s/%s.md', $type->name, $id->pageName());
    }
}
