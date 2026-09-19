<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Adapter\Symfony;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Inspection\Adapter\AdapterResult;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleFailed;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\ConsoleIntrospection;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\StaticSymfonyScanner;
use Jul6Art\DevTools\Inspection\Adapter\Symfony\SymfonyAdapter;
use Jul6Art\DevTools\Inspection\Graph\PhpReferenceExtractor;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\Transition;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Tests\Inspection\Graph\GraphFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyAdapter::class)]
#[CoversClass(ConsoleIntrospection::class)]
#[CoversClass(StaticSymfonyScanner::class)]
#[CoversClass(AdapterResult::class)]
final class SymfonyAdapterTest extends TestCase
{
    public const array EXPECTED_WORKFLOWS = [
        'async.order-created',
        'command.app.import-catalog',
        'data.migrations',
        'route.health',
        'route.order.index',
        'route.order.new',
        'route.order.show',
        'route.order.validate',
        'ui.cart-summary',
    ];

    public function testEveryEntryPointOfTheFixtureBecomesItsWorkflow(): void
    {
        [$result, $adapter] = $this->inspect(new RecordedConsoleRunner());

        self::assertSame(self::EXPECTED_WORKFLOWS, $this->ids($result));
        self::assertNull($adapter->fallbackCause);
        self::assertSame(['app_status'], array_map(static fn (EntryPoint $satellite): string => $satellite->name, $this->workflow($result, 'route.health')->satellites));
        $async = $this->workflow($result, 'async.order-created');

        self::assertSame('App\MessageHandler\NotifyOnOrderCreated', $async->main->name);
        self::assertSame(
            ['App\MessageHandler\OrderCreatedHandler'],
            array_map(static fn (EntryPoint $satellite): string => $satellite->name, $async->satellites),
            'Every handler of a message is one workflow: what happens when it is published.',
        );

        foreach ($result->workflows as $workflow) {
            self::assertSame(Confidence::High, $workflow->confidence, (string) $workflow->id);
            self::assertSame('native:symfony', $workflow->source->value);
        }
    }

    public function testSecurityCombinesAccessControlAndIsGranted(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner());

