#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../lib/db.php';

$repository = new Acme\OrderRepository(db());

foreach (file($argv[1] ?? 'php://stdin') ?: [] as $line) {
    $repository->add(trim($line));
}
