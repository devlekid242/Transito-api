<?php

namespace App\DataFixtures;

use App\Entity\Admin;
use App\Entity\User;
use App\Repository\AdminRepository;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Compte de recette donnant un accès SUPER_ADMIN complet au back-office.
 *
 * Variables optionnelles :
 * TRANSITO_SUPERADMIN_EMAIL
 * TRANSITO_SUPERADMIN_PHONE
 * TRANSITO_SUPERADMIN_PASSWORD
 * TRANSITO_SUPERADMIN_NAME
 *
 * Groupe Doctrine : super_admin
 */
final class SuperAdminFixture extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly AdminRepository $adminRepository,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $email = getenv('TRANSITO_SUPERADMIN_EMAIL') ?: 'superadmin@transito.test';
        $phone = getenv('TRANSITO_SUPERADMIN_PHONE') ?: '+242060000001';
        $password = getenv('TRANSITO_SUPERADMIN_PASSWORD') ?: 'Admin123!';
        $name = getenv('TRANSITO_SUPERADMIN_NAME') ?: 'Super Administrateur Transito';

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $user->setEmail($email);
        }

        $user->setFullName($name);
        $user->setPhoneNumber($phone);
        $user->setVilleResidence('Pointe-Noire');
        $user->setQuartier('Centre-ville');
        $user->setEmergencyContactName('Transito Support');
        $user->setEmergencyContactPhone('+242060000000');
        $user->setStatus('active');
        $user->setPhoneVerified(true);
        $user->setEmailVerified(true);
        // Le rôle métier SUPER_ADMIN est porté par Admin::adminRole.
        // User::getRoles() dérive alors ROLE_ADMIN et ROLE_SUPER_ADMIN.
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $manager->persist($user);

        $admin = $this->adminRepository->findOneBy(['user' => $user]);
        if (!$admin) {
            $admin = new Admin();
            $admin->setUser($user);
        }

        $admin->setAdminRole('SUPER_ADMIN');
        $admin->setStatus('active');
        $admin->setDepartment('Direction générale');
        $admin->setPermissions([
            'view_users',
            'edit_users',
            'delete_users',
            'suspend_users',
            'view_finance',
            'approve_withdrawals',
            'approve_refunds',
            'view_agencies',
            'edit_agencies',
            'suspend_agencies',
            'verify_kyc',
            'view_support',
            'respond_support',
            'view_reports',
            'export_data',
            'manage_admins',
            'view_settings',
            'edit_settings',
        ]);
        $admin->setNotes('Compte de recette généré par fixture. NE PAS UTILISER EN PRODUCTION.');

        $manager->persist($admin);
        $manager->flush();
    }

    public static function getGroups(): array
    {
        return ['super_admin'];
    }
}
