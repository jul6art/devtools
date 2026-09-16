<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\Edge;
use Jul6Art\DevTools\Inspection\Model\EntryPoint;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\PackageRef;
use Jul6Art\DevTools\Inspection\Model\StateMachine;
use Jul6Art\DevTools\Inspection\Model\Transition;
use Jul6Art\DevTools\Inspection\Model\WorkflowSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntryPoint::class)]
#[CoversClass(PackageRef::class)]
#[CoversClass(Edge::class)]
#[CoversClass(StateMachine::class)]
#[CoversClass(Transition::class)]
#[CoversClass(WorkflowSource::class)]
final class EntryPointAndValueObjectsTest extends TestCase
{
    public function testEntryPointAttributesAreSortedByName(): void
    {
        $entryPoint = new EntryPoint('route', 'app_x', new FileRef('src/X.php'), ['path' => '/x', 'methods' => 'GET']);

        self::assertSame(['methods' => 'GET', 'path' => '/x'], $entryPoint->attributes);
    }

    public function testEntryPointsAreEqualOnKindNameAndDeclaringFile(): void
    {
        $a = new EntryPoint('route', 'app_x', new FileRef('src/X.php'), ['path' => '/x']);

        self::assertTrue($a->sameAs(new EntryPoint('route', 'app_x', new FileRef('./src/X.php'), ['path' => '/y'])));
        self::assertFalse($a->sameAs(new EntryPoint('command', 'app_x', new FileRef('src/X.php'))));
        self::assertFalse($a->sameAs(new EntryPoint('route', 'app_x', new FileRef('src/Y.php'))));
    }

    public function testEntryPointRejectsAMalformedKind(): void
    {
        $this->expectException(InvalidModel::class);

        new EntryPoint('Route', 'app_x', new FileRef('src/X.php'));
    }

    public function testEntryPointRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidModel::class);

        new EntryPoint('route', '  ', new FileRef('src/X.php'));
    }

    public function testEntryPointRejectsAMalformedAttributeName(): void
    {
        $this->expectException(InvalidModel::class);

        new EntryPoint('route', 'app_x', new FileRef('src/X.php'), ['Path' => '/x']);
    }

    public function testPackageRefRequiresANameAndAVersion(): void
    {
        self::assertSame('symfony/form', new PackageRef(' symfony/form ', '7.4.3')->name);

        $this->expectException(InvalidModel::class);

        new PackageRef('symfony/form', '');
    }

    public function testEdgeRequiresATarget(): void
    {
        self::assertNull(new Edge('app_order_show')->label);

        $this->expectException(InvalidModel::class);

        new Edge('');
    }

    public function testATransitionMustOnlyReferencePlacesOfItsMachine(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('"cancelled"');

        new StateMachine('order', ['draft', 'validated'], [new Transition('cancel', ['draft'], ['cancelled'])]);
    }

    public function testAStateMachineRejectsDuplicatePlaces(): void
    {
        $this->expectException(InvalidModel::class);

        new StateMachine('order', ['draft', 'draft'], []);
    }

    public function testATransitionNeedsBothEnds(): void
    {
        $this->expectException(InvalidModel::class);

        new Transition('validate', [], ['validated']);
    }

    public function testASourceIsNativeWithAnAdapterNameOrClaude(): void
    {
        self::assertTrue(new WorkflowSource('native:symfony')->isNative());
        self::assertFalse(new WorkflowSource('claude')->isNative());

        $this->expectException(InvalidModel::class);

        new WorkflowSource('native:');
    }
}
