<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

/**
 * Freshness of a documented workflow (specs § 4.6.1, § 13).
 */
enum TrackingStatus: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Orphaned = 'orphaned';
    /** Never rewritten by DevTools: only its tracking file is updated (specs § 4.6.2 step 6). */
    case Manual = 'manual';

    /**
     * Whether the workflow belongs in the "À vérifier" section of the menu.
     */
    public function needsAttention(): bool
    {
        return self::Stale === $this || self::Orphaned === $this;
    }
}
