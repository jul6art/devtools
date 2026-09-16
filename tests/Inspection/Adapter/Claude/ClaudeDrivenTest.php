<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Claude;

use Jul6Art\DevTools\Ai\ApplyResult;
use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Clock\FrozenClock;
use Jul6Art\DevTools\Inspection\Adapter\Claude\ClaudeDrivenAdapter;
use Jul6Art\DevTools\Inspection\Adapter\Claude\DiscoveryBrief;
use Jul6Art\DevTools\Inspection\Adapter\Claude\XmlDiscoveryBriefStore;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\AdapterResolver;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\Serialization\ModelXmlSerializer;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rendering\PageParser;
use Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony\RecordedConsoleRunner;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\GitRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ClaudeDrivenAdapter::class)]
#[CoversClass(DiscoveryBrief::class)]
#[CoversClass(XmlDiscoveryBriefStore::class)]
final class ClaudeDrivenTest extends TestCase
{
    use CopiesFixtureProjects;

    private const string DRAFTS = __DIR__.'/../../../Fixtures/drafts';

    public function testAnExpressProjectIsDocumentedThroughKnowledgeThenDiscovery(): void
    {
        $project = $this->copyFixtureProject('node-express');

        $this->inspect($project);
        self::assertFileExists($project.'/.devtools/pending/knowledge.express-4.brief.xml');
        self::assertFileDoesNotExist($project.'/.devtools/pending/discovery.project.brief.xml', 'No discovery without the knowledge of the stack.');

        $this->draft($project, 'node-express/knowledge.express-4.draft.md', 'knowledge.express-4.draft.md');
        self::assertSame(['knowledge.express-4'], $this->apply($project)->accepted);

        $this->inspect($project);
        $brief = new XmlDiscoveryBriefStore()->read($project.'/.devtools/pending/discovery.project.brief.xml');
        self::assertSame('.', $brief->root);
        self::assertSame(['src'], $brief->sourceDirectories);
        self::assertSame('.devtools/knowledge/express-4.md', $brief->knowledgePath);
        self::assertSame([], $brief->limitedTo);
        self::assertFileExists($brief->schemaPath);

        $this->draft($project, 'node-express/discovery.project.draft.xml', 'discovery.project.draft.xml');
        self::assertSame(['discovery.project'], $this->apply($project)->accepted);
        self::assertFileExists($project.'/.devtools/discovery/project.xml');

        $report = $this->inspect($project, noAi: true);

        self::assertSame(['route.get-health', 'route.get-orders', 'route.post-orders'], $this->ids($project));
        self::assertSame(3, $report->count('created'));
        self::assertStringContainsString('confiance moyenne', (string) file_get_contents($project.'/.devtools/workflows.md'));

        foreach (glob($project.'/.devtools/workflows/*/*.md') ?: [] as $page) {
            self::assertSame([], new PageParser()->parse((string) file_get_contents($page))->conformityProblems(), $page);
        }

        $this->assertMatchesExpected($project, 'node-express');
    }

    public function testAnAngularProjectIsDocumentedTheSameWay(): void
    {
        $project = $this->copyFixtureProject('angular-minimal');
        $this->inspect($project);
        $this->draft($project, 'angular-minimal/knowledge.angular-18.draft.md', 'knowledge.angular-18.draft.md');
        $this->apply($project);
        $this->inspect($project);
        $this->draft($project, 'angular-minimal/discovery.project.draft.xml', 'discovery.project.draft.xml');
        self::assertSame(['discovery.project'], $this->apply($project)->accepted);

        $this->inspect($project, noAi: true);

        self::assertSame(['integration.orderservice', 'route.orders', 'route.orders-id'], $this->ids($project));

        foreach ($this->discovered($project)->workflows as $workflow) {
            self::assertSame(Confidence::Medium, $workflow->confidence, 'Whatever the draft declares, the Claude path is medium confidence.');
            self::assertSame('claude', $workflow->source->value);
        }
    }

