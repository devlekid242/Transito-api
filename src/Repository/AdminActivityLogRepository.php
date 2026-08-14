<?php

namespace App\Repository;

use App\Entity\Admin;
use App\Entity\AdminActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminActivityLog>
 *
 * @method AdminActivityLog|null find($id, $lockMode = null, $lockVersion = null)
 * @method AdminActivityLog|null findOneBy(array $criteria, array $orderBy = null)
 * @method AdminActivityLog[]    findAll()
 * @method AdminActivityLog[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AdminActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminActivityLog::class);
    }

    /**
     * Save an activity log entry
     */
    public function save(AdminActivityLog $log): void
    {
        $this->getEntityManager()->persist($log);
        $this->getEntityManager()->flush();
    }

    /**
     * Find activity logs for a specific admin
     *
     * @param Admin $admin The admin to fetch logs for
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return AdminActivityLog[]
     */
    public function findByAdmin(Admin $admin, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.admin = :admin')
            ->setParameter('admin', $admin)
            ->orderBy('l.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent activity logs for a specific admin
     *
     * @param Admin $admin The admin to fetch logs for
     * @param int $limit Maximum number of results (default: 10)
     * @return AdminActivityLog[]
     */
    public function findRecentByAdmin(Admin $admin, int $limit = 10): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.admin = :admin')
            ->setParameter('admin', $admin)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count activity logs for a specific admin
     */
    public function countByAdmin(Admin $admin): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.admin = :admin')
            ->setParameter('admin', $admin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count logs by action type for a specific admin
     */
    public function countByAdminAndActionType(Admin $admin, string $actionType): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.admin = :admin')
            ->andWhere('l.actionType = :actionType')
            ->setParameter('admin', $admin)
            ->setParameter('actionType', $actionType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find activity logs by admin ID (for API endpoints)
     *
     * @param int $adminId The admin ID
     * @param int $page Page number (1-based)
     * @param int $limit Results per page
     * @return array{logs: AdminActivityLog[], total: int, pages: int}
     */
    public function findByAdminId(int $adminId, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $queryBuilder = $this->createQueryBuilder('l')
            ->andWhere('l.admin = :adminId')
            ->setParameter('adminId', $adminId)
            ->orderBy('l.createdAt', 'DESC');

        $countQuery = clone $queryBuilder;
        $total = (int) $countQuery->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        
        $logs = $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $pages = (int) ceil($total / $limit);

        return [
            'logs' => $logs,
            'total' => $total,
            'pages' => $pages > 0 ? $pages : 1,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Get admin activity statistics
     *
     * @param Admin $admin The admin to get stats for
     * @return array{total: int, byType: array}
     */
    public function getAdminActivityStats(Admin $admin): array
    {
        $total = $this->countByAdmin($admin);

        $byType = [];
        $actionTypes = [
            AdminActivityLog::ACTION_TYPE_AUTH,
            AdminActivityLog::ACTION_TYPE_FINANCE,
            AdminActivityLog::ACTION_TYPE_MODERATION,
            AdminActivityLog::ACTION_TYPE_SETTINGS,
            AdminActivityLog::ACTION_TYPE_PROFILE,
            AdminActivityLog::ACTION_TYPE_SYSTEM,
        ];

        foreach ($actionTypes as $type) {
            $byType[$type] = $this->countByAdminAndActionType($admin, $type);
        }

        return [
            'total' => $total,
            'byType' => $byType,
        ];
    }

    //    /**
//     * @return AdminActivityLog[] Returns an array of AdminActivityLog objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('a.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?AdminActivityLog
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
    /**
     * Global audit feed for Super Admin. Supports filtering by type, action,
     * target entity and date range without exposing entity relations directly.
     */
    public function findGlobal(
        int $page = 1,
        int $limit = 50,
        ?string $actionType = null,
        ?string $action = null,
        ?string $targetEntity = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $qb = $this->createQueryBuilder('l');
        if ($actionType) { $qb->andWhere('l.actionType = :actionType')->setParameter('actionType', $actionType); }
        if ($action) { $qb->andWhere('l.action LIKE :action')->setParameter('action', '%'.$action.'%'); }
        if ($targetEntity) { $qb->andWhere('l.targetEntity = :targetEntity')->setParameter('targetEntity', $targetEntity); }
        if ($from) { $qb->andWhere('l.createdAt >= :from')->setParameter('from', $from); }
        if ($to) { $qb->andWhere('l.createdAt <= :to')->setParameter('to', $to); }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        $logs = $qb->orderBy('l.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return ['logs' => $logs, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => max(1, (int) ceil($total / $limit))];
    }

}