<?php

declare(strict_types=1);

namespace Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ItemController
{
    #[Route('/api/items', name: 'api_item_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
