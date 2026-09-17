<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection;

use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessDecision;

/**
 * What one inspection did (specs § 4.3 step 10): per stack, its adapter, its confidence and how many of its
 * source files no workflow reaches; per type, what was created, updated, left unchanged or orphaned — and
 * everything that went wrong, the fallbacks first.
 */
final class InspectionReport
{
    public const array OUTCOMES = ['created', 'updated', 'unchanged', 'orphaned'];

    /**
     * @var array<string, array<string, int>> type => outcome => count
     */
    private array $counts = [];

    /**
     * @var list<array{stack: string, adapter: string, confidence: string, uncovered: int}>
     */
    public array $stacks = [];

    /**
     * @var list<array{stack: string, cause: string}>
     */
    public array $fallbacks = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @var list<string>
     */
    public array $errors = [];

    /**
     * @var array<string, FreshnessDecision> by identifier
     */
    public array $decisions = [];

    public ?string $path = null;

    public string $projectName = '';

    public float $seconds = 0.0;

    public function __construct(public readonly \DateTimeImmutable $startedAt)
    {
    }

    public function decision(string $id): ?FreshnessDecision
    {
        return $this->decisions[$id] ?? null;
    }

    public function record(string $type, string $outcome): void
    {
        $this->counts[$type][$outcome] = ($this->counts[$type][$outcome] ?? 0) + 1;
    }

    public function count(string $outcome, ?string $type = null): int
    {
        $total = 0;

        foreach ($this->counts as $countedType => $outcomes) {
            if (null === $type || $type === $countedType) {
                $total += $outcomes[$outcome] ?? 0;
            }
        }

        return $total;
    }

    /**
     * 0: everything documented as it should; 1: documented, with warnings or a fallback; 2: an error stopped it.
     */
    public function exitCode(): int
    {
        return match (true) {
            [] !== $this->errors => 2,
            [] !== $this->warnings || [] !== $this->fallbacks => 1,
            default => 0,
        };
    }

    public function toMarkdown(): string
    {
        $lines = ['# Inspection — '.$this->projectName, '', $this->startedAt->format('Y-m-d H:i:s'), ''];

        foreach ($this->fallbacks as $fallback) {
            $lines[] = \sprintf('> ⚠️ **%s — the console could not answer; attributes were read instead, with medium confidence.**', $fallback['stack']);

            foreach (explode("\n", $fallback['cause']) as $line) {
                $lines[] = '> '.$line;
            }

            $lines[] = '';
        }

        if ([] !== $this->stacks) {
            $lines = [...$lines, '| Stack | Adapter | Confidence | Uncovered files |', '|---|---|---|---|'];

            foreach ($this->stacks as $stack) {
                $lines[] = \sprintf('| %s | %s | %s | %d |', $stack['stack'], $stack['adapter'], $stack['confidence'], $stack['uncovered']);
            }

            $lines[] = '';
        }

        $types = array_keys($this->counts);
        sort($types, \SORT_STRING);
        $lines = [...$lines, '| Type | Created | Updated | Unchanged | Orphaned |', '|---|---|---|---|---|'];

        foreach ([...$types, null] as $type) {
            $lines[] = \sprintf('| %s | %s |', $type ?? '**Total**', implode(' | ', array_map(fn (string $outcome): int => $this->count($outcome, $type), self::OUTCOMES)));
        }

        $changes = array_filter($this->decisions, static fn (FreshnessDecision $decision): bool => DecisionKind::Keep !== $decision->kind);

        if ([] !== $changes) {
            $lines = [...$lines, '', '## Decisions', '', '| Workflow | Decision | Why |', '|---|---|---|'];

            foreach ($changes as $id => $decision) {
                $lines[] = \sprintf('| `%s` | %s | %s |', $id, $decision->kind->value, str_replace('|', '\|', $decision->describe()));
            }
        }

        foreach (['Errors' => $this->errors, 'Warnings' => $this->warnings] as $title => $messages) {
            if ([] !== $messages) {
                $lines = [...$lines, '', '## '.$title, '', ...array_map(static fn (string $message): string => '- '.str_replace("\n", "\n  ", $message), $messages)];
            }
        }

        $lines = [...$lines, '', \sprintf('Duration: %.1f s', $this->seconds)];

        return implode("\n", $lines)."\n";
    }
}
