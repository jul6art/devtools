<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Form\OrderType;
use App\Repository\OrderRepository;
use App\Service\OrderPricing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderController extends AbstractController
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function index(): Response
    {
        return $this->render('order/index.html.twig', ['orders' => $this->orders->all()]);
    }

    #[IsGranted('ROLE_OPERATOR')]
    public function new(Request $request, OrderPricing $pricing): Response
    {
        $order = new Order();
        $form = $this->createForm(OrderType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $pricing->price($order);
            $this->orders->save($order);

            return $this->redirectToRoute('app_order_show', ['id' => $order->id]);
        }

        return $this->render('order/new.html.twig', ['form' => $form]);
    }

    #[IsGranted('ROLE_MANAGER')]
    public function validate(int $id, WorkflowInterface $orderStateMachine): Response
    {
        $order = $this->orders->get($id);
        $orderStateMachine->apply($order, 'validate');

        return $this->redirectToRoute('app_order_show', ['id' => $id]);
    }

    public function show(int $id): Response
    {
        return $this->render('order/show.html.twig', ['order' => $this->orders->get($id)]);
    }
}
