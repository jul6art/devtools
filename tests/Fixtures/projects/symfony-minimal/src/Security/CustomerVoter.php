<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Customer;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who may edit a customer (ADR-0044): a mechanism of the routes that ask for CUSTOMER_EDIT, and of no
 * other.
 *
 * @extends Voter<string, Customer>
 */
final class CustomerVoter extends Voter
{
    public const string EDIT = 'CUSTOMER_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Customer;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        if (\in_array('ROLE_MANAGER', $user->getRoles(), true)) {
            return true;
        }

        return $this->manages($user, $subject);
    }

    private function manages(UserInterface $user, Customer $customer): bool
    {
        return $customer->accountManager === $user->getUserIdentifier();
    }
}
