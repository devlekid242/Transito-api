<?php

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\Agency;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * Compte les tickets validés par un agent pendant une période
     */
    public function countValidatedByAgent(Agent $agent, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('t.validatedAt >= :start')
            ->andWhere('t.validatedAt <= :end')
            ->setParameter('agency', $agent->getAgency())
            ->setParameter('status', 'embarque')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les tickets en attente (non validés) pour une agence
     */
    public function countPendingByTrip(Agency $agency, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('tr.departureTime >= :start')
            ->andWhere('tr.departureTime <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'en_attente')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les passagers embarqués
     */
    public function countBoardedPassengers(Agent $agent, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('t.validatedAt >= :start')
            ->andWhere('t.validatedAt <= :end')
            ->setParameter('agency', $agent->getAgency())
            ->setParameter('status', 'embarque')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les passagers absent (no-show)
     */
    public function countNoShowPassengers(Agent $agent, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('tr.departureTime >= :start')
            ->andWhere('tr.departureTime <= :end')
            ->setParameter('agency', $agent->getAgency())
            ->setParameter('status', 'annule')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les tickets par agence
     */
    public function countTicketsByAgency(Agency $agency, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('r.createdAt >= :start')
            ->andWhere('r.createdAt <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }
}
