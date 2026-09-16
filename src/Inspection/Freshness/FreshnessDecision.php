<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Tracking\TrackingStatus;

final readonly class FreshnessDecision
{
    /**
     * @param list<Reason> $reasons
     */
    public function __construct(public WorkflowId $id, public DecisionKind $kind, public array $reasons = [])
    {
    }

    /**
     * The state of the documentation before this decision is applied.
     */
    public function status(): TrackingStatus
    {
        return match ($this->kind) {
            DecisionKind::Rewrite, DecisionKind::ManualStale => TrackingStatus::Stale,
            DecisionKind::Orphan => TrackingStatus::Orphaned,
            DecisionKind::Create, DecisionKind::Keep => TrackingStatus::Fresh,
        };
    }

    public function describe(): string
    {
        return implode('; ', array_map(static fn (Reason $reason): string => $reason->describe(), $this->reasons));
    }
}
