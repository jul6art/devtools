<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackProfile;

/**
 * Finds the entry points of one stack (specs § 4.3 step 3). Everything after that — grouping,
 * identifiers, files, tests — is identical for every adapter.
 */
interface AdapterInterface
{
    public function name(): string;

    public function extract(ProjectRoot $root, StackProfile $stack, Config $config, PhpReferenceExtractor $extractor): AdapterResult;
}
