<?php

namespace App\Repository;

use App\Entity\Trip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    /**
     * Compte les trajets actifs (en cours)
     */
    public function countActiveTrips(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.agency = :agency')
            ->andWhere('t.status IN (:statuses)')
            ->andWhere('t.departureTime >= :start')
            ->andWhere('t.departureTime <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('statuses', ['embarquement', 'en_route', 'planifie'])
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les trajets complétés
     */
    public function countCompletedTrips(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('t.departureTime >= :start')
            ->andWhere('t.departureTime <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'termine')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte les trajets annulés
     */
    public function countCancelledTrips(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.agency = :agency')
            ->andWhere('t.status = :status')
            ->andWhere('t.departureTime >= :start')
            ->andWhere('t.departureTime <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'annule')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Compte le nombre de trajets d'une agence
     */
    public function countTripsByAgency(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.agency = :agency')
            ->andWhere('t.departureTime >= :start')
            ->andWhere('t.departureTime <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Récupère les trajets par statut
     */
    public function countTripsByStatus(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): array
    {
        $statuses = ['planifie', 'embarquement', 'en_route', 'termine', 'annule'];
        $result = [];

        foreach ($statuses as $status) {
            $count = $this->createQueryBuilder('t')
                ->select('COUNT(t.id)')
                ->where('t.agency = :agency')
                ->andWhere('t.status = :status')
                ->andWhere('t.departureTime >= :start')
                ->andWhere('t.departureTime <= :end')
                ->setParameter('agency', $agency)
                ->setParameter('status', $status)
                ->setParameter('start', $start)
                ->setParameter('end', $end)
                ->getQuery()
                ->getSingleScalarResult() ?? 0;

            $result[$status] = $count;
        }

        return $result;
    }

    /**
     * Récupère les trajets dans une période
     */
    public function findTripsWithinPeriod(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.agency = :agency')
            ->andWhere('t.departureTime >= :start')
            ->andWhere('t.departureTime <= :end')
            ->orderBy('t.departureTime', 'ASC')
            ->setParameter('agency', $agency)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get top routes by agency.
     */
    public function getTopRoutesByAgency($agency, int $limit = 5): array
    {
        return $this->createQueryBuilder('t')
            ->select("CONCAT(t.departureCity, CONCAT(' -> ', t.arrivalCity)) as route, COUNT(t.id) as reservationCount, SUM(t.price * t.seatsReserved) as totalRevenue, AVG((t.seatsReserved * 100) / b.capacity) as fillRate")
            ->join('t.bus', 'b')
            ->where('t.agency = :agency')
            ->andWhere('b.capacity > 0')
            ->setParameter('agency', $agency)
            ->groupBy('t.departureCity', 't.arrivalCity')
            ->orderBy('totalRevenue', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find trip with reservations and tickets (for manifest).
     */
    public function findWithManifest(int $tripId): ?Trip
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.reservations', 'r')
            ->leftJoin('r.tickets', 'ticket')
            ->leftJoin('t.bus', 'b')
            ->leftJoin('t.agency', 'a')
            ->where('t.id = :tripId')
            ->setParameter('tripId', $tripId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find trips with pagination and optional filters for admin listing.
     */
    public function findTripsForAdmin(
        int $page = 1,
        int $limit = 10,
        ?string $status = null,
        ?int $agencyId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $search = null
    ): array {
        $offset = ($page - 1) * $limit;
        
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.agency', 'a')
            ->leftJoin('t.bus', 'b')
            ->leftJoin('t.reservations', 'r')
            ->select('t', 'a', 'b')
            ->orderBy('t.departureTime', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Apply filters
        if ($status && $status !== 'ALL') {
            $statusMap = [
                'SCHEDULED' => 'planifie',
                'IN_PROGRESS' => ['embarquement', 'en_route'],
                'COMPLETED' => 'termine',
                'CANCELLED' => 'annule',
                'DELAYED' => 'annule'
            ];
            
            if (isset($statusMap[$status])) {
                if (is_array($statusMap[$status])) {
                    $qb->andWhere('t.status IN (:status)')->setParameter('status', $statusMap[$status]);
                } else {
                    $qb->andWhere('t.status = :status')->setParameter('status', $statusMap[$status]);
                }
            }
        }

        if ($agencyId) {
            $qb->andWhere('t.agency = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if ($startDate) {
            $qb->andWhere('t.departureTime >= :startDate')->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('t.departureTime <= :endDate')->setParameter('endDate', $endDate);
        }

        if ($search) {
            $qb->andWhere('(
                t.departureCity LIKE :search OR
                t.arrivalCity LIKE :search OR
                CONCAT(t.departureCity, \' → \', t.arrivalCity) LIKE :search OR
                a.name LIKE :search OR
                t.driverName LIKE :search OR
                b.registrationNumber LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count trips matching filter criteria.
     */
    public function countTripsForAdmin(
        ?string $status = null,
        ?int $agencyId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $search = null
    ): int {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.agency', 'a')
            ->leftJoin('t.bus', 'b')
            ->select('COUNT(t.id)');

        if ($status && $status !== 'ALL') {
            $statusMap = [
                'SCHEDULED' => 'planifie',
                'IN_PROGRESS' => ['embarquement', 'en_route'],
                'COMPLETED' => 'termine',
                'CANCELLED' => 'annule',
                'DELAYED' => 'annule'
            ];
            
            if (isset($statusMap[$status])) {
                if (is_array($statusMap[$status])) {
                    $qb->andWhere('t.status IN (:status)')->setParameter('status', $statusMap[$status]);
                } else {
                    $qb->andWhere('t.status = :status')->setParameter('status', $statusMap[$status]);
                }
            }
        }

        if ($agencyId) {
            $qb->andWhere('t.agency = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if ($startDate) {
            $qb->andWhere('t.departureTime >= :startDate')->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('t.departureTime <= :endDate')->setParameter('endDate', $endDate);
        }

        if ($search) {
            $qb->andWhere('(
                t.departureCity LIKE :search OR
                t.arrivalCity LIKE :search OR
                a.name LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
