<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tracking;

enum GenerationMode: string
{
    /** The page's descriptive sections were written by Claude and validated (ADR-0011). */
    case Ai = 'ai';
    /** The page is factual only (ADR-0008). */
    case NoAi = 'no-ai';
}
