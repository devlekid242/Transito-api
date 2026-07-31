<?php

namespace App\Repository;

use App\Entity\ApplicationDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ApplicationDocument Repository for managing application document storage and retrieval.
 * Provides custom query methods for filtering documents by type, application, and upload date.
 */
class ApplicationDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApplicationDocument::class);
    }

    /**
     * Find documents by application.
     */
    public function findByApplication(int $applicationId): array
    {
        return $this->findBy(['application' => $applicationId], ['uploadedAt' => 'ASC']);
    }

    /**
     * Find documents by type.
     */
    public function findByType(string $type): array
    {
        return $this->findBy(['type' => $type], ['uploadedAt' => 'DESC']);
    }

    /**
     * Find documents by application and type.
     */
    public function findByApplicationAndType(int $applicationId, string $type): array
    {
        return $this->findBy(['application' => $applicationId, 'type' => $type]);
    }

    /**
     * Count documents by type.
     */
    public function countByType(string $type): int
    {
        return $this->count(['type' => $type]);
    }

    /**
     * Find documents uploaded in a date range.
     */
    public function findByDateRange(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.uploadedAt >= :start')
            ->andWhere('d.uploadedAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if an application has all required document types.
     */
    public function hasAllRequiredTypes(int $applicationId, array $requiredTypes): bool
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->select('COUNT(DISTINCT d.type) as typeCount')
            ->where('d.application = :applicationId')
            ->andWhere('d.type IN (:types)')
            ->setParameter('applicationId', $applicationId)
            ->setParameter('types', $requiredTypes);
        
        $result = $queryBuilder->getQuery()->getSingleScalarResult();
        
        return (int) $result === count($requiredTypes);
    }

    /**
     * Get document types for an application.
     */
    public function getTypesForApplication(int $applicationId): array
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->select('DISTINCT d.type')
            ->where('d.application = :applicationId')
            ->setParameter('applicationId', $applicationId);
        
        $results = $queryBuilder->getQuery()->getSingleColumnResult();
        
        return $results ?: [];
    }

    /**
     * Find documents that are missing from an application.
     */
    public function findMissingTypes(int $applicationId, array $requiredTypes): array
    {
        $existingTypes = $this->getTypesForApplication($applicationId);
        return array_diff($requiredTypes, $existingTypes);
    }

    /**
     * Find documents with pagination.
     */
    public function findPaginated(
        int $page = 1,
        int $limit = 10,
        ?string $type = null,
        ?string $search = null,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null
    ): array {
        $offset = ($page - 1) * $limit;
        
        $queryBuilder = $this->createQueryBuilder('d')
            ->leftJoin('d.application', 'a')
            ->orderBy('d.uploadedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        
        // Apply filters
        if ($type && $type !== 'ALL') {
            $queryBuilder->andWhere('d.type = :type')->setParameter('type', $type);
        }
        
        if ($search) {
            $queryBuilder->andWhere('(
                d.name LIKE :search OR
                d.originalFilename LIKE :search OR
                a.reference LIKE :search OR
                a.agencyName LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }
        
        if ($startDate) {
            $queryBuilder->andWhere('d.uploadedAt >= :startDate')->setParameter('startDate', $startDate);
        }
        
        if ($endDate) {
            $queryBuilder->andWhere('d.uploadedAt <= :endDate')->setParameter('endDate', $endDate);
        }
        
        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Count documents with optional filtering.
     */
    public function countFiltered(
        ?string $type = null,
        ?string $search = null,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null
    ): int {
        $queryBuilder = $this->createQueryBuilder('d')
            ->leftJoin('d.application', 'a')
            ->select('COUNT(d.id)');
        
        // Apply filters
        if ($type && $type !== 'ALL') {
            $queryBuilder->andWhere('d.type = :type')->setParameter('type', $type);
        }
        
        if ($search) {
            $queryBuilder->andWhere('(
                d.name LIKE :search OR
                d.originalFilename LIKE :search OR
                a.reference LIKE :search OR
                a.agencyName LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }
        
        if ($startDate) {
            $queryBuilder->andWhere('d.uploadedAt >= :startDate')->setParameter('startDate', $startDate);
        }
        
        if ($endDate) {
            $queryBuilder->andWhere('d.uploadedAt <= :endDate')->setParameter('endDate', $endDate);
        }
        
        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Get total storage size for all documents.
     */
    public function getTotalStorageSize(): int
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->select('SUM(CAST(d.size AS INTEGER)) as totalSize');
        
        $result = $queryBuilder->getQuery()->getSingleScalarResult();
        
        return (int) ($result ?: 0);
    }

    /**
     * Get storage size for an application's documents.
     */
    public function getStorageSizeForApplication(int $applicationId): int
    {
        $queryBuilder = $this->createQueryBuilder('d')
            ->select('SUM(CAST(d.size AS INTEGER)) as totalSize')
            ->where('d.application = :applicationId')
            ->setParameter('applicationId', $applicationId);
        
        $result = $queryBuilder->getQuery()->getSingleScalarResult();
        
        return (int) ($result ?: 0);
    }
}
