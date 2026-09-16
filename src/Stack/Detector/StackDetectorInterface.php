<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Detector;

use Jul6Art\DevTools\Stack\StackProfile;

interface StackDetectorInterface
{
    /**
     * Reads the manifests of one directory — without executing anything of the project — and
     * describes the stack they declare, or null when this detector's ecosystem is absent.
     *
     * @param string       $directory absolute
     * @param string       $root      the same directory relative to the project ("." for its root)
     * @param list<string> $excludes
     */
    public function detect(string $directory, string $root, array $excludes): ?StackProfile;

    /**
     * The project name declared by the manifest, if any.
     */
    public function projectName(string $directory): ?string;
}
