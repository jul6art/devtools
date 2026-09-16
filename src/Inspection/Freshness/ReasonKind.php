<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

enum ReasonKind: string
{
    case NoPreviousTracking = 'no-previous-tracking';
    case Forced = 'forced';
    case FilesChanged = 'files-changed';
    case FilesRemoved = 'files-removed';
    case FilesAdded = 'files-added';
    case PackageMajor = 'package-major';
    case EntryPointGone = 'entrypoint-gone';
    case EntryPointBack = 'entrypoint-back';
}
