<?php

declare(strict_types=1);

namespace Acme;

final readonly class OrderRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array{customer: string}>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT customer FROM orders')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function add(string $customer): void
    {
        $this->pdo->prepare('INSERT INTO orders (customer) VALUES (?)')->execute([$customer]);
    }
}
