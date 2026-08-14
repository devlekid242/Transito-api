<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function save(AuditLog $log, bool $flush = false): void
    {
        $this->getEntityManager()->persist($log);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function findPage(array $filters, int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $limit = min(200, max(1, $limit));
        $qb = $this->createQueryBuilder('a');

        foreach (['actorType' => 'actorType', 'action' => 'action', 'targetType' => 'targetType', 'targetId' => 'targetId'] as $key => $field) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $qb->andWhere("a.$field = :$key")->setParameter($key, $filters[$key]);
            }
        }
        if (!empty($filters['from'])) $qb->andWhere('a.createdAt >= :from')->setParameter('from', $filters['from']);
        if (!empty($filters['to'])) $qb->andWhere('a.createdAt <= :to')->setParameter('to', $filters['to']);

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('a.createdAt', 'DESC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => $total ? (int) ceil($total / $limit) : 0];
    }
}
