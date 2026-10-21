<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Angular;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\Angular\AngularAdapter;
use Jul6Art\DevTools\Inspection\Adapter\Angular\AngularReading;
use Jul6Art\DevTools\Inspection\Adapter\JavaScript\JavaScriptSources;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Node\NodeBridge;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Stack\StackDetector;
use Jul6Art\DevTools\Tests\Support\NodeDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AngularAdapter::class)]
#[CoversClass(AngularReading::class)]
#[CoversClass(JavaScriptSources::class)]
final class AngularAdapterTest extends TestCase
{
    public const string PROJECT = __DIR__.'/../../../Fixtures/projects/angular-minimal';

    private const string IONIC = __DIR__.'/../../../Fixtures/projects/ionic-tabs';

    public const array EXPECTED_WORKFLOWS = [
        'integration.order-service',
        'route.admin.users',
        'route.orders',
        'route.orders.id',
        'route.reports',
        'route.root',
        'ui.order-status-badge',
    ];

    public function testRoutesSharedComponentsAndHttpServicesAreItsWorkflowsInOneNodeProcess(): void
    {
        [$result, $adapter] = $this->inspect(self::PROJECT);

        self::assertSame(self::EXPECTED_WORKFLOWS, $this->ids($result));
        self::assertNull($adapter->fallbackCause);
        self::assertSame(1, $adapter->consoleCalls, 'ADR-0031: one Node process per scan.');

        foreach ($result->workflows as $workflow) {
            self::assertSame('native:angular', $workflow->source->value);
        }
    }

    public function testChildrenAndLazyLoadingConcatenateTheirPathsAndInheritTheGuards(): void
    {
        [$result] = $this->inspect(self::PROJECT);
        $detail = $this->workflow($result, 'route.orders.id');

        self::assertSame(['path' => '/orders/:id', 'security' => 'authGuard'], $detail->main->attributes);
        self::assertSame('src/app/orders/order-detail.component.ts', $detail->main->declaredIn->path);
        self::assertSame(['path' => '/admin/users', 'security' => 'authGuard'], $this->workflow($result, 'route.admin.users')->main->attributes, 'loadChildren followed, canMatch inherited.');
        self::assertContains('src/app/admin/admin.routes.ts', $this->paths($this->workflow($result, 'route.admin.users')));
        self::assertContains('src/app/auth/auth.guard.ts', $this->paths($detail), 'A guard is a file of the routes it guards.');
        self::assertSame(['path' => '/'], $this->workflow($result, 'route.root')->main->attributes);
        self::assertSame(Confidence::High, $detail->confidence);
    }

    public function testALazyComponentLoadedFromAComputedPathHasAMediumConfidenceAndAWarning(): void
    {
        [$result, $adapter] = $this->inspect(self::PROJECT);
        $reports = $this->workflow($result, 'route.reports');

        self::assertSame(Confidence::Medium, $reports->confidence);
        self::assertSame('src/app/app.routes.ts', $reports->main->declaredIn->path);
        self::assertContains('src/app/app.routes.ts: /reports loads its component from a path that is not literal; its workflow has a medium confidence.', $adapter->warnings);
    }

    public function testTheFilesOfAScreenAreItsImportsItsTemplateAndTheirPackages(): void
    {
        [$result] = $this->inspect(self::PROJECT);
        $list = $this->workflow($result, 'route.orders');

        self::assertSame([
            'src/app/app.config.ts',
            'src/app/app.routes.ts',
            'src/app/auth/auth.guard.ts',
            'src/app/orders/order-list.component.ts',
            'src/app/orders/order.service.ts',
            'src/app/shared/order-status-badge.component.css',
            'src/app/shared/order-status-badge.component.ts',
            'src/environments/environment.ts',
        ], $this->paths($list));
        self::assertContains('src/app/home/home.component.html', $this->paths($this->workflow($result, 'route.root')), 'templateUrl');
    }

    public function testNavigationComesFromRouterLinksAndRouterNavigate(): void
    {
        [$result] = $this->inspect(self::PROJECT);
        $edges = static fn (Workflow $workflow): array => array_map(static fn (Edge $edge): string => $edge->target.' '.$edge->label, $workflow->navigation);

        self::assertSame(['admin.users link', 'orders link'], $edges($this->workflow($result, 'route.root')), 'A literal routerLink and an array of literals.');
        self::assertSame(['orders.id link'], $edges($this->workflow($result, 'route.orders')), '[\'/orders\', order.id] is /orders/:id.');
        self::assertSame(['orders navigate'], $edges($this->workflow($result, 'route.orders.id')));
    }

    public function testAComponentTwoScreensImportIsAWorkflowAndAnHttpServiceListsItsEndpoints(): void
    {
        [$result] = $this->inspect(self::PROJECT);
        $badge = $this->workflow($result, 'ui.order-status-badge');
        $service = $this->workflow($result, 'integration.order-service');

        self::assertSame(['selector' => 'app-order-status-badge'], $badge->main->attributes);
        self::assertSame('OrderStatusBadgeComponent', $badge->title);
        self::assertSame(['endpoints' => 'GET /api/orders, GET /api/orders/:param, POST /api/orders/:param/cancel'], $service->main->attributes, 'environment.apiUrl resolved to /api.');
    }

    public function testIonicTabsAreAngularRoutesAndTheirTabsPageIsTheirLayout(): void
    {
        [$result] = $this->inspect(self::IONIC);

        self::assertSame(['route.tabs.tab1', 'route.tabs.tab2'], $this->ids($result));
        self::assertSame('src/app/tab1/tab1.page.ts', $this->workflow($result, 'route.tabs.tab1')->main->declaredIn->path);
        self::assertContains('src/app/tabs/tabs.page.ts', $this->paths($this->workflow($result, 'route.tabs.tab2')));
        self::assertContains('src/app/tabs/tabs.routes.ts', $this->paths($this->workflow($result, 'route.tabs.tab2')));
    }

    public function testWithoutNodeTheStackIsLeftToClaudeAndTheReportSaysWhy(): void
    {
        [, $adapter] = $this->inspect(self::PROJECT, new NodeBridge('devtools-no-such-node'));

        self::assertStringStartsWith('Node could not be started: `devtools-no-such-node` is not on the PATH.', (string) $adapter->fallbackCause);
        self::assertStringEndsWith('The stack is left to the Claude path.', (string) $adapter->fallbackCause);
        self::assertSame([], $adapter->workflows, 'The Claude path, which asks for a discovery.');
        self::assertSame([], $adapter->discovery);
    }

    /**
     * @return array{InspectionResult, AdapterResult}
     */
    private function inspect(string $project, ?NodeBridge $bridge = null): array
    {
        if (!$bridge instanceof NodeBridge) {
            NodeDependencies::install($project);
        }

        $root = new ProjectRoot($project);
        $stack = new StackDetector()->detect($root, new Config())->stacks[0];
        $extractor = new PhpReferenceExtractor();
        $adapter = new AngularAdapter($bridge)->extract($root, $stack, new Config(), $extractor);

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

        self::fail(\sprintf('No workflow "%s".', $id));
    }
}
