<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * Renders `graph/workflows.mermaid`: every workflow, grouped by type, with its dependencies.
 */
final class GraphRenderer
{
    /**
     * @param list<Workflow> $workflows
     */
    public function render(array $workflows): string
    {
        usort($workflows, static fn (Workflow $a, Workflow $b): int => strcmp($a->id->value, $b->id->value));

        $nodes = [];

        foreach ($workflows as $index => $workflow) {
            $nodes[$workflow->id->value] = 'w'.($index + 1);
        }

        $byType = [];

        foreach ($workflows as $workflow) {
            $byType[$workflow->type->name][] = $workflow;
        }

        $order = array_keys(WorkflowType::NATIVE);
        uksort($byType, static fn (string $a, string $b): int => [!\in_array($a, $order, true) ? 1 : 0, (int) array_search($a, $order, true), $a] <=> [!\in_array($b, $order, true) ? 1 : 0, (int) array_search($b, $order, true), $b]);

        $lines = ['flowchart LR'];

        foreach ($byType as $type => $members) {
            $lines[] = \sprintf('  subgraph %s["%s"]', $type, MermaidWriter::label(Labels::type($members[0]->type)));

            foreach ($members as $workflow) {
                $lines[] = \sprintf('    %s["%s"]', $nodes[$workflow->id->value], MermaidWriter::label($workflow->id->value));
            }

            $lines[] = '  end';
        }

        foreach ($workflows as $workflow) {
            foreach ($workflow->dependsOn as $dependency) {
                if (isset($nodes[$dependency->value])) {
                    $lines[] = \sprintf('  %s --> %s', $nodes[$workflow->id->value], $nodes[$dependency->value]);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }
}
