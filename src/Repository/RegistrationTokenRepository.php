<?php

namespace App\Repository;

use App\Entity\RegistrationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistrationToken>
 */
class RegistrationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistrationToken::class);
    }

    public function findByTokenHash(string $tokenHash): ?RegistrationToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findLatestForPhone(string $phoneNumber): ?RegistrationToken
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.phoneNumber = :phone')
            ->setParameter('phone', $phoneNumber)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}