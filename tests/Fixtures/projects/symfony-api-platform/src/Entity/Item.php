<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\State\ItemProcessor;
use App\State\ItemProvider;

#[ApiResource(security: "is_granted('ROLE_USER')", processor: ItemProcessor::class)]
#[GetCollection(provider: ItemProvider::class)]
#[Delete(security: "is_granted('ITEM_DELETE', object)")]
class Item
{
    public ?int $id = null;

    public string $name = '';
}
