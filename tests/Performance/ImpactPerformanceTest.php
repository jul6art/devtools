<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Performance;

use Jul6Art\DevTools\Impact\ImpactResolver;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tests\Support\GeneratesLargeSymfonyProject;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0016: under 200 ms on a 300-route project — the budget that lets a hook ask before every edit
 * (ADR-0019). The worst case: a service every route traverses.
 */
#[CoversNothing]
#[Group('performance')]
final class ImpactPerformanceTest extends TestCase
{
    use GeneratesLargeSymfonyProject;

    public function testTheImpactOfAServiceEveryRouteTraversesIsReadInUnder200Milliseconds(): void
    {
        $directory = new DevToolsDirectory(new ProjectRoot($this->inspectedLargeProject()));

        $started = microtime(true);
        $impact = new ImpactResolver()->resolve($directory, ['src/Service/Catalog.php']);
        $milliseconds = 1000 * (microtime(true) - $started);

        self::assertCount(self::ROUTES, $impact->workflows);
        self::assertLessThan(200.0, $milliseconds, \sprintf('Impact took %.0f ms.', $milliseconds));
        fwrite(\STDERR, \sprintf("\n[performance] impact of a service shared by %d routes: %.0f ms\n", self::ROUTES, $milliseconds));
    }
}
