<?php

namespace App\Repository;

use App\Entity\Agency;
use App\Entity\RefundRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefundRequest>
 */
class RefundRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefundRequest::class);
    }

    /**
     * Get the total amount of pending refund requests for a specific agency
     */
    public function getPendingRefundsAmountForAgency(Agency $agency): float
    {
        $result = $this->createQueryBuilder('rr')
            ->select('SUM(rr.requestedAmount) as total')
            ->where('rr.agency = :agency')
            ->andWhere('rr.status = :status')
            ->setParameter('agency', $agency)
            ->setParameter('status', RefundRequest::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? '0.00');
    }

    /**
     * Get all pending refund requests for a specific agency
     */
    public function findPendingRefundsForAgency(Agency $agency): array
    {
        return $this->createQueryBuilder('rr')
            ->select('rr', 'r', 'u')
            ->join('rr.reservation', 'r')
            ->join('rr.requestedBy', 'u')
            ->where('rr.agency = :agency')
            ->andWhere('rr.status = :status')
            ->setParameter('agency', $agency)
            ->setParameter('status', RefundRequest::STATUS_PENDING)
            ->orderBy('rr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all pending refund requests across all agencies
     */
    public function findAllPendingRefunds(): array
    {
        return $this->createQueryBuilder('rr')
            ->select('rr', 'a', 'r', 'u')
            ->join('rr.agency', 'a')
            ->join('rr.reservation', 'r')
            ->join('rr.requestedBy', 'u')
            ->where('rr.status = :status')
            ->setParameter('status', RefundRequest::STATUS_PENDING)
            ->orderBy('rr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the total amount of all pending refund requests
     */
    public function getTotalPendingRefundsAmount(): float
    {
        $result = $this->createQueryBuilder('rr')
            ->select('SUM(rr.requestedAmount) as total')
            ->where('rr.status = :status')
            ->setParameter('status', RefundRequest::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? '0.00');
    }

    /**
     * Get the total amount of pending refund requests for multiple agencies
     */
    public function getPendingRefundsAmountForAgencies(array $agencies): float
    {
        $result = $this->createQueryBuilder('rr')
            ->select('SUM(rr.requestedAmount) as total')
            ->where('rr.agency IN (:agencies)')
            ->andWhere('rr.status = :status')
            ->setParameter('agencies', $agencies)
            ->setParameter('status', RefundRequest::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? '0.00');
    }

    //    /**
//     * @return RefundRequest[] Returns an array of RefundRequest objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('r')
//            ->andWhere('r.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('r.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

    //    public function findOneBySomeField($value): ?RefundRequest
//    {
//        return $this->createQueryBuilder('r')
//            ->andWhere('r.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}