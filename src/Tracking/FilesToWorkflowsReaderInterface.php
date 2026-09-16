<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

interface FilesToWorkflowsReaderInterface
{
    public function read(string $path): FilesToWorkflows;
}
