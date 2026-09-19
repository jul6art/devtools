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
use Jul6Art\DevTools\Tracking\IndexGroup;
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
        self::assertSame(5, $report->count('created'));
        self::assertFileExists($project.'/docs/workflows/routes/order.new.md');
        self::assertFileExists($project.'/.devtools/workflows/routes/order.new.xml');
        self::assertFileExists($project.'/.devtools/index.xml');
        self::assertFileExists($project.'/.devtools/graph/files-to-workflows.xml');
        self::assertFileExists($project.'/.devtools/graph/workflows.mermaid');
        self::assertFileExists($project.'/.devtools/stack.xml');
        self::assertStringContainsString('## Routes (4)', (string) file_get_contents($project.'/docs/workflows/workflows.md'));
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
            $page = $project.'/docs/workflows/'.basename(\dirname($xml)).'/'.basename($xml, '.xml').'.md';
            self::assertSame([], new PageParser()->parse((string) file_get_contents($page))->conformityProblems(), $page);
        }

        self::assertCount(5, new XmlIndexStore(new WorkflowTypeRegistry())->read($project.'/.devtools/index.xml')->entries);
    }

    public function testThePagesGoWhereTheOptionSaysAndTheIndexRemembersIt(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');

        $this->pipeline()->run(new InspectionOptions($project, docs: 'documentation/flux/'));

        self::assertFileExists($project.'/documentation/flux/workflows.md');
        self::assertFileExists($project.'/documentation/flux/routes/order.new.md');
        self::assertFileDoesNotExist($project.'/docs/workflows/workflows.md');
        self::assertSame('documentation/flux', new XmlIndexStore(new WorkflowTypeRegistry())->read($project.'/.devtools/index.xml')->docs);
        self::assertStringContainsString('[Stack](../../.devtools/stack.xml)', (string) file_get_contents($project.'/documentation/flux/workflows.md'));
        self::assertFileExists($project.'/.devtools/workflows/routes/order.new.xml', 'The tracking stays in .devtools/.');
    }

    public function testADocumentationDirectoryOutsideTheProjectIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory of the project');

        new InspectionOptions($this->copyFixtureProject('symfony-minimal'), docs: '../elsewhere');
    }

    public function testADryRunWritesNothing(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $before = self::tree($project);

        $report = $this->pipeline()->run(new InspectionOptions($project, dryRun: true));

        self::assertSame($before, self::tree($project));
        self::assertSame(5, $report->count('created'));
        self::assertNull($report->path);
    }

    public function testOnlyOneTypeOfPageIsWrittenButTheMenuStaysComplete(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');

        $this->pipeline()->run(new InspectionOptions($project, only: 'commands'));

        self::assertSame(['app.import-catalog.xml'], array_map(basename(...), glob($project.'/.devtools/workflows/*/*') ?: []), 'Only the tracking of that type is written.');
        self::assertSame(['app.import-catalog.md'], array_map(basename(...), glob($project.'/docs/workflows/*/*') ?: []), 'And only its page.');
        self::assertStringContainsString('## Routes (4)', (string) file_get_contents($project.'/docs/workflows/workflows.md'));
    }

    public function testAnEntryPointThatDisappearedIsOrphanedAndItsPageKept(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $this->pipeline()->run(new InspectionOptions($project));

        $report = $this->pipeline(new FakeAdapter(['app_order_show']))->run(new InspectionOptions($project));

        self::assertSame(1, $report->count('orphaned'));
        self::assertFileExists($project.'/docs/workflows/routes/order.show.md');
        self::assertSame(TrackingStatus::Orphaned, new XmlTrackingStore(new WorkflowTypeRegistry())->read($project.'/.devtools/workflows/routes/order.show.xml')->status);
        self::assertStringContainsString('[`route.order.show`](routes/order.show.md) — orphelin', (string) file_get_contents($project.'/docs/workflows/workflows.md'));
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

    /**
     * ADR-0045: grouping by controller lays the pages out, it does not merge them. Every route keeps its
     * own page, under the directory of its controller, and the controller gets a README listing them.
     */
    public function testRoutesGroupedByControllerAreWrittenUnderTheirControllerWithAnIndex(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::groupByController($project);

        $report = $this->pipeline()->run(new InspectionOptions($project));

        self::assertSame(0, $report->exitCode());
        self::assertFileExists($project.'/docs/workflows/routes/order/new.md', 'A route keeps a page of its own.');
        self::assertFileExists($project.'/docs/workflows/routes/order/index.md');
        self::assertFileExists($project.'/docs/workflows/routes/order/README.md', 'The controller gets its index.');
        self::assertFileDoesNotExist($project.'/docs/workflows/routes/order.new.md', 'Nothing is left at the old flat path.');

        $readme = (string) file_get_contents($project.'/docs/workflows/routes/order/README.md');

        self::assertStringContainsString('## Routes', $readme);
        self::assertStringContainsString('(new.md)', $readme, 'The table links to the page of each route.');
        self::assertStringContainsString('## Résumé

—', $readme, 'Without Claude the summary stays empty.');

        $menu = (string) file_get_contents($project.'/docs/workflows/workflows.md');
        $routes = explode('## ', $menu)[1] ?? '';

        self::assertStringContainsString('(routes/order/README.md)', $routes, 'The menu lists the controller…');
        self::assertStringNotContainsString('(routes/order/new.md)', $routes, '…and not its routes, which the group page lists.');
        self::assertStringContainsString('· 3 routes ·', $routes);
        // "À vérifier" still links to the page of each workflow: it is about what a workflow needs, not
        // about how the menu is laid out.
        self::assertStringContainsString('(routes/order/new.md)', $menu);

        $index = new XmlIndexStore(new WorkflowTypeRegistry())->read($project.'/.devtools/index.xml');

        self::assertSame(
            ['routes/health/README.md', 'routes/order/README.md'],
            array_map(static fn (IndexGroup $group): string => $group->page(), $index->groups),
            'The index keeps one group per controller, sorted.',
        );
    }

    /**
     * The migration of a project that was documented flat: its pages move under their controller, and the
     * files they leave behind are deleted — the index points at the new ones, so nothing else would.
     */
    public function testTurningOnTheGroupingMovesThePagesAndLeavesNothingBehind(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        $this->pipeline()->run(new InspectionOptions($project));

        self::assertFileExists($project.'/docs/workflows/routes/order.new.md');

        self::groupByController($project);
        $this->pipeline()->run(new InspectionOptions($project));

        self::assertFileDoesNotExist($project.'/docs/workflows/routes/order.new.md', 'The page it left is removed.');
        self::assertFileExists($project.'/docs/workflows/routes/order/new.md');
        self::assertFileExists($project.'/docs/workflows/routes/order/README.md');
        self::assertStringContainsString('regroupement : la page change de dossier', (string) file_get_contents($project.'/docs/workflows/routes/order/new.md'), 'The move is one line of the history.');
    }

    /**
     * ⚠️ A controller whose last route was deleted leaves a README listing nothing, and a directory
     * with it. Found by deleting a probe route on a real project: `--prune` removed the page and the
     * tracking file, and the folder stayed — the pruned workflows never reached the group pages.
     */
    public function testPruningTheLastRouteOfAControllerRemovesItsReadmeAndItsDirectory(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::groupByController($project);
        $this->pipeline()->run(new InspectionOptions($project));

        self::assertFileExists($project.'/docs/workflows/routes/health/README.md');

        $this->pipeline(new FakeAdapter(['app_health']))->run(new InspectionOptions($project, prune: true));

        self::assertFileDoesNotExist($project.'/docs/workflows/routes/health/index.md', 'The page of the route goes.');
        self::assertFileDoesNotExist($project.'/docs/workflows/routes/health/README.md', 'The README of the emptied controller goes with it.');
        self::assertDirectoryDoesNotExist($project.'/docs/workflows/routes/health', 'And so does the directory: an empty folder is a question for nothing.');
        self::assertFileExists($project.'/docs/workflows/routes/order/README.md', 'A controller that still has routes is left alone.');
    }

    public function testASecondRunOfAGroupedProjectRewritesNothing(): void
    {
        $project = $this->copyFixtureProject('symfony-minimal');
        self::groupByController($project);
        $this->pipeline()->run(new InspectionOptions($project));

        $before = self::snapshotOf($project);
        $report = $this->pipeline()->run(new InspectionOptions($project));

        self::assertSame(0, $report->pagesWritten, 'An unchanged project rewrites no page, group pages included.');
        self::assertSame($before, self::snapshotOf($project));
    }

    /**
     * @return array<string, string>
     */
    private static function snapshotOf(string $project): array
    {
        $files = [];

        foreach ([...glob($project.'/docs/workflows/*.md') ?: [], ...glob($project.'/docs/workflows/*/*.md') ?: [], ...glob($project.'/docs/workflows/*/*/*.md') ?: [], ...glob($project.'/.devtools/*.xml') ?: []] as $file) {
            $files[substr($file, \strlen($project))] = (string) file_get_contents($file);
        }

        return $files;
    }

    private static function groupByController(string $project): void
    {
        if (!is_dir($project.'/.devtools')) {
            mkdir($project.'/.devtools', 0o777, true);
        }

        file_put_contents($project.'/.devtools/config.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <devtools xmlns="https://github.com/jul6art/devtools/schema/config/1" schema-version="1">
                <routes group="controller"/>
            </devtools>

            XML);
    }

    private function pipeline(FakeAdapter $adapter = new FakeAdapter()): InspectionPipeline
    {
        return new InspectionPipeline(new AdapterResolver([$adapter]), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')));
    }
}
