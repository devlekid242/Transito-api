<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Agency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Count reservations created today.
     */
    public function countReservationsToday(): int
    {
        $startOfDay = new \DateTime('today');
        $endOfDay = new \DateTime('tomorrow');

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startOfDay)
            ->setParameter('end', $endOfDay)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count reservations created this week.
     */
    public function countReservationsThisWeek(): int
    {
        $startOfWeek = new \DateTime('this week');
        $endOfWeek = new \DateTime('next week');

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startOfWeek)
            ->setParameter('end', $endOfWeek)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get average fill rate across all trips.
     * Uses seatsReserved from Trip and capacity from Bus.
     */
    public function getAverageFillRate(): float
    {
        // Get average fill rate from trips that have a bus assigned
        $result = $this->createQueryBuilder('r')
            ->select('AVG(t.seatsReserved) as avgReserved, AVG(b.capacity) as avgCapacity, COUNT(r.id) as count')
            ->join('r.trip', 't')
            ->join('t.bus', 'b')
            ->where('r.paymentStatus = :status')
            ->setParameter('status', 'paye')
            ->getQuery()
            ->getResult();

        $data = $result[0] ?? [];
        $avgReserved = (float) ($data['avgReserved'] ?? 0);
        $avgCapacity = (float) ($data['avgCapacity'] ?? 40); // Default to 40 if no bus capacity

        if ($avgCapacity === 0) {
            return 0.0;
        }

        return min(100.0, round(($avgReserved / $avgCapacity) * 100, 2));
    }

    /**
     * Get cancellation rate percentage.
     * Uses paymentStatus instead of status since Reservation doesn't have a status field.
     */
    public function getCancellationRate(): float
    {
        $total = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            return 0.0;
        }

        // Count reservations with paymentStatus = 'annule' or 'echoue'
        $cancelled = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.paymentStatus IN (:statuses)')
            ->setParameter('statuses', ['annule', 'echoue'])
            ->getQuery()
            ->getSingleScalarResult();

        return round(($cancelled / $total) * 100, 2);
    }

    /**
     * Get payment method distribution.
     */
    public function getPaymentMethodDistribution(): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('r.paymentMethod, COUNT(r.id) as count')
            ->where('r.paymentMethod IS NOT NULL')
            ->groupBy('r.paymentMethod')
            ->getQuery()
            ->getResult();

        $distribution = [];
        foreach ($results as $row) {
            $distribution[$row['paymentMethod']] = (int) $row['count'];
        }

        return $distribution;
    }

    // Dans ReservationRepository.php
    public function findRecentByAgency(Agency $agency, int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find top routes by reservations and revenue.
     */
    public function findTopRoutes(int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->select("CONCAT(t.departureCity, CONCAT(' -> ', t.arrivalCity)) as route, COUNT(r.id) as reservationCount, SUM(r.totalAmount) as totalAmount")
            ->join('r.trip', 't')
            ->where('r.paymentStatus = :status')
            ->setParameter('status', 'paye')
            ->groupBy('t.departureCity', 't.arrivalCity') // 👈 On regroupe par les deux villes
            ->orderBy('totalAmount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get reservations count grouped by day for the last 7 days.
     */
    public function getReservationsByDay(): array
    {
        $startDate = new \DateTime('-7 days');

        return $this->createQueryBuilder('r')
            ->select('DATE(r.createdAt) as date, COUNT(r.id) as count')
            ->where('r.createdAt >= :startDate')
            ->setParameter('startDate', $startDate)
            ->groupBy('date') // ✅ Correction : Utilisation de l'alias 'date' à la place de 'DATE(r.createdAt)'
            ->orderBy('date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count reservations by agency.
     */
    public function countReservationsByAgency($agency, \DateTimeInterface $startDate = null, \DateTimeInterface $endDate = null): int
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency);

        if ($startDate) {
            $qb->andWhere('r.createdAt >= :startDate')->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('r.createdAt <= :endDate')->setParameter('endDate', $endDate);
        }

        return (int) $qb->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get total revenue by agency.
     */
    public function getTotalRevenueByAgency($agency, \DateTimeInterface $startDate = null, \DateTimeInterface $endDate = null): float
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->andWhere('r.paymentStatus = :status')
            ->setParameter('status', 'paye');

        if ($startDate) {
            $qb->andWhere('r.createdAt >= :startDate')->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('r.createdAt <= :endDate')->setParameter('endDate', $endDate);
        }

        $result = $qb->select('SUM(r.totalAmount) as total')
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Get average fill rate by agency.
     */
    public function getAverageFillRateByAgency($agency): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(t.seatsReserved) as avgReserved, AVG(b.capacity) as avgCapacity, COUNT(r.id) as count')
            ->join('r.trip', 't')
            ->join('t.bus', 'b')
            ->where('t.agency = :agency')
            ->andWhere('r.paymentStatus = :status')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'paye')
            ->getQuery()
            ->getResult();

        $data = $result[0] ?? [];
        $avgReserved = (float) ($data['avgReserved'] ?? 0);
        $avgCapacity = (float) ($data['avgCapacity'] ?? 40); // Default to 40 if no bus capacity

        if ($avgCapacity === 0) {
            return 0.0;
        }

        return min(100.0, round(($avgReserved / $avgCapacity) * 100, 2));
    }

    /**
     * Get cancellation rate by agency.
     */
    public function getCancellationRateByAgency($agency): float
    {
        $total = (int) $this->createQueryBuilder('r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            return 0.0;
        }

        // Count reservations with paymentStatus = 'annule' or 'echoue'
        $cancelled = (int) $this->createQueryBuilder('r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('agency', $agency)
            ->setParameter('statuses', ['annule', 'echoue'])
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return round(($cancelled / $total) * 100, 2);
    }
}
