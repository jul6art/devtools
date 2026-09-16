<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

use Jul6Art\DevTools\Inspection\Model\FileRef;

/**
 * A file of a workflow with the hash it had when the page was generated: the hash, not git, is what
 * finally decides whether the workflow changed (ADR-0010).
 */
final readonly class TrackedFile
{
    public function __construct(public FileRef $file, public string $sha256)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new \InvalidArgumentException(\sprintf('The hash of "%s" is not a lowercase SHA-256.', $file->path));
        }
    }
}
