<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Laravel;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\Laravel\Artisan;
use Jul6Art\DevTools\Inspection\Adapter\Laravel\ArtisanIntrospection;
use Jul6Art\DevTools\Inspection\Adapter\Laravel\LaravelAdapter;
use Jul6Art\DevTools\Inspection\Adapter\Laravel\StaticLaravelScanner;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LaravelAdapter::class)]
#[CoversClass(Artisan::class)]
#[CoversClass(ArtisanIntrospection::class)]
#[CoversClass(StaticLaravelScanner::class)]
final class LaravelAdapterTest extends TestCase
{
    public const string PROJECT = __DIR__.'/../../../Fixtures/projects/laravel-minimal';

    public const array EXPECTED_WORKFLOWS = [
        'async.orders-import',
        'async.send-order-confirmation',
        'command.orders.import',
        'data.migrations',
        'data.seeders',
        'route.get.about',
        'route.home',
        'route.orders.index',
        'route.orders.show',
        'route.orders.store',
        'ui.alert',
        'ui.cart-summary',
    ];

    public function testEveryEntryPointOfTheFixtureBecomesItsWorkflowInThreeArtisanCalls(): void
    {
        $runner = new RecordedArtisanRunner();
        [$result, $adapter] = $this->inspect($runner);

        self::assertSame(self::EXPECTED_WORKFLOWS, $this->ids($result));
        self::assertNull($adapter->fallbackCause);
        self::assertSame(3, $adapter->consoleCalls, 'ADR-0030: at most four Artisan processes, whatever the size of the project.');
        self::assertCount(3, $runner->runs);
        self::assertSame(['php', 'artisan', 'route:list', '--json', '--no-ansi'], $runner->runs[0]);

        foreach ($result->workflows as $workflow) {
            self::assertSame('native:laravel', $workflow->source->value);
        }

        self::assertSame(Confidence::High, $this->workflow($result, 'route.orders.index')->confidence);
    }

    public function testARouteWithoutANameIsNamedAfterItsMethodAndUriAndPackageRoutesAreLeftOut(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());
        $about = $this->workflow($result, 'route.get.about');

