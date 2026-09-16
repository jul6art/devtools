<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

interface ConfigReaderInterface
{
    /**
     * A missing file is not an error: it means every default.
     */
    public function read(string $path): Config;
}
