<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * The sections of a group page, in order, and who writes each one (ADR-0045).
 *
 * Three, and no more: a group page orients, it does not explain. What describes a gesture belongs to the
 * page of that gesture — the mistake the grouping of ADR-0006 made was to put thirteen gestures on one
 * page, and a longer index would make it again.
 */
enum GroupPageSection: string
{
    case Summary = 'Résumé';
    case Routes = 'Routes';
    case States = 'États';

    /**
     * Claude writes it; in factual mode it holds "—".
     */
    public function writtenByClaude(): bool
    {
        return self::Summary === $this;
    }
}
