<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;

/**
 * The existing tests of a workflow: those that use its entry point or a file of its first hop (ADR-0006).
 */
interface TestLocatorInterface
{
    /**
     * @param list<FileRef> $nearFiles the entry point's file and its first hop
     *
     * @return list<FileRef>
     */
    public function testsOf(array $nearFiles): array;
}
