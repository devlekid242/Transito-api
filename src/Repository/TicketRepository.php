<?php

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\Agency;
use App\Entity\Ticket;
use App\Entity\Trip;
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
            ->where('t.validatedByAgent = :validatedByAgent')
            ->andWhere('t.status = :status')
            ->andWhere('t.validatedAt >= :start')
            ->andWhere('t.validatedAt <= :end')
            ->setParameter('validatedByAgent', $agent->getId())
            ->setParameter('status', 'embarque')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les tickets en attente (donc "occupant" une place) pour UN trajet donné.
     *
     * 👈 CORRECTIF : Ticket n'a pas de champ `trip` (seulement `reservation`),
     * donc `findBy(['trip' => ...])` ou `count(['trip' => ...])` levaient une
     * QueryException ("unrecognized field"). On passe par la vraie relation
     * Ticket -> Reservation -> Trip.
     */
    public function countPendingForTrip(Trip $trip): int
    {
        return (int) ($this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->where('r.trip = :trip')
            ->andWhere('t.status = :status')
            ->setParameter('trip', $trip)
            ->setParameter('status', 'en_attente')
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
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
            ->where('t.validatedByAgent = :validatedByAgent')
            ->andWhere('t.status = :status')
            ->andWhere('t.validatedAt >= :start')
            ->andWhere('t.validatedAt <= :end')
            ->setParameter('validatedByAgent', $agent->getId())
            ->setParameter('status', 'embarque')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les tickets validés pour TOUTE l'agence (tous agents confondus)
     * sur la période. Sert à calculer un taux de validation cohérent : avant,
     * ce taux comparait des tickets validés PAR UN SEUL agent à des tickets
     * en attente de TOUTE l'agence — deux échelles différentes, donnant un
     * ratio sans signification. Ici numérateur et dénominateur portent tous
     * les deux sur l'agence entière.
     */
    public function countValidatedByAgency(Agency $agency, \DateTime $start, \DateTime $end): int
    {
        return (int) ($this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('t.validatedAt >= :start')
            ->andWhere('t.validatedAt <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'embarque')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    /**
     * Récupère les tickets validés PAR UN AGENT DONNÉ sur la période, avec
     * leur réservation chargée. Sert à calculer le revenu réellement
     * attribuable à cet agent (voir StatisticsController::calculateRevenueByAgent),
     * au lieu du revenu de toute l'agence.
     */
    public function findValidatedByAgentWithinPeriod(Agent $agent, \DateTime $start, \DateTime $end): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.reservation', 'r')
            ->addSelect('r')
            ->where('t.validatedByAgent = :agent')
            ->andWhere('t.status = :status')
            ->andWhere('t.validatedAt >= :start')
            ->andWhere('t.validatedAt <= :end')
            ->setParameter('agent', $agent)
            ->setParameter('status', 'embarque')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les tickets d'un trajet donné en passant correctement par la
     * relation Ticket -> Reservation -> Trip.
     *
     * 👈 CORRECTIF IMPORTANT : le code appelant faisait auparavant
     * `findBy(['reservation' => $trip->getId()])`, ce qui compare
     * `ticket.reservation_id` à l'id d'un Trip — deux séquences d'ID
     * totalement indépendantes. Ça ne renvoyait donc pas les tickets du
     * trajet, mais des tickets choisis au hasard par coïncidence numérique
     * d'id. Cette méthode fait la vraie jointure.
     */
    public function findTicketsByTrip(Trip $trip): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.reservation', 'r')
            ->addSelect('r')
            ->where('r.trip = :trip')
            ->setParameter('trip', $trip)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les passagers absent (no-show)
     *
     * 👈 CORRIGÉ : comptait les tickets au statut "annule" (= remboursés,
     * annulés par le client), ce qui n'a rien à voir avec un no-show. Un
     * no-show est un billet payé et jamais annulé, resté "en_attente" (donc
     * jamais scanné à l'embarquement) alors que le trajet est déjà parti.
     */
    public function countNoShowPassengers(Agent $agent, \DateTime $start, \DateTime $end): int
    {
        $now = new \DateTime();

        return (int) ($this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('tr.departureTime >= :start')
            ->andWhere('tr.departureTime <= :end')
            // Un trajet pas encore parti ne peut pas encore avoir de no-show :
            // le passager a peut-être juste pas encore embarqué.
            ->andWhere('tr.departureTime < :now')
            ->setParameter('agency', $agent->getAgency())
            ->setParameter('status', 'en_attente')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
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

    /**
     * Get the total amount of unvalidated ticket reservations for an agency.
     * This is for calculating the blocked balance: sum of all reservation amounts
     * where passengers have NOT YET been validated as embarked/boarded.
     * 
     * @param Agency $agency The agency to calculate for
     * @return float The total amount of unvalidated tickets
     */
    public function getUnvalidatedTicketsAmountForAgency(Agency $agency): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(r.totalAmount) as total')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'en_attente') // Not yet validated/boarded
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? '0.00');
    }

    /**
     * Get all unvalidated tickets for an agency (for detailed reporting)
     */
    public function findUnvalidatedTicketsForAgency(Agency $agency): array
    {
        return $this->createQueryBuilder('t')
            ->select('t', 'r', 'tr')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.agency = :agency')
            ->andWhere('t.status = :status')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'en_attente')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}