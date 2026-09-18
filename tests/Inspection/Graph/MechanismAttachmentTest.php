<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Graph;

use Jul6Art\DevTools\Inspection\Graph\MechanismAttachment;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\FileRole;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\Mechanism;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Transition;
use Jul6Art\DevTools\Inspection\Model\WorkflowType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0043: which workflows a listener runs inside — one case per line of the table.
 */
#[CoversClass(MechanismAttachment::class)]
#[CoversClass(Mechanism::class)]
final class MechanismAttachmentTest extends TestCase
{
    private const string ENTITY = 'src/Entity/Order.php';

    public function testAKernelListenerRunsInsideEveryRouteAndNoCommand(): void
    {
        $attachment = new MechanismAttachment($this->mechanism('kernel.request'), WorkflowType::routes()->name);

        self::assertTrue($attachment->applies(WorkflowType::routes(), []));
        self::assertFalse($attachment->applies(WorkflowType::commands(), $this->files()));
        self::assertFalse($attachment->applies(WorkflowType::async(), $this->files()));
    }

    public function testAConsoleListenerRunsInsideEveryCommand(): void
    {
        $attachment = new MechanismAttachment($this->mechanism('console.command'), WorkflowType::commands()->name);

        self::assertTrue($attachment->applies(WorkflowType::commands(), []));
        self::assertFalse($attachment->applies(WorkflowType::routes(), []));
    }

    public function testAnUntargetedDoctrineListenerRunsInsideEveryWorkflowReachingAnEntity(): void
    {
        $attachment = new MechanismAttachment($this->mechanism('postLoad'), anyEntity: true);

        self::assertTrue($attachment->applies(WorkflowType::routes(), $this->files()));
        self::assertTrue($attachment->applies(WorkflowType::commands(), $this->files()));
        self::assertFalse($attachment->applies(WorkflowType::routes(), [new FileRef('src/Controller/HealthController.php', FileRole::Controller)]), 'A route that reaches no entity is not concerned.');
    }

    /**
     * The mutant this test kills: dropping the `files` condition would attach an entity listener to every
     * workflow of the project, which is exactly the noise ADR-0043 set out to remove.
     */
    public function testATargetedEntityListenerOnlyRunsInsideTheWorkflowsReachingItsEntity(): void
    {
        $attachment = new MechanismAttachment($this->mechanism('preUpdate'), files: [self::ENTITY]);

        self::assertTrue($attachment->applies(WorkflowType::routes(), $this->files()));
        self::assertFalse($attachment->applies(WorkflowType::routes(), [new FileRef('src/Entity/Product.php', FileRole::Entity)]));
        self::assertFalse($attachment->applies(WorkflowType::routes(), []));
    }

    public function testWithoutAnyConditionAMechanismRunsEverywhere(): void
    {
        self::assertTrue(new MechanismAttachment($this->mechanism('app.order.placed'))->applies(WorkflowType::data(), []));
    }

    /**
     * `workflow.work_order.entered.assigned` runs inside the workflows that drive that state machine, and
     * only those: attaching it to every route is the noise this ADR removes.
     */
    public function testAStateMachineListenerOnlyRunsInsideTheWorkflowsDrivingThatMachine(): void
    {
        $attachment = new MechanismAttachment($this->mechanism('workflow.work_order.entered.assigned'), stateMachine: 'work_order');

        self::assertTrue($attachment->applies(WorkflowType::routes(), [], new StateMachine('work_order', ['new', 'assigned'], [new Transition('assign', ['new'], ['assigned'])])));
        self::assertFalse($attachment->applies(WorkflowType::routes(), [], new StateMachine('quote', ['draft'], [])));
        self::assertFalse($attachment->applies(WorkflowType::routes(), $this->files()), 'A workflow without a state machine is not concerned.');
    }

    public function testAMechanismNeedsAKindTheModelKnows(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"cron" is not a kind of mechanism');

        new Mechanism('cron', 'App\Whatever', 'kernel.request', new FileRef('src/Whatever.php'));
    }

    private function mechanism(string $event): Mechanism
    {
        return new Mechanism('listener', 'App\EventListener\SomeListener', $event, new FileRef('src/EventListener/SomeListener.php', FileRole::Listener));
    }

    /**
     * @return list<FileRef>
     */
    private function files(): array
    {
        return [new FileRef('src/Controller/OrderController.php', FileRole::Controller), new FileRef(self::ENTITY, FileRole::Entity)];
    }
}
