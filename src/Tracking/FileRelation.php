<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

enum FileRelation: string
{
    /** The file is traversed by the workflow. */
    case File = 'file';
    /** The file is an existing test of the workflow. */
    case Test = 'test';

    /**
     * The file runs inside the workflow without being one of its own: it declares a mechanism the workflow
     * carries, or belongs to a workflow it depends on (ADR-0016).
     */
    case Dependency = 'dependency';
}
