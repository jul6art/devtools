<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\InspectionResult;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Workflow::class)]
#[CoversClass(WorkflowType::class)]
#[CoversClass(WorkflowTypeRegistry::class)]
#[CoversClass(InspectionResult::class)]
final class WorkflowTest extends TestCase
{
    public function testListsAreSortedAtConstruction(): void
    {
        $workflow = ModelFixtures::orderNewWorkflow();

        self::assertSame(
            ['src/Controller/OrderController.php', 'src/Form/OrderType.php', 'templates/order/new.html.twig'],
            array_map(static fn (FileRef $file): string => $file->path, $workflow->files),
        );
        self::assertSame(['doctrine/orm', 'symfony/form'], array_map(static fn (PackageRef $package): string => $package->name, $workflow->packages));
        self::assertSame(['route.order.index', 'route.order.show'], array_map(strval(...), $workflow->dependsOn));
        self::assertSame(['app_order_index', 'app_order_show'], array_map(static fn (Edge $edge): string => $edge->target, $workflow->navigation));
        self::assertSame([42, 45], array_map(static fn (DecisionPoint $decision): int => $decision->line, $workflow->decisions), 'Decisions are sorted by target, then file and line.');
        self::assertSame(['App\\EventListener\\LocaleListener'], array_map(static fn (Mechanism $mechanism): string => $mechanism->name, $workflow->mechanisms));
    }

    public function testItRejectsADuplicateFile(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('src/Form/OrderType.php');

        ModelFixtures::orderNewWorkflow([
            new FileRef('src/Form/OrderType.php', FileRole::Form),
            new FileRef('./src/Form/OrderType.php', FileRole::Other),
        ]);
    }

    public function testTheIdentifierPrefixMustMatchTheType(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"command"');

        new Workflow(
            id: new WorkflowId('route.app.import-catalog'),
            type: WorkflowType::commands(),
            title: 'Catalog import',
            main: new EntryPoint('command', 'app:import-catalog', new FileRef('src/Command/ImportCatalogCommand.php')),
            confidence: Confidence::High,
            source: new WorkflowSource('native:symfony'),
        );
    }

    public function testAWorkflowCannotDependOnItself(): void
    {
        $this->expectException(InvalidModel::class);

        new Workflow(
            id: new WorkflowId('command.app.import'),
            type: WorkflowType::commands(),
            title: 'Import',
            main: new EntryPoint('command', 'app:import', new FileRef('src/Command/ImportCommand.php')),
            dependsOn: [new WorkflowId('command.app.import')],
            confidence: Confidence::High,
            source: new WorkflowSource('native:symfony'),
        );
    }

    public function testATitleIsRequired(): void
    {
        $this->expectException(InvalidModel::class);

        new Workflow(
            id: new WorkflowId('command.app.import'),
            type: WorkflowType::commands(),
            title: ' ',
            main: new EntryPoint('command', 'app:import', new FileRef('src/Command/ImportCommand.php')),
            confidence: Confidence::High,
            source: new WorkflowSource('native:symfony'),
        );
    }

    /**
     * Six, not the seven of specs § 4.2: `events` is gone (ADR-0043), a listener being a mechanism of the
     * workflows it intercepts rather than a workflow of its own.
     */
    public function testTheRegistryKnowsTheSixNativeTypes(): void
    {
        $registry = new WorkflowTypeRegistry();

        self::assertSame(
            ['routes', 'commands', 'async', 'ui', 'integrations', 'data'],
            array_map(static fn (WorkflowType $type): string => $type->name, $registry->all()),
        );
        self::assertSame('async', $registry->get('async')->idPrefix);
    }

    public function testEventsIsNoLongerANativeTypeAndCanBeDeclaredAsACustomOne(): void
    {
        self::assertSame('event', WorkflowType::custom('events', 'event')->idPrefix);

        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"events"');

        new WorkflowTypeRegistry()->get('events');
    }

    public function testTheRegistryRejectsAnUnknownType(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"webhooks"');

        new WorkflowTypeRegistry()->get('webhooks');
    }

    public function testACustomTypeIsDeclaredOnceAndCannotShadowANativeOne(): void
    {
        $registry = new WorkflowTypeRegistry([WorkflowType::custom('webhooks', 'webhook')]);

        self::assertSame('webhook', $registry->get('webhooks')->idPrefix);
        self::assertFalse($registry->get('webhooks')->isNative());

        $this->expectException(InvalidModel::class);

        new WorkflowTypeRegistry([WorkflowType::custom('jobs', 'async')]);
    }

    public function testTheResultSortsWorkflowsByIdentifierAndRejectsDuplicates(): void
    {
        $result = new InspectionResult('symfony-7', [ModelFixtures::orderNewWorkflow(), ModelFixtures::commandWorkflow()], [new FileRef('src/Util/StringHelper.php'), new FileRef('src/Util/Clock.php')]);

        self::assertSame(['command.app.import-catalog', 'route.order.new'], array_map(static fn (Workflow $workflow): string => (string) $workflow->id, $result->workflows));
        self::assertSame(['src/Util/Clock.php', 'src/Util/StringHelper.php'], array_map(static fn (FileRef $file): string => $file->path, $result->uncovered));

        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('route.order.new');

        new InspectionResult('symfony-7', [ModelFixtures::orderNewWorkflow(), ModelFixtures::orderNewWorkflow()]);
    }
}
