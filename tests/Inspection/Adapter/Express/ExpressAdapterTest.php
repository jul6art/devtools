<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Express;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\Express\ExpressAdapter;
use Jul6Art\DevTools\Inspection\Adapter\Express\ExpressReading;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Tests\Support\CopiesFixtureProjects;
use Jul6Art\DevTools\Tests\Support\NodeDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressAdapter::class)]
#[CoversClass(ExpressReading::class)]
final class ExpressAdapterTest extends TestCase
{
    use CopiesFixtureProjects;

    public const array EXPECTED_WORKFLOWS = [
        'async.emails',
        'async.nightly-report',
        'command.import-orders',
        'command.orders-cli',
        'route.delete.admin.orders.id',
        'route.get.health',
        'route.get.orders',
        'route.get.orders.id',
        'route.post.orders',
    ];

    private const string FIXTURES = __DIR__.'/../../../Fixtures/projects';

    /**
     * ADR-0033: the same application in JavaScript with CommonJS and in TypeScript with ESM, the same
     * workflows.
     */
    #[DataProvider('variants')]
    public function testRoutersScriptsWorkersAndSchedulesAreItsWorkflowsInOneNodeProcess(string $fixture, string $extension): void
    {
        [$result, $adapter] = $this->inspect($fixture);

        self::assertSame(self::EXPECTED_WORKFLOWS, $this->ids($result));
        self::assertNull($adapter->fallbackCause);
        self::assertSame(1, $adapter->consoleCalls);
        self::assertSame('src/controllers/orderController.'.$extension, $this->workflow($result, 'route.get.orders')->main->declaredIn->path, 'Where the handler is written.');
        self::assertSame('bin/orders-cli.'.$extension, $this->workflow($result, 'command.orders-cli')->main->declaredIn->path);
        self::assertSame('src/scripts/import-orders.'.$extension, $this->workflow($result, 'command.import-orders')->main->declaredIn->path, 'A script running a file of the project; `start` runs the server, which is the application.');

        foreach ($result->workflows as $workflow) {
            self::assertSame('native:express', $workflow->source->value);
            self::assertSame(Confidence::High, $workflow->confidence);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function variants(): iterable
    {
        yield 'JavaScript, CommonJS' => ['express-api', 'js'];
        yield 'TypeScript, ESM' => ['express-api-ts', 'ts'];
    }

    #[DataProvider('variants')]
    public function testAMountedRouterAddsItsPrefixAndItsMiddleware(string $fixture, string $extension): void
    {
        [$result] = $this->inspect($fixture);

        self::assertSame(['methods' => 'DELETE', 'path' => '/admin/orders/:id', 'security' => 'requireAuth, requireAdmin'], $this->workflow($result, 'route.delete.admin.orders.id')->main->attributes);
        self::assertSame(['methods' => 'POST', 'path' => '/orders', 'security' => 'requireAuth'], $this->workflow($result, 'route.post.orders')->main->attributes);
        self::assertSame(['methods' => 'GET', 'path' => '/health'], $this->workflow($result, 'route.get.health')->main->attributes);
        self::assertContains('src/app.'.$extension, $this->paths($this->workflow($result, 'route.get.orders')), 'The application mounting the router is a file of its routes.');
        self::assertContains('src/routes/orders.'.$extension, $this->paths($this->workflow($result, 'route.get.orders')));
        self::assertContains('src/services/orderService.'.$extension, $this->paths($this->workflow($result, 'route.get.orders')));
    }

    #[DataProvider('variants')]
    public function testAWorkerAScheduleAndAListenerOfTheProjectsEmitter(string $fixture, string $extension): void
    {
        [$result] = $this->inspect($fixture);
        $names = static fn (Workflow $workflow): array => array_map(static fn (Mechanism $mechanism): string => $mechanism->name.' '.$mechanism->event, $workflow->mechanisms);

        self::assertSame('worker', $this->workflow($result, 'async.emails')->main->kind);
        self::assertSame(['schedule' => '0 2 * * *'], $this->workflow($result, 'async.nightly-report')->main->attributes);
        self::assertSame(['notifyWarehouse order.created'], $names($this->workflow($result, 'route.post.orders')));
        self::assertSame([], $names($this->workflow($result, 'route.get.health')));
        self::assertSame(['test/orders.test.'.$extension], array_map(static fn (FileRef $test): string => $test->path, $this->workflow($result, 'route.get.orders')->tests), 'A test requesting the literal path.');
    }

    public function testARouteOnAComputedPathOrARouterNobodyMountsIsSaid(): void
    {
        NodeDependencies::install(self::FIXTURES.'/express-api');
        $project = $this->copyFixtureProject('express-api', withDependencies: true);
        file_put_contents($project.'/src/routes/reports.js', "const express = require('express');\nconst router = express.Router();\nconst base = process.env.REPORTS;\nrouter.get(base, (req, res) => res.end());\nmodule.exports = router;\n");
        [$result, $adapter] = $this->inspect($project);

        self::assertSame(Confidence::Medium, $this->workflow($result, 'route.get')->confidence);
        self::assertContains('src/routes/reports.js: a router no application mounts; its routes are documented from /.', $adapter->warnings);
        self::assertContains('src/routes/reports.js:4: a route path computed at run time; its workflow has a medium confidence.', $adapter->warnings);
    }

    public function testAJavaScriptProjectWithoutTypeScriptIsLeftToClaude(): void
    {
        [, $adapter] = $this->inspect(self::FIXTURES.'/node-express', install: false);

        self::assertStringStartsWith('TypeScript is not installed in this project', (string) $adapter->fallbackCause);
        self::assertSame([], $adapter->workflows);
    }

    /**
     * @return array{InspectionResult, AdapterResult}
     */
    private function inspect(string $fixture, bool $install = true): array
    {
        $project = str_contains($fixture, '/') ? $fixture : self::FIXTURES.'/'.$fixture;

        if ($install) {
            NodeDependencies::install($project);
        }

        $root = new ProjectRoot($project);
        $stack = new StackDetector()->detect($root, new Config())->stacks[0];
        $extractor = new PhpReferenceExtractor();
        $adapter = new ExpressAdapter()->extract($root, $stack, new Config(), $extractor);

        return [new WorkflowBuilder(new Config())->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor, $adapter->mechanisms, $adapter->graph, $adapter->tests)->result, $adapter];
    }

    /**
     * @return list<string>
     */
    private function ids(InspectionResult $result): array
    {
        $ids = array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows);
        sort($ids, \SORT_STRING);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function paths(Workflow $workflow): array
    {
        return array_map(static fn (FileRef $file): string => $file->path, $workflow->files);
    }

    private function workflow(InspectionResult $result, string $id): Workflow
    {
        foreach ($result->workflows as $workflow) {
            if ($id === (string) $workflow->id) {
                return $workflow;
            }
        }

        self::fail(\sprintf('No workflow "%s" among %s.', $id, implode(', ', $this->ids($result))));
    }
}
