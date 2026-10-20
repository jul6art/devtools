<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\CustomerType;
use App\Repository\CustomerRepository;
use App\Security\CustomerVoter;
use App\Service\CustomerTiering;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CustomerController extends AbstractController
{
    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    #[IsGranted('ROLE_USER')]
    public function edit(int $id, Request $request, CustomerTiering $tiering): Response
    {
        $customer = $this->customers->get($id);
        $this->denyAccessUnlessGranted(CustomerVoter::EDIT, $customer);

        $form = $this->createForm(CustomerType::class, $customer, [
            'user' => $this->getUser()?->getUserIdentifier(),
            'can_change_site' => $this->isGranted('ROLE_MANAGER'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tiering->apply($customer);
            $this->customers->save($customer);

            return $this->redirectToRoute('app_customer_edit', ['id' => $id]);
        }

        return $this->render('customer/edit.html.twig', ['form' => $form]);
    }
}
