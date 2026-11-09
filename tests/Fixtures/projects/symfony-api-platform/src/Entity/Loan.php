<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Security\LoanVoter;
use App\State\GiveBackProcessor;
use App\State\LendProcessor;

#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('".LoanVoter::LIST."')"),
        new Get(security: "is_granted('".LoanVoter::VIEW."', object)"),
        new Post(security: "is_granted('".LoanVoter::CREATE."')", processor: LendProcessor::class),
        new Post(uriTemplate: '/loans/{id}/return', security: "is_granted('".LoanVoter::GIVE_BACK."', object)", input: false, processor: GiveBackProcessor::class, name: 'loan_return'),
    ],
)]
class Loan
{
    public ?int $id = null;

    public ?\DateTimeImmutable $returnedOn = null;
}
