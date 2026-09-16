<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

interface TrackingReaderInterface
{
    public function read(string $path): TrackingDocument;
}
