<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$wipe = static function (string $directory): void {
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    @rmdir($directory);
};

// Start from a clean slate: the functional tests compile real containers into the
// system temp directory and a stale one would silently invalidate the assertions.
$wipe(sys_get_temp_dir().'/jul6art-devtools-tests');

// ADR-0041: the knowledge library is writable, and `resources/knowledge/` of a source checkout is its
// default. No test — nor any process a test launches — may deposit a sheet in this repository, so the
// whole suite is pointed at a temporary library, emptied first: a sheet left there by a previous run
// would answer a stack the test means to be unknown. putenv() is what a sub-process inherits;
// $_SERVER is what code reading the superglobal sees.
$knowledgeHome = sys_get_temp_dir().'/jul6art-devtools-knowledge';
$wipe($knowledgeHome);
putenv('DEVTOOLS_KNOWLEDGE_HOME='.$knowledgeHome);
$_SERVER['DEVTOOLS_KNOWLEDGE_HOME'] = $_ENV['DEVTOOLS_KNOWLEDGE_HOME'] = $knowledgeHome;
