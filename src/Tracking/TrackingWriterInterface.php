<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

interface TrackingWriterInterface
{
    /**
     * @return bool whether the file changed
     */
    public function write(string $path, TrackingDocument $document): bool;
}
