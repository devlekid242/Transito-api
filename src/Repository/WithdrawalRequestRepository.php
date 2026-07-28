<?php

namespace App\Repository;

use App\Entity\WithdrawalRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WithdrawalRequest>
 */
class WithdrawalRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WithdrawalRequest::class);
    }

    public function findByUser($user)
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total amount of pending withdrawal requests.
     */
    public function getPendingWithdrawalsAmount(): string
    {
        $result = $this->createQueryBuilder('wr')
            ->select('SUM(wr.amount) as total')
            ->where('wr.status = :status')
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();
        
        return $result ?? '0.00';
    }

    /**
     * Find pending withdrawals for admin dashboard.
     */
    public function findPendingWithdrawals(): array
    {
        return $this->createQueryBuilder('wr')
            ->select('wr', 'a', 'u')
            ->join('wr.agency', 'a')
            ->leftJoin('wr.requestedBy', 'u')
            ->where('wr.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('wr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total results for a filtered query.
     */
    public function countForList($queryBuilder): int
    {
        $clone = clone $queryBuilder;
        $clone->select('COUNT(wr.id)');
        $clone->resetDQLPart('orderBy');
        
        try {
            return (int) $clone->getQuery()->getSingleScalarResult();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get withdrawal requests that are pending and would be affected by refund safeguard
     */
    public function findPendingWithdrawalsWithRefundRisk(): array
    {
        return $this->createQueryBuilder('wr')
            ->select('wr', 'a', 'u')
            ->join('wr.agency', 'a')
            ->leftJoin('wr.requestedBy', 'u')
            ->where('wr.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('wr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the total amount of pending withdrawals for a specific agency
     */
    public function getPendingWithdrawalsAmountForAgency($agency): string
    {
        $result = $this->createQueryBuilder('wr')
            ->select('SUM(wr.amount) as total')
            ->where('wr.agency = :agency')
            ->andWhere('wr.status = :status')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0.00';
    }
}
