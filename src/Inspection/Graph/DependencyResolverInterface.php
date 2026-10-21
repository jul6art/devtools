<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\FileRef;

/**
 * The files an entry point traverses (specs § 4.3 step 4): read from PHP by {@see DependencyResolver}, from
 * JavaScript and TypeScript by {@see TypeScriptDependencyResolver} (ADR-0031).
 */
interface DependencyResolverInterface
{
    /**
     * @param list<string>  $methods    the entry point's methods; empty for the whole file
     * @param list<FileRef> $structural always part of the workflow
     */
    public function resolve(FileRef $entryFile, array $methods = [], array $structural = []): ResolvedDependencies;
}
