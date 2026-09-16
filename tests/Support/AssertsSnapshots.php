<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Compares output with a versioned snapshot (ADR-0001).
 *
 * `DEVTOOLS_UPDATE_SNAPSHOTS=1 composer test` rewrites the snapshots instead of comparing — and the
 * resulting diff is read before it is committed: a snapshot updated without reading is a test that
 * approves whatever the code does.
 */
trait AssertsSnapshots
{
    public static function assertMatchesSnapshot(string $actual, string $snapshotPath): void
    {
        if ('1' === getenv('DEVTOOLS_UPDATE_SNAPSHOTS')) {
            if (!is_dir(\dirname($snapshotPath))) {
                mkdir(\dirname($snapshotPath), 0o777, true);
            }

            file_put_contents($snapshotPath, $actual);
        }

        Assert::assertFileExists($snapshotPath, 'Snapshot missing: run DEVTOOLS_UPDATE_SNAPSHOTS=1 composer test, then read the file before committing it.');
        Assert::assertStringEqualsFile($snapshotPath, $actual);
    }
}
