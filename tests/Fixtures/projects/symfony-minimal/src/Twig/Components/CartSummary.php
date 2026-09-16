<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Repository\OrderRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class CartSummary
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public int $count = 0;

    public function __construct(private readonly OrderRepository $orders)
    {
    }

    #[LiveAction]
    public function refresh(): void
    {
        $this->count = \count($this->orders->all());
    }
}
