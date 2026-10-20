<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * The sections of a workflow page, in order, and who writes each one (ADR-0044, which extends ADR-0043,
 * which replaced ADR-0008).
 *
 * This ownership is what the validation of Claude's drafts relies on (ADR-0011): a draft may only fill
 * the sections Claude owns. Facts — routes, history, links — are always rendered by DevTools.
 *
 * ⚠️ "Composants impliqués" and "Tests existants" are **gone**, against the § 4.5 of the specs which
 * lists them: on a real project they were 250 of a page's 344 lines, and they are already in the XML
 * tracking file, which is the one that links the workflow to its files. "Decisions" takes their place.
 */
enum PageSection: string
{
    case Summary = 'Summary';
    case Trigger = 'Trigger';
    case Journey = 'Journey';
    case Decisions = 'Decisions';
    case Data = 'Data';
    case CrossCutting = 'Cross-cutting mechanisms';
    case Attention = 'Points of attention';
    case Related = 'Related workflows';
    case History = 'History';
    /**
     * Where DevTools' part of "Decisions" ends and Claude's begins (ADR-0044). The state machine and the
     * navigation are facts DevTools draws from the code; the field diagrams are Claude's. A Markdown comment,
     * so that a reader sees one section, and a parser two.
     */
    public const string CLAUDE_BELOW = '<!-- Above: drawn by DevTools from the code. Below: written by Claude. -->';

    /**
     * Claude writes it; in factual mode it holds "—", except the cross-cutting mechanisms, which DevTools
     * fills from the model, and "Decisions", which opens with the state machine and the navigation
     * DevTools draws (ADR-0044) — "Navigation / states", the tenth section, is gone into it.
     */
    public function writtenByClaude(): bool
    {
        return match ($this) {
            self::Summary, self::Journey, self::Decisions, self::Data, self::CrossCutting, self::Attention => true,
            default => false,
        };
    }
}
