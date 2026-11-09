<?php

declare(strict_types=1);

namespace App\Manager;

use App\Entity\Loan;

final class LoanManager
{
    public function giveBack(Loan $loan): Loan
    {
        $loan->returnedOn = new \DateTimeImmutable();

        return $loan;
    }
}
