<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Loan;
use App\Manager\LoanManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The screen the API replaces: its route is named like the API's operation, but for its prefix.
 */
final class LoanController extends AbstractController
{
    #[Route('/loans/{id}/give-back', name: 'app_loan_return', methods: ['POST'])]
    public function giveBack(Loan $loan, LoanManager $loans): Response
    {
        $loans->giveBack($loan);

        return $this->redirect('/loans');
    }
}
