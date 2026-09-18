<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * A group as `index.xml` keeps it: a directory, a title, and the type it groups (ADR-0045).
 *
 * The menu reads these to list controllers instead of routes; the count comes from the entries, so that
 * a group and its workflows can never disagree on how many there are.
 */
final readonly class IndexGroup
{
    public function __construct(public WorkflowType $type, public string $directory, public string $title)
    {
    }

    /**
     * The page of the group, relative to the documentation directory.
     */
    public function page(): string
    {
        return DevToolsDirectory::groupPageInDocs($this->type, $this->directory);
    }

    public function key(): string
    {
        return $this->type->name."\0".$this->directory;
    }
}
