<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdAssigner;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdCollision;
use Jul6Art\DevTools\Inspection\Model\WorkflowIdDeriver;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A collision is an error, never a numeric suffix: a suffix would depend on the discovery order and
 * change from one scan to the next, orphaning pages at random.
 */
#[CoversClass(WorkflowIdAssigner::class)]
#[CoversClass(WorkflowIdCollision::class)]
final class WorkflowIdAssignerTest extends TestCase
{
    public function testItAssignsTheDerivedIdentifier(): void
    {
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver());

        self::assertSame('route.order.new', (string) $assigner->assign(WorkflowType::routes(), $this->route('app_order_new', 'src/Controller/OrderController.php')));
    }

    public function testTheSameEntryPointCanBeAssignedTwice(): void
    {
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver());
        $entryPoint = $this->route('app_order_new', 'src/Controller/OrderController.php');

        $assigner->assign(WorkflowType::routes(), $entryPoint);

        self::assertSame('route.order.new', (string) $assigner->assign(WorkflowType::routes(), $entryPoint));
    }

    public function testACollisionNamesBothSources(): void
    {
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver());
        $assigner->assign(WorkflowType::routes(), $this->route('app_order_new', 'src/Controller/OrderController.php'));

        try {
            // "order_new" loses no prefix and derives to the same identifier.
            $assigner->assign(WorkflowType::routes(), $this->route('order_new', 'src/Controller/LegacyOrderController.php'));
            self::fail('A collision must be reported.');
        } catch (WorkflowIdCollision $collision) {
            self::assertStringContainsString('route.order.new', $collision->getMessage());
            self::assertStringContainsString('"app_order_new" (src/Controller/OrderController.php)', $collision->getMessage());
            self::assertStringContainsString('"order_new" (src/Controller/LegacyOrderController.php)', $collision->getMessage());
            self::assertStringContainsString('<alias', $collision->getMessage());
        }
    }

    public function testAnAliasResolvesACollision(): void
    {
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver(), ['order_new' => 'route.legacy.order.new']);
        $assigner->assign(WorkflowType::routes(), $this->route('app_order_new', 'src/Controller/OrderController.php'));

        self::assertSame('route.legacy.order.new', (string) $assigner->assign(WorkflowType::routes(), $this->route('order_new', 'src/Controller/LegacyOrderController.php')));
    }

    public function testAnAliasMustKeepThePrefixOfItsType(): void
    {
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver(), ['app_order_new' => 'command.order.new']);

        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"route"');

        $assigner->assign(WorkflowType::routes(), $this->route('app_order_new', 'src/Controller/OrderController.php'));
    }

    public function testAnAliasCannotStealAnIdentifierAlreadyAssigned(): void
    {
        $assigner = new WorkflowIdAssigner(new WorkflowIdDeriver(), ['app_order_create' => 'route.order.new']);
        $assigner->assign(WorkflowType::routes(), $this->route('app_order_new', 'src/Controller/OrderController.php'));

        $this->expectException(WorkflowIdCollision::class);

        $assigner->assign(WorkflowType::routes(), $this->route('app_order_create', 'src/Controller/OrderController.php'));
    }

    private function route(string $name, string $file): EntryPoint
    {
        return new EntryPoint('route', $name, new FileRef($file));
    }
}
