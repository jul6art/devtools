<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;

final readonly class FileLink
{
    public function __construct(public WorkflowId $workflow, public FileRelation $relation)
    {
    }
}
