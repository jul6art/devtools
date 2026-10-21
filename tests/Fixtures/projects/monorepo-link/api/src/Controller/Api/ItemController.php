<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Item;
use App\Repository\ItemRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/items')]
final class ItemController
{
    public function __construct(private readonly ItemRepository $items)
    {
    }

    #[Route('', name: 'api_item_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse($this->items->findAll());
    }

    #[Route('/{id}', name: 'api_item_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse($this->items->find($id));
    }

    #[Route('/{id}', name: 'api_item_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->items->find($id) ?? new Item();
        $item->label = (string) $request->getPayload()->get('label');
        $this->items->save($item);

        return new JsonResponse($item);
    }

    #[Route('/{id}', name: 'api_item_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->items->remove($id);

        return new JsonResponse(null, 204);
    }
}
