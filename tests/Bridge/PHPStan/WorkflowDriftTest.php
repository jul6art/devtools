<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Bridge\PHPStan;

use Jul6Art\DevTools\Bridge\PHPStan\WorkflowDrift;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Tests\Inspection\Pipeline\FakeAdapter;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0048: the drift shows up where the code is written, computed by the inspection and not by a second
 * extractor.
 *
 * The rule itself is exercised by a real PHPStan run — `tests/EndToEnd/PhpStanExtensionTest.php` — rather
 * than by a double of `Scope`, which has fifty-seven methods and would prove nothing about the wiring.
 */
#[CoversClass(WorkflowDrift::class)]
final class WorkflowDriftTest extends TestCase
{
    use CopiesFixtureProjects;
    use UsesTemporaryDirectory;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testAChangedFactIsReportedAtItsFileAndLine(): void
    {
        $project = $this->inspected();
        self::rename($project, "'quoted'", "'estimated'");

        $entries = self::drift()->of($project);

        self::assertCount(1, $entries);
        self::assertStringContainsString('modified decision App\Entity\Order::status', $entries[0]['message']);
        self::assertSame('src/Service/OrderPricing.php', $entries[0]['file']);
        self::assertSame(24, $entries[0]['line']);
        self::assertStringContainsString("workflows:accept 'App\\Entity\\Order::status'", $entries[0]['tip']);
    }

    public function testAProjectWithoutDevToolsHearsNothing(): void
    {
        $directory = $this->temporaryDirectory().'/plain';
        mkdir($directory, 0o777, true);

        self::assertSame([], self::drift()->of($directory));
    }

    public function testAProjectWithoutDriftHearsNothing(): void
    {
        self::assertSame([], self::drift()->of($this->inspected()));
    }

    public function testAnInspectionThatFailsSaysSoOnce(): void
    {
        $project = $this->inspected();
        file_put_contents($project.'/.devtools/config.xml', 'not xml at all');

        $entries = self::drift()->of($project);

        self::assertCount(1, $entries);
        self::assertStringContainsString('could not inspect', $entries[0]['message']);
    }

    private function inspected(): string
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::pipeline()->run(new InspectionOptions($project, prune: true, noAi: true));

        return $project;
    }

    private static function rename(string $project, string $from, string $to): void
    {
        $pricing = $project.'/src/Service/OrderPricing.php';
        file_put_contents($pricing, str_replace($from, $to, (string) file_get_contents($pricing)));
    }

    private static function pipeline(): InspectionPipeline
    {
        return new InspectionPipeline(new AdapterResolver([new FakeAdapter()]), new FrozenClock(new \DateTimeImmutable('2026-09-18T10:00:00+02:00')));
    }

    private static function drift(): WorkflowDrift
    {
        return new WorkflowDrift(self::pipeline());
    }
}
