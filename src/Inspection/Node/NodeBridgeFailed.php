<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Node;

/**
 * The Node bridge could not answer: no Node, no TypeScript in the project, output that is not the JSON asked
 * for (ADR-0031). The adapter leaves the stack to the Claude path and says why.
 */
final class NodeBridgeFailed extends \RuntimeException
{
}
