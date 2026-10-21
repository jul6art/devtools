<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

/**
 * Where a feedback stands (ADR-0025): open until fixed, promoted to a rule (ADR-0026), or set aside on purpose.
 */
enum FeedbackStatus: string
{
    case Open = 'open';
    case Fixed = 'fixed';
    case Promoted = 'promoted';
    case WontFix = 'wont-fix';
}