        self::assertSame('ROLE_USER', $this->workflow($result, 'route.order.index')->main->attributes['security'] ?? null);
        self::assertSame('ROLE_USER, ROLE_OPERATOR', $this->workflow($result, 'route.order.new')->main->attributes['security'] ?? null);
        self::assertArrayNotHasKey('security', $this->workflow($result, 'route.health')->main->attributes, 'A public route has no security attribute.');
        self::assertSame(['methods' => 'GET|POST', 'path' => '/orders/new', 'security' => 'ROLE_USER, ROLE_OPERATOR'], $this->workflow($result, 'route.order.new')->main->attributes);
    }

    public function testNavigationFollowsRedirectsAndTheRenderedTemplatesButNotTheLayout(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner());

        self::assertEquals([new Edge('app_order_show', 'redirect')], $this->workflow($result, 'route.order.new')->navigation);
        self::assertEquals([new Edge('app_order_new', 'link'), new Edge('app_order_show', 'link')], $this->workflow($result, 'route.order.index')->navigation);
    }

    public function testTheStateMachineARouteDrivesIsAttached(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner());
        $states = $this->workflow($result, 'route.order.validate')->states;

        self::assertNotNull($states);
        self::assertSame('order', $states->name);
        self::assertSame(['draft', 'validated', 'shipped'], $states->places);
        self::assertSame(['validate', 'ship'], array_map(static fn (Transition $transition): string => $transition->name, $states->transitions));
        self::assertNull($this->workflow($result, 'route.order.index')->states);
    }

    /**
     * A kernel listener runs inside every route, so it is a mechanism of each of them — not a workflow
     * of its own with a page nobody opens (ADR-0043).
     *
     * ⚠️ A Doctrine listener runs inside every workflow that reaches an entity, and the console never
     * mentions it: it lives on Doctrine's event manager, not on the dispatcher. It is read from its
     * attribute even when the console answers — found on a real project, where deleting one changed
     * nothing in the documentation.
     */
    public function testEveryRouteCarriesTheProjectsListenersAsMechanisms(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner());

        foreach ($result->workflows as $workflow) {
            if ('routes' !== $workflow->type->name) {
                continue;
            }

            $names = array_map(static fn (Mechanism $mechanism): string => $mechanism->name, $workflow->mechanisms);

            self::assertContains('App\\EventListener\\LocaleListener', $names, (string) $workflow->id);
            self::assertSame('kernel.request', $workflow->mechanisms[0]->event);
        }

        $touchesAnEntity = $this->workflow($result, 'route.order.new');

        self::assertContains(
            'App\\EventListener\\TotalsListener',
            array_map(static fn (Mechanism $mechanism): string => $mechanism->name, $touchesAnEntity->mechanisms),
            'The Doctrine listener comes from its attribute, not from the console.',
        );

        self::assertSame(
            ['App\\EventListener\\TotalsListener'],
            array_map(static fn (Mechanism $mechanism): string => $mechanism->name, $this->workflow($result, 'command.app.import-catalog')->mechanisms),
            'A kernel listener does not run inside a command — a Doctrine one does, as soon as the command reaches an entity.',
        );
    }

    public function testNoWorkflowOfTypeEventsIsProducedAnyMore(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner());

        self::assertSame([], array_values(array_filter(array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows), static fn (string $id): bool => str_starts_with($id, 'event.'))));
    }

    public function testATestRequestingTheRoutesLiteralPathIsOneOfItsTests(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner());

        self::assertContains('tests/Controller/OrderControllerTest.php', array_map(static fn (FileRef $file): string => $file->path, $this->workflow($result, 'route.order.index')->tests));
    }

    public function testStructuralConfigurationIsTracked(): void
    {
        [$result] = $this->inspect(new RecordedConsoleRunner());
        $files = fn (string $id): array => array_map(static fn (FileRef $file): string => $file->path, $this->workflow($result, $id)->files);

        self::assertContains('config/routes.yaml', $files('route.order.new'));
        self::assertContains('config/packages/security.yaml', $files('route.order.new'));
        self::assertContains('config/services.yaml', $files('command.app.import-catalog'));
        self::assertContains('config/packages/framework.yaml', $files('route.order.validate'), 'The state machine is declared there.');
        self::assertContains('templates/components/CartSummary.html.twig', $files('ui.cart-summary'));
        self::assertContains('src/Message/OrderCreated.php', $files('async.order-created'));
    }

    public function testTheConsoleIsQueriedAtMostSixTimes(): void
    {
        $runner = new RecordedConsoleRunner();
        $this->inspect($runner);

        self::assertCount(6, $runner->runs);
    }

    public function testThreeHundredRoutesCostTheSameSixProcesses(): void
    {
        $routes = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/console/symfony-minimal/router.txt'), true);
        self::assertIsArray($routes);
        $template = $routes['app_order_index'];
        self::assertIsArray($template);

        for ($i = 0; $i < 300; ++$i) {
            $routes['app_generated_'.$i] = [...$template, 'path' => '/generated/'.$i];
        }

        $runner = new RecordedConsoleRunner(['router' => (string) json_encode($routes)]);
        $this->inspect($runner);

        self::assertCount(6, $runner->runs);
    }

    public function testWithoutAWorkingConsoleTheStaticScanStandsInAndSaysSo(): void
    {
        [$result, $adapter] = $this->inspect(new RecordedConsoleRunner(failure: new ConsoleFailed('bin/console exited with code 255: Class "Doctrine\Bundle" not found')));

        self::assertStringContainsString('Class "Doctrine\Bundle" not found', (string) $adapter->fallbackCause);
        self::assertSame(self::EXPECTED_WORKFLOWS, $this->ids($result), 'Attributes carry every entry point of this fixture.');
        self::assertSame(Confidence::Medium, $this->workflow($result, 'route.order.new')->confidence);
        self::assertSame('ROLE_OPERATOR', $this->workflow($result, 'route.order.new')->main->attributes['security'] ?? null, 'Only IsGranted is visible statically.');
    }

    public function testAPollutedOutputFallsBackWithTheOffendingLines(): void
    {
        [, $adapter] = $this->inspect(new RecordedConsoleRunner(['router' => "Warning: Undefined variable \$x in config/bundles.php on line 4\n{}"]));

        self::assertStringContainsString('Warning: Undefined variable $x', (string) $adapter->fallbackCause);
    }

    /**
     * @return array{InspectionResult, AdapterResult}
     */
    private function inspect(RecordedConsoleRunner $runner): array
    {
        $root = GraphFixture::root();
        $stack = GraphFixture::stack();
        $extractor = new PhpReferenceExtractor();
        $adapter = new SymfonyAdapter($runner)->extract($root, $stack, new Config(), $extractor);

        return [new WorkflowBuilder(new Config())->build($root, $stack, $adapter->candidates, $adapter->templateDirectories, $extractor, $adapter->mechanisms)->result, $adapter];
    }

    /**
     * @return list<string>
     */
    private function ids(InspectionResult $result): array
    {
        return array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows);
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
