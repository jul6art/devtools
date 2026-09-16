<?php

declare(strict_types=1);

namespace Acme\Tests;

use Acme\OrderRepository;
use PHPUnit\Framework\TestCase;

final class OrderRepositoryTest extends TestCase
{
    public function testAnAddedOrderIsListed(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec((string) file_get_contents(__DIR__.'/../migrations/001_create_orders.sql'));
        $repository = new OrderRepository($pdo);

        $repository->add('Ada');

        self::assertSame([['customer' => 'Ada']], $repository->all());
    }
}
