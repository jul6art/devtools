<?php

declare(strict_types=1);

use Jul6Art\DevTools\Tests\Support\CoverageThresholds;

// composer coverage: fails when a floor of ADR-0015 is not reached.
require __DIR__.'/../../vendor/autoload.php';

$report = $argv[1] ?? 'var/coverage/clover.xml';
$failures = new CoverageThresholds(dirname(__DIR__, 2))->failures(is_file($report) ? (string) file_get_contents($report) : '');

foreach ($failures as $failure) {
    fwrite(\STDERR, $failure."\n");
}

echo [] === $failures ? "Coverage floors reached.\n" : '';

exit([] === $failures ? 0 : 1);
