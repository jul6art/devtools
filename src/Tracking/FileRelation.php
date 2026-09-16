<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

enum FileRelation: string
{
    /** The file is traversed by the workflow. */
    case File = 'file';
    /** The file is an existing test of the workflow. */
    case Test = 'test';
}
