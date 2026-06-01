<?php

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class RefreshTokenService
{
    private const REFRESH_TOKEN_TTL_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefreshTokenRepository $refreshTokenRepository
    ) {
    }

    public function issueForUser(User $user): array
    {
        $plainToken = bin2hex(random_bytes(64));
        $tokenHash = hash('sha256', $plainToken);

        $refreshToken = new RefreshToken();
        $refreshToken
            ->setUser($user)
            ->setTokenHash($tokenHash)
            ->setExpiresAt((new \DateTime())->modify(sprintf('+%d days', self::REFRESH_TOKEN_TTL_DAYS)));

        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();

        return [
            'plain' => $plainToken,
            'entity' => $refreshToken,
        ];
    }

    public function rotate(string $plainToken): ?array
    {
        $tokenHash = hash('sha256', $plainToken);
        $refreshToken = $this->refreshTokenRepository->findOneBy(['tokenHash' => $tokenHash]);

        if (!$refreshToken || !$refreshToken->isActive()) {
            return null;
        }

        $refreshToken->setRevokedAt(new \DateTime());
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();

        return $this->issueForUser($refreshToken->getUser());
    }
}
