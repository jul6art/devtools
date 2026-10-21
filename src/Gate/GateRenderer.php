<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

/**
 * What the gate says (ADR-0023): its verdict, then each step and what to do — the text Claude receives when the
 * Stop hook blocks, so every line names a file, a workflow, a scenario, a step or a capture.
 */
final class GateRenderer
{
    public function text(GateResult $result): string
    {
        $failed = array_values(array_filter($result->steps, static fn (GateStep $step): bool => GateStatus::Failed === $step->status()));
        $lines = [$result->passed()
            ? \sprintf('DevTools gate — passed (%d files changed).', \count($result->changed))
            : \sprintf('DevTools gate — failed: %s.', implode(', ', array_map(static fn (GateStep $step): string => $step->name, $failed)))];

        foreach ($result->steps as $step) {
            $mark = match ($step->status()) {
                GateStatus::Passed => '✓',
                GateStatus::Warning => '!',
                GateStatus::Failed => '✗',
            };
            $lines[] = \sprintf('%s %s (%s)%s', $mark, $step->name, self::duration($step->milliseconds), [] === $step->notes || GateStatus::Passed !== $step->status() ? '' : ' — '.implode('; ', $step->notes));

            foreach ($step->failures as $failure) {
                $lines[] = '    ✗ '.$failure;
            }

            foreach ($step->warnings as $warning) {
                $lines[] = '    ! '.$warning;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{verdict: string, changed: list<string>, consecutive: int, steps: list<array{name: string, status: string, milliseconds: int, failures: list<string>, warnings: list<string>, notes: list<string>}>}
     */
    public function toArray(GateResult $result): array
    {
        return [
            'verdict' => $result->passed() ? 'passed' : 'failed',
            'changed' => $result->changed,
            'consecutive' => $result->consecutive,
            'steps' => array_map(static fn (GateStep $step): array => [
                'name' => $step->name,
                'status' => $step->status()->value,
                'milliseconds' => $step->milliseconds,
                'failures' => $step->failures,
                'warnings' => $step->warnings,
                'notes' => $step->notes,
            ], $result->steps),
        ];
    }

    private static function duration(int $milliseconds): string
    {
        return $milliseconds < 1000 ? $milliseconds.' ms' : rtrim(rtrim(number_format($milliseconds / 1000, 1, '.', ''), '0'), '.').' s';
    }
}
