<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

use Jul6Art\DevTools\Tracking\Index;
use Jul6Art\DevTools\Tracking\IndexEntry;
use Jul6Art\DevTools\Tracking\TrackingStatus;

/**
 * Which scenarios need a look (ADR-0020): those whose workflow was rewritten since they were written or
 * reviewed — never deleted, listed — and those whose workflow is gone.
 */
final class ScenarioReview
{
    /**
     * @return array<string, string> scenario id => why, sorted by identifier
     */
    public static function attention(ScenarioIndex $scenarios, Index $index): array
    {
        $workflows = [];

        foreach ($index->entries as $entry) {
            $workflows[$entry->id->value] = $entry;
        }

        $attention = [];

        foreach ($scenarios->entries as $scenario) {
            $workflow = $workflows[$scenario->workflow->value] ?? null;
            $reason = match (true) {
                !$workflow instanceof IndexEntry => \sprintf('orphaned: %s is no longer documented', $scenario->workflow->value),
                TrackingStatus::Orphaned === $workflow->status => \sprintf('orphaned: the entry point of %s is gone', $scenario->workflow->value),
                $workflow->updated > $scenario->revision => \sprintf('to review: %s was rewritten', $scenario->workflow->value),
                default => null,
            };

            if (null !== $reason) {
                $attention[$scenario->id] = $reason;
            }
        }

        return $attention;
    }

    /**
     * @param array<string, string> $attention
     *
     * @return array<string, string> the orphans only: what an inspection reports as a warning
     */
    public static function orphans(array $attention): array
    {
        return array_filter($attention, static fn (string $reason): bool => str_starts_with($reason, 'orphaned'));
    }
}
