<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\Confidence;
use Jul6Art\DevTools\Inspection\Model\DecisionPoint;
use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Transition;
use Jul6Art\DevTools\Inspection\Model\Workflow;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;

/**
 * Realistic model objects for the tests, so each test states only what it is about.
 */
final class ModelFixtures
{
    public static function routeEntryPoint(string $name = 'app_order_new'): EntryPoint
    {
        return new EntryPoint(
            'route',
            $name,
            new FileRef('src/Controller/OrderController.php', FileRole::Controller),
            ['path' => '/orders/new', 'methods' => 'GET|POST', 'security' => 'ROLE_OPERATOR'],
        );
    }

    /**
     * A route workflow with every optional part filled, in an order that is deliberately not the
     * sorted one.
     *
     * @param list<FileRef>|null $files
     */
    public static function orderNewWorkflow(?array $files = null): Workflow
    {
        return new Workflow(
            id: new WorkflowId('route.order.new'),
            type: WorkflowType::routes(),
            title: 'Order creation',
            main: self::routeEntryPoint(),
            satellites: [self::routeEntryPoint('app_order_new_localized')],
            files: $files ?? [
                new FileRef('templates/order/new.html.twig', FileRole::Template),
                new FileRef('src/Controller/OrderController.php', FileRole::Controller),
                new FileRef('src/Form/OrderType.php', FileRole::Form),
            ],
            packages: [new PackageRef('symfony/form', '7.4.3'), new PackageRef('doctrine/orm', '3.5.2')],
            dependsOn: [new WorkflowId('route.order.index'), new WorkflowId('route.order.show')],
            tests: [new FileRef('tests/Controller/OrderControllerTest.php', FileRole::Test)],
            navigation: [new Edge('app_order_show', 'redirect'), new Edge('app_order_index', 'link')],
            decisions: [
                new DecisionPoint('App\\Entity\\Order::status', 'OrderStatus::DRAFT', 'null === $order->getReference()', new FileRef('src/Controller/OrderController.php', FileRole::Controller), 42),
                new DecisionPoint('App\\Entity\\Order::status', 'OrderStatus::PLACED', '!(null === $order->getReference())', new FileRef('src/Controller/OrderController.php', FileRole::Controller), 45),
            ],
            mechanisms: [
                new Mechanism('listener', 'App\\EventListener\\LocaleListener', 'kernel.request', new FileRef('src/EventListener/LocaleListener.php', FileRole::Listener), 16),
            ],
            states: new StateMachine(
                'order',
                ['draft', 'validated', 'shipped'],
                [new Transition('validate', ['draft'], ['validated']), new Transition('ship', ['validated'], ['shipped'])],
            ),
            confidence: Confidence::High,
            source: new WorkflowSource('native:symfony'),
        );
    }

    public static function commandWorkflow(): Workflow
    {
        return new Workflow(
            id: new WorkflowId('command.app.import-catalog'),
            type: WorkflowType::commands(),
            title: 'Catalog import',
            main: new EntryPoint('command', 'app:import-catalog', new FileRef('src/Command/ImportCatalogCommand.php', FileRole::Other)),
            confidence: Confidence::Medium,
            source: new WorkflowSource('claude'),
        );
    }
}
