<?php

namespace App\Security;

use App\Entity\Admin;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Fine-grained authorization for the administrative back-office.
 * ROLE_ADMIN only proves that the account is staff; this voter enforces
 * the business role stored on Admin (SUPER/FINANCE/MODERATION/SUPPORT).
 */
final class AdminRoleVoter extends Voter
{
    public const SUPER = 'ADMIN_SUPER';
    public const FINANCE = 'ADMIN_FINANCE';
    public const MODERATION = 'ADMIN_MODERATION';
    public const SUPPORT = 'ADMIN_SUPPORT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SUPER, self::FINANCE, self::MODERATION, self::SUPPORT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof \App\Entity\User) {
            return false;
        }

        $admin = $user->getAdmin();
        if (!$admin instanceof Admin || $admin->getStatus() !== 'active') {
            return false;
        }

        if ($admin->getAdminRole() === 'SUPER_ADMIN') {
            return true;
        }

        return match ($attribute) {
            self::FINANCE => $admin->getAdminRole() === 'FINANCE_ADMIN',
            self::MODERATION => $admin->getAdminRole() === 'MODERATION_ADMIN',
            self::SUPPORT => $admin->getAdminRole() === 'SUPPORT_ADMIN',
            self::SUPER => false,
            default => false,
        };
    }
}
