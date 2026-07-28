<?php

namespace App\Repository;

use App\Entity\PaymentLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentLog>
 */
class PaymentLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentLog::class);
    }

    /**
     * Get total amount of pending refunds.
     */
    public function getPendingRefundsAmount(): string
    {
        $result = $this->createQueryBuilder('pl')
            ->select('SUM(pl.amount) as total')
            ->where('pl.status = :status')
            ->setParameter('status', 'REFUND_PENDING')
            ->getQuery()
            ->getSingleScalarResult();
        
        return $result ?? '0.00';
    }

    /**
     * Find payment logs with REFUND_PENDING status.
     */
    public function findRefundPending(): array
    {
        return $this->createQueryBuilder('pl')
            ->where('pl.status = :status')
            ->setParameter('status', 'REFUND_PENDING')
            ->orderBy('pl.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