        self::assertSame(['methods' => 'GET', 'path' => '/about'], $about->main->attributes);
        self::assertSame('app/Http/Controllers/AboutController.php', $about->main->declaredIn->path);
        self::assertSame('GET /orders/{order}', $this->workflow($result, 'route.orders.show')->title);
        self::assertSame([], array_filter($this->ids($result), static fn (string $id): bool => str_contains($id, 'livewire')), 'The routes Livewire registers are not the project\'s.');
    }

    public function testSecurityComesFromTheMiddlewaresAndAPolicyIsAFileOfTheRoute(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());

        self::assertSame('auth', $this->workflow($result, 'route.orders.index')->main->attributes['security'] ?? null);
        self::assertSame('auth, can:create,App\Models\Order', $this->workflow($result, 'route.orders.store')->main->attributes['security'] ?? null);
        self::assertSame('auth, can:view,order', $this->workflow($result, 'route.orders.show')->main->attributes['security'] ?? null);
        self::assertArrayNotHasKey('security', $this->workflow($result, 'route.home')->main->attributes);
        self::assertContains('app/Policies/OrderPolicy.php', $this->paths($this->workflow($result, 'route.orders.show')));
        self::assertContains('app/Policies/OrderPolicy.php', $this->paths($this->workflow($result, 'route.orders.store')));
        self::assertNotContains('app/Policies/OrderPolicy.php', $this->paths($this->workflow($result, 'route.orders.index')));
    }

    public function testTheBladeViewsAndTheComponentsTheyRenderAreFilesOfTheRoute(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());
        $home = $this->paths($this->workflow($result, 'route.home'));

        foreach (['resources/views/home.blade.php', 'resources/views/layouts/app.blade.php', 'resources/views/components/alert.blade.php', 'app/View/Components/Alert.php', 'app/Livewire/CartSummary.php', 'routes/web.php', 'bootstrap/app.php', 'app/Providers/AppServiceProvider.php'] as $file) {
            self::assertContains($file, $home);
        }

        self::assertContains('resources/views/orders/_row.blade.php', $this->paths($this->workflow($result, 'route.orders.index')), 'An @include is followed.');
        self::assertContains('resources/views/about.blade.php', $this->paths($this->workflow($result, 'route.get.about')), 'View::make() renders a view too.');
    }

    public function testNavigationFollowsRedirectsAndTheRenderedViewsButNotTheLayout(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());
        $edges = static fn (Workflow $workflow): array => array_map(static fn (Edge $edge): string => $edge->target.' '.$edge->label, $workflow->navigation);

        self::assertSame(['orders.index link'], $edges($this->workflow($result, 'route.home')), 'The layout links home, which every page does.');
        self::assertSame(['orders.show link', 'orders.store link'], $edges($this->workflow($result, 'route.orders.index')));
        self::assertSame(['orders.show redirect'], $edges($this->workflow($result, 'route.orders.store')));
    }

    public function testAListenerIsAMechanismOfTheWorkflowsDispatchingItsEvent(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());
        $names = static fn (Workflow $workflow): array => array_map(static fn (Mechanism $mechanism): string => $mechanism->name.' '.$mechanism->event, $workflow->mechanisms);

        self::assertSame(['App\Listeners\NotifyWarehouse App\Events\OrderPlaced'], $names($this->workflow($result, 'route.orders.store')));
        self::assertSame([], $names($this->workflow($result, 'route.orders.index')));
        self::assertSame([], array_filter($this->ids($result), static fn (string $id): bool => str_starts_with($id, 'event.')), 'ADR-0043: a listener is no workflow.');
    }

    public function testTheScheduleIsAsyncAndRunsTheCommandItNames(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());
        $scheduled = $this->workflow($result, 'async.orders-import');

        self::assertSame('scheduled', $scheduled->main->kind);
        self::assertSame(['schedule' => 'daily'], $scheduled->main->attributes);
        self::assertSame('routes/console.php', $scheduled->main->declaredIn->path);
        self::assertSame(['command.orders.import'], array_map(static fn (mixed $id): string => (string) $id, $scheduled->dependsOn));
        self::assertSame('orders:import (daily)', $scheduled->title);
    }

    public function testJobsComponentsAndDataAreTheirWorkflows(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());

        self::assertSame('job', $this->workflow($result, 'async.send-order-confirmation')->main->kind);
        self::assertSame(['live' => 'true'], $this->workflow($result, 'ui.cart-summary')->main->attributes);
        self::assertContains('resources/views/livewire/cart-summary.blade.php', $this->paths($this->workflow($result, 'ui.cart-summary')));
        self::assertSame(['live' => 'false'], $this->workflow($result, 'ui.alert')->main->attributes);
        self::assertContains('database/migrations/2026_01_01_000000_create_orders_table.php', $this->paths($this->workflow($result, 'data.migrations')));
        self::assertContains('database/seeders/DatabaseSeeder.php', $this->paths($this->workflow($result, 'data.seeders')));
        self::assertSame(['app/Console/Commands/ImportOrders.php'], [$this->workflow($result, 'command.orders.import')->main->declaredIn->path]);
    }

    public function testATestRequestingTheRouteIsATestOfIt(): void
    {
        [$result] = $this->inspect(new RecordedArtisanRunner());

        self::assertSame(['tests/Feature/OrderControllerTest.php'], array_map(static fn (FileRef $test): string => $test->path, $this->workflow($result, 'route.orders.index')->tests));
    }

    /**
     * ADR-0030: Artisan cannot answer (no vendor/, a broken boot) — the code stands in for it, with the same
     * identifiers and a medium confidence, and the report says why.
     */
    public function testWhenArtisanCannotAnswerTheCodeStandsInForIt(): void
    {
        [$result, $adapter] = $this->inspect(new RecordedArtisanRunner(failure: new ConsoleFailed('Could not open input file: artisan')));

        self::assertSame(self::EXPECTED_WORKFLOWS, $this->ids($result));
        self::assertSame('Could not open input file: artisan', $adapter->fallbackCause);
        self::assertSame(Confidence::Medium, $this->workflow($result, 'route.orders.index')->confidence);
        self::assertSame('auth, can:create,App\Models\Order', $this->workflow($result, 'route.orders.store')->main->attributes['security'] ?? null, 'The group\'s middleware is followed.');
        self::assertSame('auth, can:view,order', $this->workflow($result, 'route.orders.show')->main->attributes['security'] ?? null);
        self::assertCount(1, $this->workflow($result, 'route.orders.store')->mechanisms, 'The listeners Laravel discovers are read from the code.');
    }

    public function testAnEventListThatFailsLeavesTheListenersToTheCodeAlone(): void
    {
        [$result, $adapter] = $this->inspect(new RecordedArtisanRunner(['events' => 1]));

        self::assertNull($adapter->fallbackCause, 'Routes and commands still come from Artisan.');
        self::assertStringStartsWith('The listeners come from the code: `artisan event:list --json` exited with code 1', $adapter->warnings[0] ?? '');
        self::assertCount(1, $this->workflow($result, 'route.orders.store')->mechanisms);
    }

    public function testArtisanAnsweringSomethingElseThanJsonIsAFailure(): void
    {
        [, $adapter] = $this->inspect(new RecordedArtisanRunner(['routes' => "Warning: the cache is stale\n[]"]));

        self::assertStringContainsString("did not answer with JSON; it printed first:\nWarning: the cache is stale", (string) $adapter->fallbackCause);
    }

    public function testTheConfiguredArtisanAndEnvironmentAreTheOnesRun(): void
    {
        $runner = new RecordedArtisanRunner();
        $this->inspect($runner, new Config(laravelArtisan: 'vendor/bin/sail artisan', laravelEnv: 'testing'));

        self::assertSame(['vendor/bin/sail', 'artisan', 'route:list', '--json', '--no-ansi', '--env=testing'], $runner->runs[0]);
    }

    /**
     * @return array{InspectionResult, AdapterResult}
     */
    private function inspect(RecordedArtisanRunner $runner, Config $config = new Config()): array
    {
        $root = GraphFixture::root(self::PROJECT);
        $stack = GraphFixture::stack(self::PROJECT);
        $extractor = new PhpReferenceExtractor();
        $adapter = new LaravelAdapter($runner)->extract($root, $stack, $config, $extractor);

        return [new WorkflowBuilder($config)->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor, $adapter->mechanisms)->result, $adapter];
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
