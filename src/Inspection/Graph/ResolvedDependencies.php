<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\PackageRef;

final readonly class ResolvedDependencies
{
    /**
     * @param list<FileRef>    $files    every file traversed, sorted by path
     * @param list<FileRef>    $direct   the entry point's file and its first hop, sorted by path
     * @param list<PackageRef> $packages sorted by name
     * @param list<string>     $warnings
     */
    public function __construct(
        public array $files,
        public array $direct,
        public array $packages,
        public array $warnings,
    ) {
    }
}
