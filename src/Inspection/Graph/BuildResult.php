<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Model\InspectionResult;

final readonly class BuildResult
{
    /**
     * @param list<string> $warnings files that could not be analysed, named — they never stop a scan
     */
    public function __construct(public InspectionResult $result, public array $warnings)
    {
    }
}
