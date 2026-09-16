<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Pipeline;

use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectLock;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tracking\TrackingStatus;
use Jul6Art\DevTools\Tracking\XmlIndexStore;
use Jul6Art\DevTools\Tracking\XmlTrackingStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspectionPipeline::class)]
#[CoversClass(InspectionOptions::class)]
#[CoversClass(InspectionReport::class)]
#[CoversClass(AdapterResolver::class)]
#[CoversClass(ProjectLock::class)]
final class InspectionPipelineTest extends TestCase
{
    use CopiesFixtureProjects;

    public function testAFirstRunDocumentsEveryWorkflow(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $report = $this->pipeline()->run(new InspectionOptions($project));

        self::assertSame(0, $report->exitCode());
        self::assertSame(6, $report->count('created'));
        self::assertFileExists($project.'/.devtools/workflows/routes/order.new.md');
        self::assertFileExists($project.'/.devtools/workflows/routes/order.new.xml');
        self::assertFileExists($project.'/.devtools/index.xml');
        self::assertFileExists($project.'/.devtools/graph/files-to-workflows.xml');
        self::assertFileExists($project.'/.devtools/graph/workflows.mermaid');
        self::assertFileExists($project.'/.devtools/stack.xml');
        self::assertStringContainsString('## Routes (4)', (string) file_get_contents($project.'/.devtools/workflows.md'));
        self::assertNotNull($report->path, 'A report is written.');
        self::assertFileExists($report->path);
    }

    public function testEveryWrittenDocumentIsValidAndEveryPageConforms(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $this->pipeline()->run(new InspectionOptions($project));
        $tracking = new XmlTrackingStore(new WorkflowTypeRegistry());

        foreach (glob($project.'/.devtools/workflows/*/*.xml') ?: [] as $xml) {
            $document = $tracking->read($xml);
            self::assertSame(TrackingStatus::Fresh, $document->status);
            self::assertSame([], new PageParser()->parse((string) file_get_contents(substr($xml, 0, -4).'.md'))->conformityProblems(), $xml);
        }

        self::assertCount(6, new XmlIndexStore(new WorkflowTypeRegistry())->read($project.'/.devtools/index.xml')->entries);
    }

    public function testADryRunWritesNothing(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $before = self::tree($project);

        $report = $this->pipeline()->run(new InspectionOptions($project, dryRun: true));

        self::assertSame($before, self::tree($project));
        self::assertSame(6, $report->count('created'));
        self::assertNull($report->path);
    }

    public function testOnlyOneTypeOfPageIsWrittenButTheMenuStaysComplete(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');

        $this->pipeline()->run(new InspectionOptions($project, only: 'commands'));

        self::assertSame(['app.import-catalog.md', 'app.import-catalog.xml'], array_map(basename(...), glob($project.'/.devtools/workflows/*/*') ?: []));
        self::assertStringContainsString('## Routes (4)', (string) file_get_contents($project.'/.devtools/workflows.md'));
    }

    public function testAnEntryPointThatDisappearedIsOrphanedAndItsPageKept(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $this->pipeline()->run(new InspectionOptions($project));

        $report = $this->pipeline(new FakeAdapter(['app_order_show']))->run(new InspectionOptions($project));

        self::assertSame(1, $report->count('orphaned'));
        self::assertFileExists($project.'/.devtools/workflows/routes/order.show.md');
        self::assertSame(TrackingStatus::Orphaned, new XmlTrackingStore(new WorkflowTypeRegistry())->read($project.'/.devtools/workflows/routes/order.show.xml')->status);
        self::assertStringContainsString('[`route.order.show`](workflows/routes/order.show.md) — orphelin', (string) file_get_contents($project.'/.devtools/workflows.md'));
    }

    public function testASecondRunConcurrentWithTheFirstIsRefused(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $this->pipeline()->run(new InspectionOptions($project));
        $held = ProjectLock::acquire($project.'/.devtools');

        try {
            $report = $this->pipeline()->run(new InspectionOptions($project));
        } finally {
            $held->release();
        }

        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('Another inspection is running', implode("\n", $report->errors));
    }

    public function testAFallbackIsAWarningShownFirst(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');

        $report = $this->pipeline(new FakeAdapter(fallbackCause: '`debug:router` exited with code 255'))->run(new InspectionOptions($project));

        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString("> ⚠️ **Symfony (.) — the console could not answer; attributes were read instead, with medium confidence.**\n> `debug:router` exited with code 255", (string) file_get_contents((string) $report->path));
    }

    public function testAnInvalidConfigurationIsAnError(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        mkdir($project.'/.devtools');
        file_put_contents($project.'/.devtools/config.xml', '<devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1"><graph depth="deep"/></devtools>');

        $report = $this->pipeline()->run(new InspectionOptions($project));

        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('config.xml', implode("\n", $report->errors));
    }

    public function testAStackWithoutAnAdapterIsSkippedWithAWarning(): void
    {
        $report = $this->pipeline(new FakeAdapter(findsNothing: true))->run(new InspectionOptions($this->copyFixtureProject('monorepo')));

        self::assertSame(1, $report->exitCode(), 'The Symfony stack is documented, the Angular one is skipped.');
        self::assertStringContainsString('front', implode("\n", $report->warnings));

        $onlyUnsupported = $this->pipeline()->run(new InspectionOptions($this->copyFixtureProject('node-express')));

        self::assertSame(2, $onlyUnsupported->exitCode(), 'Nothing could be inspected.');
    }

    private function pipeline(FakeAdapter $adapter = new FakeAdapter()): InspectionPipeline
    {
        return new InspectionPipeline(new AdapterResolver([$adapter]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')));
    }
}