    /**
     * @param callable(string): string $mutation
     */
    #[DataProvider('refusals')]
    public function testADiscoveryDraftBreakingARuleIsRefusedAndNothingIsWritten(callable $mutation, string $expected): void
    {
        $project = $this->copyFixtureProject('node-express');
        $this->inspect($project);
        $this->draft($project, 'node-express/knowledge.express-4.draft.md', 'knowledge.express-4.draft.md');
        $this->apply($project);
        $this->inspect($project);
        file_put_contents($project.'/.devtools/pending/discovery.project.draft.xml', $mutation((string) file_get_contents(self::DRAFTS.'/node-express/discovery.project.draft.xml')));

        $result = $this->apply($project);

        self::assertStringContainsString($expected, implode("\n", $result->refused['discovery.project'] ?? []));
        self::assertFileDoesNotExist($project.'/.devtools/discovery/project.xml');
    }

    /**
     * @return iterable<string, array{callable(string): string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'file that does not exist' => [static fn (string $xml): string => str_replace('src/services/orderService.js', 'src/services/invoiceService.js', $xml), 'src/services/invoiceService.js does not exist'];
        yield 'file outside the stack root' => [static fn (string $xml): string => str_replace('path="src/app.js" role="config"', 'path="../../../../etc/hosts" role="config"', $xml), 'outside the project root'];
        yield 'invalid identifier' => [static fn (string $xml): string => str_replace('id="route.get-orders"', 'id="Route Orders"', $xml), 'line'];
        yield 'collision within the draft' => [static fn (string $xml): string => str_replace('name="POST /orders"', 'name="GET /orders"', $xml), 'claimed by two entry points'];
        yield 'collision across files' => [static fn (string $xml): string => str_replace('name="GET /health"', 'name="GET /orders"', $xml), 'claimed by two entry points'];
    }

    public function testANewFileAsksForALimitedDiscoveryAndAChangedCoveredFileDoesNot(): void
    {
        $project = $this->documentedExpressProject();
        $repository = GitRepository::initialise($project);

        file_put_contents($project.'/src/services/orderService.js', "\n// changed\n", \FILE_APPEND);
        $repository->commitAll('change a covered file');
        $this->inspect($project);
        self::assertFileDoesNotExist($project.'/.devtools/pending/discovery.project.brief.xml');

        file_put_contents($project.'/src/routes/invoices.js', "const express = require('express');\nmodule.exports = express.Router().get('/', (req, res) => res.json([]));\n");
        $repository->commitAll('a new router');
        $this->inspect($project);

        self::assertSame(['src/routes/invoices.js'], new XmlDiscoveryBriefStore()->read($project.'/.devtools/pending/discovery.project.brief.xml')->limitedTo);
    }

    public function testAMonorepoDocumentsItsSymfonyAndItsAngularStacksInOneFolder(): void
    {
        $project = $this->copyFixtureProject('monorepo');
        $angularKnowledge = str_replace('# Angular 18', '# Angular 22', (string) file_get_contents(self::DRAFTS.'/angular-minimal/knowledge.angular-18.draft.md'));

        $this->inspect($project, monorepo: true);
        file_put_contents($project.'/.devtools/pending/knowledge.angular-22.draft.md', $angularKnowledge);
        $this->apply($project);
        $this->inspect($project, monorepo: true);
        file_put_contents($project.'/.devtools/pending/discovery.front.draft.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <inspection xmlns="https://github.com/jul6art/devtools/schema/inspection-model/1" schema-version="1" stack="angular-22">
              <workflows>
                <workflow id="route.items" type="routes" confidence="medium" source="claude">
                  <title>/items</title>
                  <main kind="route" name="items">
                    <declared-in path="front/src/app/app.routes.ts" role="config"/>
                    <attribute name="path" value="/items"/>
                  </main>
                  <files>
                    <file path="front/src/app/app.routes.ts" role="config"/>
                    <file path="front/src/app/item-list.component.ts" role="component"/>
                  </files>
                </workflow>
              </workflows>
            </inspection>
            XML);
        $valid = (string) file_get_contents($project.'/.devtools/pending/discovery.front.draft.xml');
        file_put_contents($project.'/.devtools/pending/discovery.front.draft.xml', str_replace('<file path="front/src/app/item-list.component.ts" role="component"/>', '<file path="api/src/Controller/ItemController.php" role="controller"/>', $valid));
        self::assertStringContainsString('api/src/Controller/ItemController.php is outside the stack root "front"', implode("\n", $this->apply($project)->refused['discovery.front'] ?? []), 'A stack\'s discovery cannot claim a file of another stack.');

        file_put_contents($project.'/.devtools/pending/discovery.front.draft.xml', $valid);
        self::assertSame(['discovery.front'], $this->apply($project)->accepted);

        $report = $this->inspect($project, noAi: true, monorepo: true);

        self::assertSame(['route.api.item.list', 'route.items'], $this->ids($project));
        self::assertCount(2, $report->stacks);
    }

    private function documentedExpressProject(): string
    {
        $project = $this->copyFixtureProject('node-express');
        $this->inspect($project);
        $this->draft($project, 'node-express/knowledge.express-4.draft.md', 'knowledge.express-4.draft.md');
        $this->apply($project);
        $this->inspect($project);
        $this->draft($project, 'node-express/discovery.project.draft.xml', 'discovery.project.draft.xml');
        $this->apply($project);
        $this->inspect($project, noAi: true);

        return $project;
    }

    private function inspect(string $project, bool $noAi = false, bool $monorepo = false): InspectionReport
    {
        $adapters = $monorepo
            ? [new SymfonyAdapter(new RecordedConsoleRunner(failure: new ConsoleFailed('no vendor in this fixture'))), new ClaudeDrivenAdapter()]
            : [new ClaudeDrivenAdapter()];
        $report = new InspectionPipeline(new AdapterResolver($adapters), new FrozenClock(new \DateTimeImmutable('2026-09-16T15:00:00+02:00')))->run(new InspectionOptions($project, noAi: $noAi));
        self::assertSame([], $report->errors, implode("\n", $report->errors));

        return $report;
    }

    private function apply(string $project): ApplyResult
    {
        return new DraftApplier(new FrozenClock(new \DateTimeImmutable('2026-09-16T15:30:00+02:00')))->apply(new ProjectRoot($project));
    }

    private function draft(string $project, string $recorded, string $name): void
    {
        copy(self::DRAFTS.'/'.$recorded, $project.'/.devtools/pending/'.$name);
    }

    /**
     * @return list<string>
     */
    private function ids(string $project): array
    {
        $ids = array_map(static fn (string $file): string => basename($file, '.xml'), glob($project.'/.devtools/workflows/*/*.xml') ?: []);
        $types = array_map(static fn (string $file): string => basename(\dirname($file)), glob($project.'/.devtools/workflows/*/*.xml') ?: []);
        $prefixes = ['routes' => 'route', 'integrations' => 'integration'];
        $full = array_map(static fn (string $id, string $type): string => $prefixes[$type].'.'.$id, $ids, $types);
        sort($full);

        return $full;
    }

    private function discovered(string $project): InspectionResult
    {
        return new ModelXmlSerializer(new WorkflowTypeRegistry())->deserialize((string) file_get_contents($project.'/.devtools/discovery/project.xml'));
    }

    private function assertMatchesExpected(string $project, string $name): void
    {
        $expected = __DIR__.'/../../../Fixtures/expected/'.$name.'/.devtools';
        $actual = array_filter(self::tree($project.'/.devtools'), static fn (string $path): bool => !preg_match('#^(reports|pending|schemas)/#', $path), \ARRAY_FILTER_USE_KEY);
        $actual = array_map(static fn (string $content): string => (string) preg_replace('/tool="devtools [^"]*"/', 'tool="devtools"', $content), $actual);

        if ('1' === getenv('DEVTOOLS_UPDATE_SNAPSHOTS')) {
            foreach ($actual as $path => $content) {
                new Filesystem()->dumpFile($expected.'/'.$path, $content);
            }
        }

        $stored = array_filter(self::tree($expected), static fn (string $path): bool => !preg_match('#^(reports|pending|schemas)/#', $path), \ARRAY_FILTER_USE_KEY);
        self::assertSame($stored, $actual);
    }
}
