<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OtpChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OtpChallenge> */
class OtpChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    { parent::__construct($registry, OtpChallenge::class); }

    public function findLatestForPhone(string $phone): ?OtpChallenge
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.phoneNumber = :phone')
            ->setParameter('phone', $phone)
            ->orderBy('o.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
