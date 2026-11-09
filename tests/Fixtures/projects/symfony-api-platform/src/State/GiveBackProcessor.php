<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Loan;
use App\Manager\LoanManager;

/**
 * @implements ProcessorInterface<Loan, Loan>
 */
final readonly class GiveBackProcessor implements ProcessorInterface
{
    public function __construct(private LoanManager $loans)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Loan
    {
        return $this->loans->giveBack($data);
    }
}
