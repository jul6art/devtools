<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * Collects the path of every analysed file (ADR-0048).
 *
 * ⚠️ The rule does not read what it collects, and it still has to exist: PHPStan only visits
 * `CollectedDataNode` — the one node that runs once, at the end, past the result cache — when at least
 * one collector is registered. Without this class the rule was never called, and PHPStan reported
 * nothing at all on a project that had drifted.
 *
 * @implements Collector<FileNode, string>
 */
class AnalysedFileCollector implements Collector
{
    #[\Override]
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): string
    {
        return $scope->getFile();
    }
}
