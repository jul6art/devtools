<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * The sections of a workflow page, in order, and who writes each one (ADR-0043, which replaces ADR-0008).
 *
 * This ownership is what the validation of Claude's drafts relies on (ADR-0011): a draft may only fill
 * the sections Claude owns. Facts — routes, history, links — are always rendered by DevTools.
 *
 * ⚠️ "Composants impliqués" and "Tests existants" are **gone**, against the § 4.5 of the specs which
 * lists them: on a real project they were 250 of a page's 344 lines, and they are already in the XML
 * tracking file, which is the one that links the workflow to its files. "Décisions" takes their place.
 */
enum PageSection: string
{
    case Summary = 'Résumé';
    case Trigger = 'Déclencheur';
    case Journey = 'Parcours';
    case Navigation = 'Navigation / états';
    case Decisions = 'Décisions';
    case Data = 'Données';
    case CrossCutting = 'Mécanismes transverses';
    case Attention = "Points d'attention";
    case Related = 'Workflows liés';
    case History = 'Historique';

    /**
     * Claude writes it; in factual mode it holds "—", except the cross-cutting mechanisms, which DevTools
     * fills from the model.
     */
    public function writtenByClaude(): bool
    {
        return match ($this) {
            self::Summary, self::Journey, self::Decisions, self::Data, self::CrossCutting, self::Attention => true,
            default => false,
        };
    }
}
