<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Tests\Inspection\Model;

use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowId::class)]
final class WorkflowIdTest extends TestCase
{
    public function testItExposesItsPrefixAndThePageName(): void
    {
        $id = new WorkflowId('route.order.create');

        self::assertSame('route', $id->prefix());
        self::assertSame('order.create', $id->pageName());
        self::assertSame('route.order.create', (string) $id);
    }

    public function testEqualityIsByValue(): void
    {
        self::assertTrue(new WorkflowId('command.app.import')->equals(new WorkflowId('command.app.import')));
        self::assertFalse(new WorkflowId('command.app.import')->equals(new WorkflowId('command.app.export')));
    }

    #[DataProvider('malformedIdentifiers')]
    public function testItRejectsAMalformedIdentifier(string $value): void
    {
        $this->expectException(InvalidModel::class);

        new WorkflowId($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'prefix only' => ['route'];
        yield 'uppercase' => ['route.Order.new'];
        yield 'empty segment' => ['route..new'];
        yield 'trailing dot' => ['route.order.'];
        yield 'underscore' => ['route.order_new'];
        yield 'accent' => ['route.commande.créer'];
        yield 'leading hyphen in segment' => ['route.-order'];
        yield 'space' => ['route.order new'];
        yield 'path traversal attempt' => ['route.../etc'];
    }
}
