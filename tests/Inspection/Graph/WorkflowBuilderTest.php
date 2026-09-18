<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Config\Config;
use Jul6Art\DevTools\Config\InvalidConfig;
use Jul6Art\DevTools\Inspection\Graph\BuildResult;
use Jul6Art\DevTools\Inspection\Graph\CoverageCalculator;
use Jul6Art\DevTools\Inspection\Graph\DecisionExtractor;
use Jul6Art\DevTools\Inspection\Graph\EntryPointCandidate;
use Jul6Art\DevTools\Inspection\Graph\TestLocator;
use Jul6Art\DevTools\Inspection\Graph\WorkflowBuilder;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowBuilder::class)]
#[CoversClass(EntryPointCandidate::class)]
#[CoversClass(TestLocator::class)]
#[CoversClass(CoverageCalculator::class)]
final class WorkflowBuilderTest extends TestCase
{
    public function testTwoRoutesToTheSameMethodFormOneWorkflow(): void
    {
        $health = $this->workflow($this->build(), 'route.health');

        self::assertSame('app_health', $health->main->name);
        self::assertSame(['app_status'], array_map(static fn (EntryPoint $satellite): string => $satellite->name, $health->satellites));
        self::assertSame(
            ['command.app.import-catalog', 'route.health', 'route.order.index', 'route.order.new', 'route.order.show'],
            array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $this->build()->result->workflows),
        );
    }

    /**
     * ADR-0043: a route of a real back-office reaches a dozen entities, each with its own conditional
     * setters. A page carrying a diagram for every one of them is the inventory this ADR removed, so only
     * the fields whose value depends on the most are kept — deterministically.
     */
    public function testAtMostEightDecidedFieldsAreKeptPerWorkflow(): void
    {
        $decisions = $this->workflow($this->build(), 'route.order.new')->decisions;
        $targets = array_values(array_unique(array_map(static fn (DecisionPoint $decision): string => $decision->target, $decisions)));

        self::assertLessThanOrEqual(DecisionExtractor::MAX_TARGETS, \count($targets));
        self::assertContains('App\\Entity\\Order::status', $targets, 'The field with the most branches is kept.');
        self::assertSame($targets, array_values(array_unique(array_map(static fn (DecisionPoint $decision): string => $decision->target, $this->workflow($this->build(), 'route.order.new')->decisions))), 'Two builds keep the same fields.');
    }

    public function testADeclaredGroupAttachesASatellite(): void
    {
        $index = $this->workflow($this->build(new Config(groups: ['app_order_index' => ['app_order_show']])), 'route.order.index');

        self::assertSame(['app_order_show'], array_map(static fn (EntryPoint $satellite): string => $satellite->name, $index->satellites));
        self::assertContains('templates/order/show.html.twig', array_map(static fn (FileRef $file): string => $file->path, $index->files), 'A satellite brings its own files.');
    }

    public function testAGroupReferencingAnUnknownEntryPointFailsWithItsName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('app_order_export');

        $this->build(new Config(groups: ['app_order_index' => ['app_order_export']]));
    }

    public function testTestsReferencingTheWorkflowNearItsEntryPointAreFound(): void
    {
        self::assertSame(['tests/Service/OrderPricingTest.php'], array_map(static fn (FileRef $file): string => $file->path, $this->workflow($this->build(), 'route.order.new')->tests), 'The helper next to it, tests/Support/OrderFactory.php, is not a test.');
        self::assertSame([], $this->workflow($this->build(), 'route.health')->tests);
    }

    public function testFilesNoWorkflowReferencesAreUncovered(): void
    {
        self::assertSame(
            ['migrations/Version20260901000000.php', 'src/EventListener/LocaleListener.php', 'src/Kernel.php', 'src/Message/OrderCreated.php', 'src/MessageHandler/NotifyOnOrderCreated.php', 'src/MessageHandler/OrderCreatedHandler.php', 'src/Twig/Components/CartSummary.php', 'src/Util/StringHelper.php', 'templates/components/CartSummary.html.twig'],
            array_map(static fn (FileRef $file): string => $file->path, $this->build()->result->uncovered),
        );
    }

    public function testAnEntryPointADependencyPointsToBecomesAnIdentifier(): void
    {
        $candidates = GraphFixture::candidates();
        $commands = array_values(array_filter($candidates, static fn (EntryPointCandidate $candidate): bool => 'command' === $candidate->entryPoint->kind));

        self::assertNotSame([], $commands, 'The fixture declares a command.');

        $candidates = [...\array_slice($candidates, 0, 1), $candidates[1]->withDependsOn([$commands[0]->entryPoint]), ...\array_slice($candidates, 2)];

        $result = new WorkflowBuilder(new Config())->build(GraphFixture::root(), GraphFixture::stack(), $candidates, ['templates'])->result;

        self::assertSame(['command.app.import-catalog'], array_map(strval(...), $this->workflow(new BuildResult($result, []), 'route.order.index')->dependsOn));
    }

    /**
     * ADR-0045: grouping by controller is a matter of presentation. The workflows are the ones
     * `entry-point` builds — one per route, same identifiers — and each carries the group its page is
     * written in.
     */
    public function testGroupedByControllerTheWorkflowsAreTheSameAsByEntryPoint(): void
    {
        $grouped = $this->build(new Config(routeGrouping: Config::ROUTES_BY_CONTROLLER));
        $flat = $this->build();

        $ids = static fn (BuildResult $build): array => array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $build->result->workflows);

        self::assertSame($ids($flat), $ids($grouped), 'The grouping never changes which workflows exist, nor their identifiers.');
        self::assertSame(['command.app.import-catalog', 'route.health', 'route.order.index', 'route.order.new', 'route.order.show'], $ids($grouped));
    }

    public function testGroupedByControllerEveryRouteOfAControllerSharesOneGroup(): void
    {
        $build = $this->build(new Config(routeGrouping: Config::ROUTES_BY_CONTROLLER));

        $index = $this->workflow($build, 'route.order.index');
        $new = $this->workflow($build, 'route.order.new');

        self::assertNotNull($index->group);
        self::assertNotNull($new->group);
        self::assertSame('order', $index->group->directory, 'The directory is what the route names have in common.');
        self::assertSame('/orders', $index->group->title, 'The title is the path the routes share.');
        self::assertSame('src/Controller/OrderController.php', $index->group->declaredIn->path);
        self::assertSame($index->group->directory, $new->group->directory);

        self::assertSame('index', $index->group->leafOf($index->id));
        self::assertSame('new', $new->group->leafOf($new->id));
    }

    public function testWithoutGroupingAWorkflowHasNoGroup(): void
    {
        self::assertNull($this->workflow($this->build(), 'route.order.index')->group);
    }

    public function testAnUnknownWayOfGroupingRoutesIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('is not a way of grouping routes');

        new Config(routeGrouping: 'par-écran');
    }

    private function build(Config $config = new Config()): BuildResult
    {
        return new WorkflowBuilder($config)->build(GraphFixture::root(), GraphFixture::stack(), GraphFixture::candidates(), ['templates']);
    }

    private function workflow(BuildResult $build, string $id): Workflow
    {
        foreach ($build->result->workflows as $workflow) {
            if ($id === (string) $workflow->id) {
                return $workflow;
            }
        }

        self::fail(\sprintf('No workflow "%s".', $id));
    }
}
