<?php

namespace App\Repository;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Admin>
 */
class AdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    /**
     * Find admin by User
     */
    public function findByUser(User $user): ?Admin
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find active admins
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find admins by role
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.adminRole = :role')
            ->setParameter('role', $role)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find admins by status
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :status')
            ->setParameter('status', $status)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find admins by department
     */
    public function findByDepartment(string $department): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.department = :department')
            ->setParameter('department', $department)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find admins with specific permission
     */
    public function findWithPermission(string $permission): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('JSON_CONTAINS(a.permissions, JSON_QUOTE(:permission)) = true')
            ->setParameter('permission', $permission)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total active admins
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get admin with full details (including user info)
     */
    public function findWithDetails(int $id): ?Admin
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->andWhere('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
