<?php

declare(strict_types=1);

use Jul6Art\DevTools\Bridge\Rector\UseClockRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([UseClockRector::class]);
