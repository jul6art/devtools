<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A JavaScript fixture's dependencies — TypeScript above all, which the Node bridge reads with (ADR-0031) —
 * installed on first run, from its lock file (network needed once, then npm's cache). Without Node or npm,
 * the test is skipped and says so.
 */
final class NodeDependencies
{
    public static function install(string $project): void
    {
        if (null === new ExecutableFinder()->find('node')) {
            TestCase::markTestSkipped('Node is not installed: the Node bridge cannot be exercised.');
        }

        if (is_file($project.'/node_modules/typescript/package.json')) {
            return;
        }

        $npm = new ExecutableFinder()->find('npm') ?? TestCase::markTestSkipped('npm is not installed: the fixture\'s TypeScript cannot be installed.');
        $install = new Process([$npm, is_file($project.'/package-lock.json') ? 'ci' : 'install', '--no-audit', '--no-fund', '--loglevel=error'], $project, timeout: 600);
        $install->run();

        if (!$install->isSuccessful()) {
            TestCase::markTestSkipped('The fixture\'s Node dependencies could not be installed (npm or network unavailable): '.$install->getErrorOutput());
        }
    }
}
