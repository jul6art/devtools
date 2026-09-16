<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

use Jul6Art\DevTools\Inspection\Model\StateMachine;

/**
 * Mermaid diagrams whose node identifiers are generated (`n1`, `n2`…) and whose labels are always quoted
 * and escaped: a class name with `\`, a route with `{id}` or a bracket breaks an unescaped diagram.
 */
final class MermaidWriter
{
    /**
     * @param list<string>                       $labels
     * @param list<array{int, int, string|null}> $edges  [from index, to index, edge label]
     */
    public static function flowchart(string $direction, array $labels, array $edges): string
    {
        $lines = ['flowchart '.$direction];

        foreach ($labels as $index => $label) {
            $lines[] = \sprintf('  n%d["%s"]', $index + 1, self::label($label));
        }

        foreach ($edges as [$from, $to, $label]) {
            $lines[] = null === $label
                ? \sprintf('  n%d --> n%d', $from + 1, $to + 1)
                : \sprintf('  n%d -->|"%s"| n%d', $from + 1, self::label($label), $to + 1);
        }

        return self::block($lines);
    }

    public static function stateDiagram(StateMachine $machine): string
    {
        $lines = ['stateDiagram-v2'];

        foreach ($machine->places as $index => $place) {
            $lines[] = \sprintf('  state "%s" as s%d', self::label($place), $index + 1);
        }

        if ([] !== $machine->places) {
            $lines[] = '  [*] --> s1';
        }

        foreach ($machine->transitions as $transition) {
            foreach ($transition->from as $from) {
                foreach ($transition->to as $to) {
                    $lines[] = \sprintf('  s%d --> s%d : %s', (int) array_search($from, $machine->places, true) + 1, (int) array_search($to, $machine->places, true) + 1, self::transitionLabel($transition->name));
                }
            }
        }

        return self::block($lines);
    }

    public static function label(string $label): string
    {
        return str_replace(['"', "\r", "\n"], ['#quot;', ' ', ' '], $label);
    }

    private static function transitionLabel(string $label): string
    {
        return str_replace([':', ';', "\n"], ['#58;', '#59;', ' '], $label);
    }

    /**
     * @param list<string> $lines
     */
    private static function block(array $lines): string
    {
        return "```mermaid\n".implode("\n", $lines)."\n```";
    }
}
