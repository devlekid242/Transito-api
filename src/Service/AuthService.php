<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Agency;
use App\Repository\AgentRepository;

class AuthService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getCurrentUser(): ?User
    {
        // Assuming you have a way to get the currently authenticated user
        // This is just a placeholder implementation
        return $this->entityManager->getRepository(User::class)->find(1); // Replace with actual logic
    }

    private function getAuthenticatedAgency(User $user, AgentRepository $agentRepository): ?Agency
    {
        if (!$user instanceof User) {
            return null;
        }
        $agent = $agentRepository->findOneBy(['user' => $user]);
        return $agent?->getAgency();
    }
}