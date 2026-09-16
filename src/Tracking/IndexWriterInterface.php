<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

interface IndexWriterInterface
{
    /**
     * @return bool whether the file changed
     */
    public function write(string $path, Index $document): bool;
}
