<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports the drift of the workflows as PHPStan errors (ADR-0048).
 *
 * ⚠️ It hangs on `CollectedDataNode`, the node PHPStan visits once at the end of an analysis, and not on
 * a node of each file: the result cache can therefore never hide a drift that appeared since the last
 * run. It analyses nothing itself — DevTools computes, PHPStan displays.
 *
 * Not final: a consumer may need to point it at another directory.
 *
 * @implements Rule<CollectedDataNode>
 */
class WorkflowDriftRule implements Rule
{
    public function __construct(
        private readonly WorkflowDrift $drift = new WorkflowDrift(),
        private readonly ?string $path = null,
    ) {
    }

    /**
     * ⚠️ Built by a static factory rather than by the container: PHPStan's DI does not fall back on a
     * constructor's default values, and declaring the whole pipeline as services would mean maintaining
     * a second wiring of the core in a `.neon` file.
     */
    public static function create(?string $path = null): self
    {
        return new self(new WorkflowDrift(), $path);
    }

    #[\Override]
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($this->drift->of($this->path ?? (string) getcwd()) as $entry) {
            $error = RuleErrorBuilder::message($entry['message'])
                ->identifier('devtools.workflowDrift')
                ->tip($entry['tip']);

            if (null !== $entry['file']) {
                $error->file(($this->path ?? (string) getcwd()).'/'.$entry['file']);
            }

            if (null !== $entry['line']) {
                $error->line($entry['line']);
            }

            $errors[] = $error->build();
        }

        return $errors;
    }
}
