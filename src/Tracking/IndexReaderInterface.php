<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

interface IndexReaderInterface
{
    public function read(string $path): Index;
}
