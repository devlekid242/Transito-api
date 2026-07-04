<?php

namespace App\DataFixtures;

use App\Entity\Agency;
use App\Entity\Agent;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PartnerDashboardFixture extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        // Create Agency
        $agency = new Agency();
        $agency->setName('Ocean du nord congo');
        $agency->setEmail('ocean@demo.test');
        $agency->setPhone('+242066000000');
        $agency->setDescription('Agence partenaire de test pour valider le dashboard et les flux de gestion.');
        $agency->setPasswordHash($this->passwordHasher->hashPassword(new User(), 'Ocean2026!'));
        $agency->setStatus('active');

        $manager->persist($agency);

        // Create User first (source of truth for authentication & identity)
        $dashboardUser = new User();
        $dashboardUser->setFullName('Admin Ocean');
        $dashboardUser->setEmail('admin.ocean@demo.test');
        $dashboardUser->setPhoneNumber('+242066111111');
        $dashboardUser->setRoles(['ROLE_PARTNER', 'ROLE_USER']);
        $dashboardUser->setPassword($this->passwordHasher->hashPassword($dashboardUser, 'Ocean2026!'));
        $dashboardUser->setStatus('active');

        $manager->persist($dashboardUser);
        $manager->flush(); // Flush to ensure User gets an ID before Agent is created

        // Create Agent (now linked to User via FK)
        $agent = new Agent();
        $agent->setUser($dashboardUser);      // Link to User instead of duplicating fields
        $agent->setAgency($agency);
        $agent->setAgentRole('admin_agence');  // Use agentRole instead of role
        $agent->setStatus('active');

        $manager->persist($agent);
        $manager->flush();
    }
}
