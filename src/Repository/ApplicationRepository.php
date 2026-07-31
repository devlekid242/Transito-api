<?php

namespace App\Repository;

use App\Entity\Application;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Application Repository for Partnership Application Management.
 * Provides custom query methods for filtering, pagination, and status-based queries.
 */
class ApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Application::class);
    }

    /**
     * Generate a unique reference for a new application.
     */
    public function generateReference(): string
    {
        $prefix = 'APP-';
        $datePart = date('Ymd');
        
        // Find the highest sequence number for today
        $queryBuilder = $this->createQueryBuilder('a')
            ->select('MAX(CAST(SUBSTRING(a.reference, :pos) AS INTEGER)) as maxSeq')
            ->where('a.reference LIKE :prefix')
            ->setParameter('prefix', $prefix . $datePart . '%')
            ->setParameter('pos', strlen($prefix . $datePart));
        
        $result = $queryBuilder->getQuery()->getSingleScalarResult();
        $sequence = ($result ?: 0) + 1;
        
        return $prefix . $datePart . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Find applications by status.
     */
    public function findByStatus(string $status): array
    {
        return $this->findBy(['status' => $status]);
    }

    /**
     * Count applications by status.
     */
    public function countByStatus(string $status): int
    {
        return $this->count(['status' => $status]);
    }

    /**
     * Find pending applications awaiting review.
     */
    public function findPendingApplications(): array
    {
        return $this->findBy(['status' => 'PENDING'], ['submittedAt' => 'ASC']);
    }

    /**
     * Find applications under review.
     */
    public function findUnderReviewApplications(): array
    {
        return $this->findBy(['status' => 'UNDER_REVIEW'], ['submittedAt' => 'ASC']);
    }

    /**
     * Find applications submitted in a date range.
     */
    public function findByDateRange(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.submittedAt >= :start')
            ->andWhere('a.submittedAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('a.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find application by reference.
     */
    public function findByReference(string $reference): ?Application
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * Find applications with pagination and optional filtering.
     */
    public function findPaginated(
        int $page = 1,
        int $limit = 10,
        ?string $status = null,
        ?string $search = null,
        ?string $city = null,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null
    ): array {
        $offset = ($page - 1) * $limit;
        
        $queryBuilder = $this->createQueryBuilder('a')
            ->orderBy('a.submittedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        
        // Apply filters
        if ($status && $status !== 'ALL') {
            $queryBuilder->andWhere('a.status = :status')->setParameter('status', $status);
        }
        
        if ($search) {
            $queryBuilder->andWhere('(
                a.reference LIKE :search OR
                a.agencyName LIKE :search OR
                a.legalRepresentative LIKE :search OR
                a.city LIKE :search OR
                a.email LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }
        
        if ($city) {
            $queryBuilder->andWhere('a.city = :city')->setParameter('city', $city);
        }
        
        if ($startDate) {
            $queryBuilder->andWhere('a.submittedAt >= :startDate')->setParameter('startDate', $startDate);
        }
        
        if ($endDate) {
            $queryBuilder->andWhere('a.submittedAt <= :endDate')->setParameter('endDate', $endDate);
        }
        
        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Count applications with optional filtering.
     */
    public function countFiltered(
        ?string $status = null,
        ?string $search = null,
        ?string $city = null,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null
    ): int {
        $queryBuilder = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)');
        
        // Apply filters
        if ($status && $status !== 'ALL') {
            $queryBuilder->andWhere('a.status = :status')->setParameter('status', $status);
        }
        
        if ($search) {
            $queryBuilder->andWhere('(
                a.reference LIKE :search OR
                a.agencyName LIKE :search OR
                a.legalRepresentative LIKE :search OR
                a.city LIKE :search OR
                a.email LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }
        
        if ($city) {
            $queryBuilder->andWhere('a.city = :city')->setParameter('city', $city);
        }
        
        if ($startDate) {
            $queryBuilder->andWhere('a.submittedAt >= :startDate')->setParameter('startDate', $startDate);
        }
        
        if ($endDate) {
            $queryBuilder->andWhere('a.submittedAt <= :endDate')->setParameter('endDate', $endDate);
        }
        
        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Find applications by email.
     */
    public function findByEmail(string $email): ?Application
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Find applications by phone.
     */
    public function findByPhone(string $phone): ?Application
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    /**
     * Get applications that have been reviewed by a specific reviewer.
     */
    public function findByReviewer(string $reviewer): array
    {
        return $this->findBy(['reviewer' => $reviewer], ['reviewedAt' => 'DESC']);
    }

    /**
     * Get recent applications (last N days).
     */
    public function findRecent(int $days = 7): array
    {
        $startDate = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('a')
            ->where('a.submittedAt >= :startDate')
            ->setParameter('startDate', $startDate)
            ->orderBy('a.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
