<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * The sections of a workflow page, in the order of specs § 4.5, and who writes each one (ADR-0008).
 *
 * This ownership is what the validation of Claude's drafts relies on (ADR-0011): a draft may only fill
 * the sections Claude owns. Facts — files, routes, tests, history — are always rendered by DevTools.
 */
enum PageSection: string
{
    case Summary = 'Résumé';
    case Trigger = 'Déclencheur';
    case Journey = 'Parcours';
    case Navigation = 'Navigation / états';
    case Components = 'Composants impliqués';
    case Data = 'Données';
    case CrossCutting = 'Mécanismes transverses';
    case Attention = "Points d'attention";
    case Tests = 'Tests existants';
    case Related = 'Workflows liés';
    case History = 'Historique';

    /**
     * Claude writes it; in factual mode it holds "—" or, for the journey and cross-cutting mechanisms,
     * what DevTools can state on its own.
     */
    public function writtenByClaude(): bool
    {
        return match ($this) {
            self::Summary, self::Journey, self::Data, self::CrossCutting, self::Attention => true,
            default => false,
        };
    }
}
