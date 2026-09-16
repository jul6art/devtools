<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * How far a workflow can be trusted: high when a native adapter produced it, medium when Claude did
 * or a native adapter had to fall back, low when discovery only found a fragment (specs § 4.8, § 11).
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
