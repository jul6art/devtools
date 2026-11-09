<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Loan;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Loan|null>
 */
final class LoanVoter extends Voter
{
    public const string LIST = 'LOAN_LIST';

    public const string VIEW = 'LOAN_VIEW';

    public const string CREATE = 'LOAN_CREATE';

    public const string GIVE_BACK = 'LOAN_GIVE_BACK';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::LIST, self::VIEW, self::CREATE, self::GIVE_BACK], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return null !== $token->getUser();
    }
}
