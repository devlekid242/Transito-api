<?php

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceToken>
 */
class DeviceTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceToken::class);
    }

    /** @return DeviceToken[] */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function findByToken(string $token): ?DeviceToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Supprime les tokens devenus invalides (désinstallation, token expiré...)
     * signalés par Firebase lors de l'envoi. Évite de continuer à essayer de
     * pousser vers des appareils qui ne recevront jamais rien.
     */
    public function deleteByTokens(array $tokens): void
    {
        if (empty($tokens)) {
            return;
        }

        $this->createQueryBuilder('d')
            ->delete()
            ->where('d.token IN (:tokens)')
            ->setParameter('tokens', $tokens)
            ->getQuery()
            ->execute();
    }
}