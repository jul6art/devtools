<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

use Jul6Art\DevTools\Tracking\FileRelation;
use Jul6Art\DevTools\Tracking\FilesToWorkflows;

/**
 * Whether a feedback about to be written was already given (ADR-0025): the same category, and a close target —
 * the same element, the same file, the same workflow, or two workflows of a kind sharing a component. Read from
 * the index of feedback and the map of the last inspection, never from the feedback files.
 *
 * A feedback set aside on purpose is not one to see again.
 */
final readonly class RecurrenceDetector
{
    /**
     * A template, a component: what two screens share and a defect travels with.
     */
    private const string COMPONENT = '#(^|/)(templates|components?)/|\.(twig|vue|svelte|tsx|jsx)$#i';

    public function __construct(private ?FilesToWorkflows $graph = null)
    {
    }

    public function find(FeedbackIndex $index, FeedbackTarget $target, string $category): ?FeedbackIndexEntry
    {
        $best = null;
        $bestScore = 0;

        foreach ($index->entries as $entry) {
            if ($entry->category !== $category || FeedbackStatus::WontFix === $entry->status) {
                continue;
            }

            $score = $this->closeness($entry->target, $target);

            if ($score > $bestScore || ($score === $bestScore && $score > 0 && null !== $best && $entry->occurrences > $best->occurrences)) {
                [$best, $bestScore] = [$entry, $score];
            }
        }

        return $best;
    }

    /**
     * 4 the same element, 3 the same file, 2 the same workflow, 1 workflows of a kind sharing a component, 0 none.
     */
    private function closeness(FeedbackTarget $known, FeedbackTarget $new): int
    {
        return match (true) {
            null !== $new->selector && $known->selector === $new->selector => 4,
            null !== $new->file && $known->file === $new->file => 3,
            null !== $new->workflow && $known->workflow === $new->workflow => 2,
            null !== $new->workflow && null !== $known->workflow && $this->shareComponent($known->workflow, $new->workflow) => 1,
            default => 0,
        };
    }

    private function shareComponent(string $one, string $other): bool
    {
        if (!$this->graph instanceof FilesToWorkflows || strtok($one, '.') !== strtok($other, '.')) {
            return false;
        }

        foreach ($this->graph->links as $path => $links) {
            if (1 !== preg_match(self::COMPONENT, $path)) {
                continue;
            }

            $workflows = [];

            foreach ($links as $link) {
                if (FileRelation::File === $link->relation) {
                    $workflows[] = $link->workflow->value;
                }
            }

            if (\in_array($one, $workflows, true) && \in_array($other, $workflows, true)) {
                return true;
            }
        }

        return false;
    }
}
