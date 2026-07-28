<?php

namespace App\DataFixtures;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // --- Compte SUPER_ADMIN ---
        $superAdminUser = new User();
        $superAdminUser->setFullName('Super Admin Test');
        $superAdminUser->setEmail('superadmin@transito.test');
        $superAdminUser->setPhoneNumber('+242060000001');
        $superAdminUser->setVilleResidence('Pointe-Noire');
        $superAdminUser->setQuartier('Centre-ville');
        $superAdminUser->setEmergencyContactName('N/A');
        $superAdminUser->setEmergencyContactPhone('+242060000000');
        $superAdminUser->setRoles(['ROLE_USER']);

        $hashedPassword = $this->passwordHasher->hashPassword($superAdminUser, 'Admin123!');
        $superAdminUser->setPassword($hashedPassword);

        $manager->persist($superAdminUser);

        $superAdmin = new Admin();
        $superAdmin->setUser($superAdminUser);
        $superAdmin->setAdminRole('SUPER_ADMIN');
        $superAdmin->setStatus('active');
        $superAdmin->setDepartment('Direction');
        $superAdmin->setPermissions([
            'view_users',
            'edit_users',
            'view_finance',
            'approve_withdrawals',
            'view_agencies',
            'edit_agencies',
            'view_support',
            'respond_support',
        ]);
        $superAdmin->setNotes('Compte de test généré par fixture — à supprimer en production.');

        $manager->persist($superAdmin);

        // --- Compte SUPPORT_ADMIN (pour tester les permissions restreintes) ---
        $supportAdminUser = new User();
        $supportAdminUser->setFullName('Support Admin Test');
        $supportAdminUser->setEmail('support@transito.test');
        $supportAdminUser->setPhoneNumber('+242060000002');
        $supportAdminUser->setVilleResidence('Pointe-Noire');
        $supportAdminUser->setQuartier('Centre-ville');
        $supportAdminUser->setEmergencyContactName('N/A');
        $supportAdminUser->setEmergencyContactPhone('+242060000000');
        $supportAdminUser->setRoles(['ROLE_USER']);

        $hashedSupportPassword = $this->passwordHasher->hashPassword($supportAdminUser, 'Support123!');
        $supportAdminUser->setPassword($hashedSupportPassword);

        $manager->persist($supportAdminUser);

        $supportAdmin = new Admin();
        $supportAdmin->setUser($supportAdminUser);
        $supportAdmin->setAdminRole('SUPPORT_ADMIN');
        $supportAdmin->setStatus('active');
        $supportAdmin->setDepartment('Support client');
        $supportAdmin->setPermissions(['view_support', 'respond_support']);
        $supportAdmin->setNotes('Compte de test généré par fixture — à supprimer en production.');

        $manager->persist($supportAdmin);

        // --- Compte admin SUSPENDU (pour tester le rejet au login) ---
        $suspendedUser = new User();
        $suspendedUser->setFullName('Admin Suspendu Test');
        $suspendedUser->setEmail('suspended@transito.test');
        $suspendedUser->setPhoneNumber('+242060000003');
        $suspendedUser->setVilleResidence('Pointe-Noire');
        $suspendedUser->setQuartier('Centre-ville');
        $suspendedUser->setEmergencyContactName('N/A');
        $suspendedUser->setEmergencyContactPhone('+242060000000');
        $suspendedUser->setRoles(['ROLE_USER']);

        $hashedSuspendedPassword = $this->passwordHasher->hashPassword($suspendedUser, 'Suspended123!');
        $suspendedUser->setPassword($hashedSuspendedPassword);

        $manager->persist($suspendedUser);

        $suspendedAdmin = new Admin();
        $suspendedAdmin->setUser($suspendedUser);
        $suspendedAdmin->setAdminRole('MODERATION_ADMIN');
        $suspendedAdmin->setStatus('suspended');
        $suspendedAdmin->setDepartment('Modération');
        $suspendedAdmin->setPermissions(['view_users']);
        $suspendedAdmin->setNotes('Compte suspendu de test — doit être rejeté au login (403).');

        $manager->persist($suspendedAdmin);



        $manager->flush();
    }
}