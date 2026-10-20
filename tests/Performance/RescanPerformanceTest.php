<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Performance;

use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Support\GeneratesLargeSymfonyProject;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Specs § 8: a re-scan without change of a 300-route project in under ten seconds, without AI — through
 * the real Symfony adapter, only the console's answers are replayed.
 */
#[CoversNothing]
#[Group('performance')]
final class RescanPerformanceTest extends TestCase
{
    use GeneratesLargeSymfonyProject;

    public function testAnUnchangedThreeHundredRouteProjectIsRescannedInUnderTenSeconds(): void
    {
        [$project, $runner] = $this->generateLargeProject();
        $pipeline = new InspectionPipeline(new AdapterResolver([new SymfonyAdapter($runner)]));

        $first = $pipeline->run(new InspectionOptions($project));
        self::assertSame([], $first->errors, implode("\n", $first->errors));
        self::assertSame(self::ROUTES, $first->count('created'));

        $started = microtime(true);
        $second = $pipeline->run(new InspectionOptions($project));
        $seconds = microtime(true) - $started;

        self::assertSame(self::ROUTES, $second->count('unchanged'));
        self::assertLessThan(10.0, $seconds, \sprintf('Re-scan took %.2f s.', $seconds));
        fwrite(\STDERR, \sprintf("\n[performance] re-scan of %d unchanged routes: %.2f s\n", self::ROUTES, $seconds));
    }
}
