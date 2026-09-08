<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobRun>
 */
class JobRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobRun::class);
    }

    /**
     * Utilisé par l'API admin (étape suivante) pour lister/filtrer les rapports.
     *
     * @return JobRun[]
     */
    public function search(
        ?string $commandName = null,
        ?string $status = null,
        ?\DateTimeInterface $since = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('j')
            ->orderBy('j.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($commandName !== null) {
            $qb->andWhere('j.commandName = :commandName')->setParameter('commandName', $commandName);
        }
        if ($status !== null) {
            $qb->andWhere('j.status = :status')->setParameter('status', $status);
        }
        if ($since !== null) {
            $qb->andWhere('j.startedAt >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Dernière exécution connue pour chaque commande (pour des cartes "statut actuel" sur le dashboard).
     *
     * @return JobRun[]
     */
    public function findLatestPerCommand(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->executeQuery(
            'SELECT j.* FROM job_run j
             INNER JOIN (
                 SELECT command_name, MAX(started_at) AS max_started
                 FROM job_run
                 GROUP BY command_name
             ) latest
             ON latest.command_name = j.command_name AND latest.max_started = j.started_at'
        )->fetchAllAssociative();

        if (!$rows) {
            return [];
        }

        $ids = array_column($rows, 'id');

        return $this->createQueryBuilder('j')
            ->andWhere('j.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('j.commandName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
