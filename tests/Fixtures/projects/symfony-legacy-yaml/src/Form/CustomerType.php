<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Customer;
use App\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The sites a customer may be attached to depend on who edits it (ADR-0044): a decision on
 * `Customer::site`, written in the form rather than in the entity.
 */
final class CustomerType extends AbstractType
{
    public function __construct(private readonly SiteRepository $sites)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class);
        $builder->add('site', EntityType::class, [
            'class' => Site::class,
            'choices' => $this->sites->visibleTo($options['user']),
            'disabled' => !$options['can_change_site'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Customer::class, 'user' => null, 'can_change_site' => false]);
    }
}
