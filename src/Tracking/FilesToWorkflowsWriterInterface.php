<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

interface FilesToWorkflowsWriterInterface
{
    /**
     * @return bool whether the file changed
     */
    public function write(string $path, FilesToWorkflows $document): bool;
}
