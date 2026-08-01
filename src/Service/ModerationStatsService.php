<?php

namespace App\Service;

use App\Entity\Agency;
use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Trip;
use App\Entity\PaymentLog;
use App\Repository\AgencyRepository;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use App\Repository\TripRepository;
use App\Repository\PaymentLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeInterface;

/**
 * Service for computing moderation and analytics statistics.
 * Handles all complex statistical queries for the Moderation & Analytics Dashboard.
 */
class ModerationStatsService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgencyRepository $agencyRepository,
        private UserRepository $userRepository,
        private ReservationRepository $reservationRepository,
        private TripRepository $tripRepository,
        private PaymentLogRepository $paymentLogRepository,
    ) {}

    /**
     * Get comprehensive moderation statistics.
     * Returns pre-computed statistics for users, agencies, reservations, and financials.
     */
    public function getModerationStats(
        DateTimeInterface $startDate = null,
        DateTimeInterface $endDate = null,
        array $agencyIds = null
    ): array {
        $startDate = $startDate ?? new \DateTime('-30 days');
        $endDate = $endDate ?? new \DateTime('now');

        $userStats = $this->getUserStatistics($startDate, $endDate, $agencyIds);
        $agencyStats = $this->getAgencyStatistics($startDate, $endDate, $agencyIds);
        $reservationStats = $this->getReservationStatistics($startDate, $endDate, $agencyIds);
        $financialStats = $this->getFinancialStatistics($startDate, $endDate, $agencyIds);
        $comparisonData = $this->getComparisonData($startDate, $endDate, $agencyIds);

        return [
            'success' => true,
            'data' => [
                'users' => $userStats,
                'agencies' => $agencyStats,
                'reservations' => $reservationStats,
                'finance' => $financialStats,
                'comparison' => $comparisonData,
            ],
            'timestamp' => (new \DateTime())->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Get user statistics.
     *
     * CORRECTIF : User n'a aucune relation `agency` (ce n'est pas un membre
     * d'agence, c'est un voyageur/passager). Le filtre `u.agency IN (...)`
     * levait une QueryException dès qu'un agency_ids était fourni. Les
     * compteurs purement basés sur User ne sont donc plus filtrés par agence
     * — seules les statistiques qui passent par Reservation/Trip le sont
     * encore (voir getUserCancellationRate / getAverageReservationsPerUser).
     */
    private function getUserStatistics(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        $totalUsers = (int) $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        $activeUsers = $this->countUsersByStatus('active', $startDate, $endDate);
        $blockedUsers = $this->countUsersByStatus('blocked', $startDate, $endDate);

        // User types (dérivés des relations Admin/Agent, pas de la colonne `roles`)
        $clients = $this->countClients($startDate, $endDate);
        $agents = $this->countUsersWithRelation('agent', $startDate, $endDate);
        $admins = $this->countUsersWithRelation('admin', $startDate, $endDate);

        $startOfWeek = new \DateTime('this week');
        $newThisWeek = $this->countNewUsers($startOfWeek, $endDate);

        $startOfMonth = new \DateTime('first day of this month');
        $newThisMonth = $this->countNewUsers($startOfMonth, $endDate);

        $avgReservations = $this->getAverageReservationsPerUser($startDate, $endDate, $agencyIds);
        $cancellationRate = $this->getUserCancellationRate($startDate, $endDate, $agencyIds);

        $usersByType = [
            ['label' => 'Clients', 'value' => $clients, 'color' => '#2563eb'],
            ['label' => 'Agents', 'value' => $agents, 'color' => '#16a34a'],
            ['label' => 'Administrateurs', 'value' => $admins, 'color' => '#9b1818'],
        ];

        return [
            'total' => $totalUsers,
            'active' => $activeUsers,
            'blocked' => $blockedUsers,
            'newThisWeek' => $newThisWeek,
            'newThisMonth' => $newThisMonth,
            'clients' => $clients,
            'agents' => $agents,
            'admins' => $admins,
            'avgReservationsPerUser' => round($avgReservations, 2),
            'cancellationRate' => round($cancellationRate, 2),
            'usersByType' => $usersByType,
        ];
    }

    /**
     * Count users by status.
     */
    private function countUsersByStatus(
        string $status,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): int {
        return (int) $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->andWhere('u.status = :status')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * CORRECTIF : remplace countUsersByRole('CLIENT'/'AGENT'/'ADMIN', ...) qui
     * cherchait ces mots dans la colonne JSON `roles`. Or User::getRoles()
     * calcule ROLE_ADMIN dynamiquement à partir de la relation `admin` (voir
     * User.php) — ces libellés ne sont jamais persistés tels quels en base,
     * donc l'ancienne requête retournait toujours 0. On se base ici sur les
     * vraies relations `admin` / `agent` de l'entité User avec une jointure explicite.
     *
     * @param 'admin'|'agent' $relation
     */
    private function countUsersWithRelation(
        string $relation,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): int {
        return (int) $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            // On utilise un INNER JOIN explicite au lieu du IS NOT NULL
            ->join("u.{$relation}", 'rel')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Un "client" est un utilisateur qui n'est ni membre du staff agence
     * (agent) ni administrateur de la plateforme.
     */
    private function countClients(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): int {
        return (int) $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt BETWEEN :start AND :end')
            // CORRECTION ICI : Jointures explicites pour cibler l'ID des relations
            ->leftJoin('u.admin', 'a')
            ->leftJoin('u.agent', 'ag')
            ->andWhere('a.id IS NULL')
            ->andWhere('ag.id IS NULL')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count new users in a date range.
     */
    private function countNewUsers(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): int {
        return (int) $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get average reservations per user.
     *
     * CORRECTIF : le filtre agence passait par `u.agency`, inexistant. On
     * filtre désormais via `r.trip -> t.agency`, seul chemin réel vers
     * Agency depuis une Reservation.
     */
    private function getAverageReservationsPerUser(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): float {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as totalReservations, COUNT(DISTINCT r.user) as totalUsers')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('r.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $result = $qb->getQuery()->getResult();
        $data = $result[0] ?? [];

        $totalReservations = (int) ($data['totalReservations'] ?? 0);
        $totalUsers = (int) ($data['totalUsers'] ?? 0);

        return $totalUsers > 0 ? $totalReservations / $totalUsers : 0;
    }

    /**
     * Get user cancellation rate.
     *
     * CORRECTIF : même correction de filtre agence que ci-dessus (r.agency
     * -> join r.trip / t.agency).
     */
    private function getUserCancellationRate(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): float {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as total, COUNT(CASE WHEN r.paymentStatus IN (:cancelledStatuses) THEN 1 ELSE 0 END) as cancelled')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('cancelledStatuses', ['annule', 'echoue']);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('r.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $result = $qb->getQuery()->getResult();
        $data = $result[0] ?? [];

        $total = (int) ($data['total'] ?? 0);
        $cancelled = (int) ($data['cancelled'] ?? 0);

        return $total > 0 ? ($cancelled / $total) * 100 : 0;
    }

    /**
     * Get agency statistics.
     */
    private function getAgencyStatistics(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        // CORRECTIF : l'ancien code réutilisait le même QueryBuilder mutable
        // pour deux comptages différents (->select()/->andWhere() cumulés sur
        // le même objet), ce qui "marchait" par coïncidence d'ordre d'appel
        // mais est fragile. On utilise désormais un builder frais par requête.
        $totalQb = $this->agencyRepository->createQueryBuilder('a');
        if ($agencyIds && !empty($agencyIds)) {
            $totalQb->where('a.id IN (:agencyIds)')->setParameter('agencyIds', $agencyIds);
        }
        $totalAgencies = (int) $totalQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $activeQb = $this->agencyRepository->createQueryBuilder('a');
        $activeQb->where('a.status = :status')->setParameter('status', 'active');
        if ($agencyIds && !empty($agencyIds)) {
            $activeQb->andWhere('a.id IN (:agencyIds)')->setParameter('agencyIds', $agencyIds);
        }
        $activeAgencies = (int) $activeQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $suspendedAgencies = $totalAgencies - $activeAgencies;

        $kycStats = $this->getAgencyKycStatistics($agencyIds);

        $avgReservations = $this->getAverageReservationsPerAgency($startDate, $endDate, $agencyIds);
        $avgFillRate = $this->getAverageAgencyFillRate($startDate, $endDate, $agencyIds);

        return [
            'total' => $totalAgencies,
            'active' => $activeAgencies,
            'suspended' => $suspendedAgencies,
            'kycVerified' => $kycStats['verified'],
            'kycPending' => $kycStats['pending'],
            'kycMissing' => $kycStats['missing'],
            'kycRejected' => $kycStats['rejected'],
            'avgReservationsPerAgency' => round($avgReservations, 2),
            'avgFillRate' => round($avgFillRate, 2),
        ];
    }

    /**
     * Get agency KYC statistics.
     * (start/end retirés : un statut KYC est évalué "à date", la période
     * n'était de toute façon jamais utilisée par getAgencyKycStatus.)
     */
    private function getAgencyKycStatistics(array $agencyIds = null): array
    {
        $qb = $this->agencyRepository->createQueryBuilder('a');

        if ($agencyIds && !empty($agencyIds)) {
            $qb->where('a.id IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $agencies = $qb->getQuery()->getResult();

        $stats = [
            'verified' => 0,
            'pending' => 0,
            'missing' => 0,
            'rejected' => 0,
        ];

        foreach ($agencies as $agency) {
            $kycStatus = $this->getAgencyKycStatus($agency);
            $stats[$kycStatus]++;
        }

        return $stats;
    }

    /**
     * Determine KYC status for an agency based on its documents.
     */
    private function getAgencyKycStatus(Agency $agency): string
    {
        $documents = $agency->getDocuments();

        if ($documents->isEmpty()) {
            return 'missing';
        }

        $hasRejected = false;
        $hasApproved = false;
        $hasPending = false;

        foreach ($documents as $doc) {
            switch ($doc->getStatus()) {
                case 'approved':
                    $hasApproved = true;
                    break;
                case 'pending':
                    $hasPending = true;
                    break;
                case 'rejected':
                    $hasRejected = true;
                    break;
            }
        }

        if ($hasRejected) {
            return 'rejected';
        }

        if ($hasPending && !$hasApproved) {
            return 'pending';
        }

        if ($hasApproved) {
            return 'verified';
        }

        return 'missing';
    }

    /**
     * Get average reservations per agency.
     *
     * CORRECTIF : `r.agency` n'existe pas sur Reservation. On rejoint
     * `r.trip` pour atteindre `t.agency`, aussi bien pour le comptage
     * distinct des agences que pour le filtre agency_ids.
     */
    private function getAverageReservationsPerAgency(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): float {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as totalReservations, COUNT(DISTINCT t.agency) as totalAgencies')
            ->join('r.trip', 't')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $result = $qb->getQuery()->getResult();
        $data = $result[0] ?? [];

        $totalReservations = (int) ($data['totalReservations'] ?? 0);
        $totalAgencies = (int) ($data['totalAgencies'] ?? 0);

        return $totalAgencies > 0 ? $totalReservations / $totalAgencies : 0;
    }

    /**
     * Get average fill rate across all agencies.
     */
    private function getAverageAgencyFillRate(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): float {
        $qb = $this->tripRepository->createQueryBuilder('t')
            ->select('AVG(t.seatsReserved) as avgReserved, AVG(b.capacity) as avgCapacity')
            ->join('t.bus', 'b')
            ->join('t.agency', 'a')
            ->where('t.departureTime BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->andWhere('a.id IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $result = $qb->getQuery()->getResult();
        $data = $result[0] ?? [];

        $avgReserved = (float) ($data['avgReserved'] ?? 0);
        $avgCapacity = (float) ($data['avgCapacity'] ?? 40);

        return $avgCapacity > 0 ? min(100, ($avgReserved / $avgCapacity) * 100) : 0;
    }

    /**
     * Get reservation statistics.
     *
     * CORRECTIF : l'ancien code faisait `if (agencyIds) { filtre agence }
     * else { filtre date }` — un agency_ids fourni faisait donc ignorer
     * complètement la période demandée. Les deux filtres sont maintenant
     * cumulés (AND), et le filtre agence passe par `r.trip -> t.agency`.
     */
    private function getReservationStatistics(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('r.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $totalReservations = (int) $qb->getQuery()->getSingleScalarResult();

        $reservationsByStatus = $this->getReservationsByStatus($startDate, $endDate, $agencyIds);
        $monthlyReservations = $this->getMonthlyReservations($startDate, $endDate, $agencyIds);
        $fillRate = $this->getOverallFillRate($startDate, $endDate, $agencyIds);
        $cancellationRate = $this->getOverallCancellationRate($startDate, $endDate, $agencyIds);

        return [
            'total' => $totalReservations,
            'fillRate' => round($fillRate, 2),
            'cancellationRate' => round($cancellationRate, 2),
            'reservationsByStatus' => $reservationsByStatus,
            'monthlyReservations' => $monthlyReservations,
        ];
    }

    /**
     * Get reservations grouped by status.
     *
     * CORRECTIF x2 : (1) filtre agence via r.trip/t.agency au lieu de
     * r.agency ; (2) la clé 'remboursee' ne correspondait à aucune valeur
     * réelle de Reservation::paymentStatus (qui vaut 'rembourse', sans e
     * final d'après l'Assert\Choice de l'entité) — les réservations
     * remboursées étaient donc silencieusement exclues du donut chart.
     */
    private function getReservationsByStatus(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select('r.paymentStatus, COUNT(r.id) as count')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->groupBy('r.paymentStatus')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('r.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $results = $qb->getQuery()->getResult();

        $statusMap = [
            'paye' => ['label' => 'Payées', 'color' => '#16a34a'],
            'en_attente' => ['label' => 'En attente', 'color' => '#f59e0b'],
            'annule' => ['label' => 'Annulées', 'color' => '#ef4444'],
            'echoue' => ['label' => 'Échec', 'color' => '#6b7280'],
            'rembourse' => ['label' => 'Remboursées', 'color' => '#8b5cf6'],
        ];

        $data = [];
        foreach ($results as $row) {
            $status = $row['paymentStatus'] ?? 'unknown';
            if (isset($statusMap[$status])) {
                $data[] = [
                    'label' => $statusMap[$status]['label'],
                    'value' => (int) $row['count'],
                    'color' => $statusMap[$status]['color'],
                ];
            }
        }

        return $data;
    }

    /**
     * Get monthly reservations count.
     *
     * CORRECTIF : filtre agence via r.trip/t.agency.
     */
    private function getMonthlyReservations(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select("DATE_FORMAT(r.createdAt, '%Y-%m') as month, COUNT(r.id) as count")
            ->where('r.createdAt BETWEEN :start AND :end')
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('r.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $results = $qb->getQuery()->getResult();

        $current = clone $startDate;
        $data = [];

        while ($current <= $endDate) {
            $monthKey = $current->format('Y-m');
            $found = false;

            foreach ($results as $row) {
                if ($row['month'] === $monthKey) {
                    $data[] = (int) $row['count'];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data[] = 0;
            }

            $current->modify('+1 month');
        }

        return $data;
    }

    /**
     * Get overall fill rate.
     *
     * CORRECTIF : le join vers `t` (r.trip) existait déjà ici ; il suffisait
     * de filtrer sur `t.agency` au lieu du champ inexistant `r.agency`.
     */
    private function getOverallFillRate(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): float {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select('AVG(t.seatsReserved) as avgReserved, AVG(b.capacity) as avgCapacity')
            ->join('r.trip', 't')
            ->join('t.bus', 'b')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus = :status')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'paye');

        if ($agencyIds && !empty($agencyIds)) {
            $qb->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $result = $qb->getQuery()->getResult();
        $data = $result[0] ?? [];

        $avgReserved = (float) ($data['avgReserved'] ?? 0);
        $avgCapacity = (float) ($data['avgCapacity'] ?? 40);

        return $avgCapacity > 0 ? min(100, ($avgReserved / $avgCapacity) * 100) : 0;
    }

    /**
     * Get overall cancellation rate.
     *
     * CORRECTIF : filtre agence via r.trip/t.agency.
     */
    private function getOverallCancellationRate(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): float {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as total, COUNT(CASE WHEN r.paymentStatus IN (:cancelledStatuses) THEN 1 ELSE 0 END) as cancelled')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('cancelledStatuses', ['annule', 'echoue']);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('r.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $result = $qb->getQuery()->getResult();
        $data = $result[0] ?? [];

        $total = (int) ($data['total'] ?? 0);
        $cancelled = (int) ($data['cancelled'] ?? 0);

        return $total > 0 ? ($cancelled / $total) * 100 : 0;
    }

    /**
     * Get financial statistics.
     *
     * CORRECTIF : `pl.agency` n'existe pas sur PaymentLog. Le seul chemin
     * vers Agency est PaymentLog -> reservation -> trip -> agency.
     */
    private function getFinancialStatistics(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        $qb = $this->paymentLogRepository->createQueryBuilder('pl')
            ->select('SUM(pl.amount) as totalAmount, COUNT(pl.id) as totalTransactions')
            ->where('pl.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('pl.reservation', 'res')
                ->join('res.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $result = $qb->getQuery()->getResult();
        $data = $result[0] ?? [];

        $totalAmount = (float) ($data['totalAmount'] ?? 0);
        $totalTransactions = (int) ($data['totalTransactions'] ?? 0);

        $successfulPayments = $this->countPaymentsByStatus('SUCCESS', $startDate, $endDate, $agencyIds);
        $pendingPayments = $this->countPaymentsByStatus('PENDING', $startDate, $endDate, $agencyIds);
        $failedPayments = $this->countPaymentsByStatus('FAILED', $startDate, $endDate, $agencyIds);

        $monthlyRevenue = $this->getMonthlyRevenue($startDate, $endDate, $agencyIds);

        return [
            'totalRevenue' => $totalAmount,
            'totalTransactions' => $totalTransactions,
            'successfulPayments' => $successfulPayments,
            'pendingPayments' => $pendingPayments,
            'failedPayments' => $failedPayments,
            'monthlyRevenue' => $monthlyRevenue,
        ];
    }

    /**
     * Count payments by status.
     *
     * CORRECTIF : filtre agence via pl.reservation -> res.trip -> t.agency.
     */
    private function countPaymentsByStatus(
        string $status,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): int {
        $qb = $this->paymentLogRepository->createQueryBuilder('pl')
            ->select('COUNT(pl.id)')
            ->where('pl.createdAt BETWEEN :start AND :end')
            ->andWhere('pl.status = :status')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', $status);

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('pl.reservation', 'res')
                ->join('res.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get monthly revenue.
     *
     * CORRECTIF : filtre agence via pl.reservation -> res.trip -> t.agency.
     */
    private function getMonthlyRevenue(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        $qb = $this->paymentLogRepository->createQueryBuilder('pl')
            ->select("DATE_FORMAT(pl.createdAt, '%Y-%m') as month, SUM(pl.amount) as amount")
            ->where('pl.createdAt BETWEEN :start AND :end')
            ->andWhere('pl.status = :status')
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'SUCCESS');

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('pl.reservation', 'res')
                ->join('res.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $results = $qb->getQuery()->getResult();

        $current = clone $startDate;
        $data = [];

        while ($current <= $endDate) {
            $monthKey = $current->format('Y-m');
            $found = false;

            foreach ($results as $row) {
                if ($row['month'] === $monthKey) {
                    $data[] = (float) ($row['amount'] ?? 0);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data[] = 0;
            }

            $current->modify('+1 month');
        }

        return $data;
    }

    /**
     * Get comparison data for agencies.
     */
    private function getComparisonData(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null
    ): array {
        $qb = $this->agencyRepository->createQueryBuilder('a');

        if ($agencyIds && !empty($agencyIds)) {
            $qb->where('a.id IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $agencies = $qb->getQuery()->getResult();

        $comparison = [];

        foreach ($agencies as $agency) {
            $agencyData = $this->getAgencyPerformanceData($agency, $startDate, $endDate);

            $comparison[] = [
                'agencyId' => $agency->getId(),
                'agencyName' => $agency->getName(),
                'status' => $agency->getStatus(),
                'kycStatus' => $this->getAgencyKycStatus($agency),
                'totalReservations' => $agencyData['reservations'],
                'totalRevenue' => $agencyData['revenue'],
                'fillRate' => $agencyData['fillRate'],
                'cancellationRate' => $agencyData['cancellationRate'],
                'avgRating' => $agencyData['avgRating'],
            ];
        }

        usort($comparison, function ($a, $b) {
            return $b['totalReservations'] <=> $a['totalReservations'];
        });

        return $comparison;
    }

    /**
     * Get performance data for a specific agency.
     *
     * CORRECTIF : toutes les requêtes filtraient sur `r.agency`, un champ
     * inexistant sur Reservation. On rejoint désormais `r.trip` (aliasé `t`)
     * et on filtre sur `t.agency = :agency`. Le sous-bloc fillRate joignait
     * déjà `t` — seul le filtre était erroné. Ajout de `?? 0` sur les
     * scalaires COUNT/SUM pour éviter un TypeError si aucune ligne ne
     * correspond.
     */
    private function getAgencyPerformanceData(
        Agency $agency,
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): array {
        $reservations = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as count')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->setParameter('agency', $agency)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()->getSingleScalarResult();

        $revenue = $this->reservationRepository->createQueryBuilder('r')
            ->select('SUM(r.totalAmount) as revenue')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus = :status')
            ->setParameter('agency', $agency)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'paye')
            ->getQuery()->getSingleScalarResult();

        $fillRate = $this->reservationRepository->createQueryBuilder('r')
            ->select('AVG(t.seatsReserved) as avgReserved, AVG(b.capacity) as avgCapacity')
            ->join('r.trip', 't')
            ->join('t.bus', 'b')
            ->where('t.agency = :agency')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus = :status')
            ->setParameter('agency', $agency)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'paye')
            ->getQuery()->getResult();

        $fillRateData = $fillRate[0] ?? [];
        $avgReserved = (float) ($fillRateData['avgReserved'] ?? 0);
        $avgCapacity = (float) ($fillRateData['avgCapacity'] ?? 40);
        $fillRatePercentage = $avgCapacity > 0 ? min(100, ($avgReserved / $avgCapacity) * 100) : 0;

        $cancellationData = $this->reservationRepository->createQueryBuilder('r')
            // CORRECTION ICI : Utilisation de SUM avec ELSE 0 pour DQL
            ->select('COUNT(r.id) as total, SUM(CASE WHEN r.paymentStatus IN (:cancelledStatuses) THEN 1 ELSE 0 END) as cancelled')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->setParameter('agency', $agency)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('cancelledStatuses', ['annule', 'echoue'])
            ->getQuery()->getResult();

        $cancellationData = $cancellationData[0] ?? [];
        $total = (int) ($cancellationData['total'] ?? 0);
        $cancelled = (int) ($cancellationData['cancelled'] ?? 0);
        $cancellationRate = $total > 0 ? ($cancelled / $total) * 100 : 0;

        return [
            'reservations' => (int) ($reservations ?? 0),
            'revenue' => (float) ($revenue ?? 0),
            'fillRate' => round($fillRatePercentage, 2),
            'cancellationRate' => round($cancellationRate, 2),
            'avgRating' => 4.5, // Default rating, can be enhanced with actual rating system
        ];
    }

    /**
     * Get agency comparison summary.
     */
    public function getAgencyComparisonSummary(
        DateTimeInterface $startDate = null,
        DateTimeInterface $endDate = null,
        array $agencyIds = null,
        int $limit = 10
    ): array {
        $startDate = $startDate ?? new \DateTime('-30 days');
        $endDate = $endDate ?? new \DateTime('now');

        $comparisonData = $this->getComparisonData($startDate, $endDate, $agencyIds);

        $topByReservations = array_slice($comparisonData, 0, $limit);

        $byRevenue = $comparisonData;
        usort($byRevenue, function ($a, $b) {
            return $b['totalRevenue'] <=> $a['totalRevenue'];
        });
        $topByRevenue = array_slice($byRevenue, 0, $limit);

        $byFillRate = $comparisonData;
        usort($byFillRate, function ($a, $b) {
            return $b['fillRate'] <=> $a['fillRate'];
        });
        $topByFillRate = array_slice($byFillRate, 0, $limit);

        $byCancellation = $comparisonData;
        usort($byCancellation, function ($a, $b) {
            return $a['cancellationRate'] <=> $b['cancellationRate'];
        });
        $topByLowestCancellation = array_slice($byCancellation, 0, $limit);

        return [
            'success' => true,
            'data' => [
                'topByReservations' => $topByReservations,
                'topByRevenue' => $topByRevenue,
                'topByFillRate' => $topByFillRate,
                'topByLowestCancellation' => $topByLowestCancellation,
            ],
            'timestamp' => (new \DateTime())->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Get chart data for line charts.
     */
    public function getChartData(
        string $chartType,
        DateTimeInterface $startDate = null,
        DateTimeInterface $endDate = null,
        array $agencyIds = null,
        string $period = 'monthly'
    ): array {
        $startDate = $startDate ?? new \DateTime('-8 months');
        $endDate = $endDate ?? new \DateTime('now');

        switch ($chartType) {
            case 'users':
                return $this->getUserChartData($startDate, $endDate, $period);
            case 'reservations':
                return $this->getReservationChartData($startDate, $endDate, $agencyIds, $period);
            case 'revenue':
                return $this->getRevenueChartData($startDate, $endDate, $agencyIds, $period);
            default:
                return ['success' => false, 'message' => 'Unknown chart type'];
        }
    }

    /**
     * Get user chart data.
     *
     * CORRECTIF : suppression du filtre `u.agency` (inexistant). Le
     * paramètre agencyIds n'a pas de sens pour une série "nouveaux
     * utilisateurs", qui n'est jamais rattachée à une agence.
     */
    private function getUserChartData(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        string $period = 'monthly'
    ): array {
        $groupBy = $this->getGroupByExpression($period, 'u');

        $results = $this->userRepository->createQueryBuilder('u')
            ->select($groupBy . ' as period, COUNT(u.id) as count')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('period')
            ->orderBy('period', 'ASC')
            ->getQuery()
            ->getResult();

        $labels = [];
        $data = [];

        foreach ($results as $row) {
            $labels[] = $this->formatPeriodLabel($row['period'], $period);
            $data[] = (int) $row['count'];
        }

        return [
            'success' => true,
            'data' => [
                'labels' => $labels,
                'series' => [['name' => 'Nouveaux utilisateurs', 'data' => $data, 'color' => '#2563eb']],
            ],
        ];
    }

    /**
     * Get reservation chart data.
     *
     * CORRECTIF : getGroupByExpression() générait toujours `u.createdAt`,
     * même ici où l'alias de la requête est `r` -> erreur Doctrine "unknown
     * alias u". On passe désormais l'alias explicitement. Filtre agence
     * corrigé également (r.trip -> t.agency).
     */
    private function getReservationChartData(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null,
        string $period = 'monthly'
    ): array {
        $groupBy = $this->getGroupByExpression($period, 'r');

        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->select($groupBy . ' as period, COUNT(r.id) as count')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('period')
            ->orderBy('period', 'ASC');

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('r.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $results = $qb->getQuery()->getResult();

        $labels = [];
        $data = [];

        foreach ($results as $row) {
            $labels[] = $this->formatPeriodLabel($row['period'], $period);
            $data[] = (int) $row['count'];
        }

        return [
            'success' => true,
            'data' => [
                'labels' => $labels,
                'series' => [['name' => 'Réservations', 'data' => $data, 'color' => '#16a34a']],
            ],
        ];
    }

    /**
     * Get revenue chart data.
     *
     * CORRECTIF : même correction d'alias que ci-dessus (pl au lieu de u),
     * et filtre agence via pl.reservation -> res.trip -> t.agency.
     */
    private function getRevenueChartData(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        array $agencyIds = null,
        string $period = 'monthly'
    ): array {
        $groupBy = $this->getGroupByExpression($period, 'pl');

        $qb = $this->paymentLogRepository->createQueryBuilder('pl')
            ->select($groupBy . ' as period, SUM(pl.amount) as amount')
            ->where('pl.createdAt BETWEEN :start AND :end')
            ->andWhere('pl.status = :status')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'SUCCESS')
            ->groupBy('period')
            ->orderBy('period', 'ASC');

        if ($agencyIds && !empty($agencyIds)) {
            $qb->join('pl.reservation', 'res')
                ->join('res.trip', 't')
                ->andWhere('t.agency IN (:agencyIds)')
                ->setParameter('agencyIds', $agencyIds);
        }

        $results = $qb->getQuery()->getResult();

        $labels = [];
        $data = [];

        foreach ($results as $row) {
            $labels[] = $this->formatPeriodLabel($row['period'], $period);
            $data[] = (float) ($row['amount'] ?? 0);
        }

        return [
            'success' => true,
            'data' => [
                'labels' => $labels,
                'series' => [['name' => 'Revenu (FCFA)', 'data' => $data, 'color' => '#8b5cf6']],
            ],
        ];
    }

    /**
     * Get SQL GROUP BY expression based on period type.
     *
     * CORRECTIF : cette méthode ignorait totalement l'alias de la requête
     * appelante et retournait toujours `u.createdAt`. Elle fonctionnait par
     * coïncidence pour getUserChartData (alias `u`) mais cassait
     * getReservationChartData (`r`) et getRevenueChartData (`pl`) avec une
     * erreur DQL "unknown alias". L'alias est maintenant un paramètre
     * explicite.
     */
    private function getGroupByExpression(string $period, string $alias = 'u'): string
    {
        switch ($period) {
            case 'daily':
                return "DATE_FORMAT({$alias}.createdAt, '%Y-%m-%d')";
            case 'weekly':
                return "DATE_FORMAT({$alias}.createdAt, '%Y-%u')";
            case 'monthly':
            default:
                return "DATE_FORMAT({$alias}.createdAt, '%Y-%m')";
        }
    }

    /**
     * Format period label for display.
     */
    private function formatPeriodLabel(string $period, string $periodType): string
    {
        switch ($periodType) {
            case 'daily':
                return (new \DateTime($period))->format('d M');
            case 'weekly':
                $date = new \DateTime($period . '-1'); // Week starts on Monday
                return $date->format('W, Y');
            case 'monthly':
            default:
                $date = new \DateTime($period . '-01');
                return $date->format('M Y');
        }
    }

    /**
     * Get date presets for frontend filtering.
     */
    public function getDatePresets(): array
    {
        $today = new \DateTime();

        return [
            [
                'id' => 'today',
                'label' => 'Aujourd\'hui',
                'startDate' => $today->format('Y-m-d'),
                'endDate' => $today->format('Y-m-d'),
            ],
            [
                'id' => 'yesterday',
                'label' => 'Hier',
                'startDate' => (new \DateTime('yesterday'))->format('Y-m-d'),
                'endDate' => (new \DateTime('yesterday'))->format('Y-m-d'),
            ],
            [
                'id' => 'last7',
                'label' => '7 derniers jours',
                'startDate' => (new \DateTime('-6 days'))->format('Y-m-d'),
                'endDate' => $today->format('Y-m-d'),
            ],
            [
                'id' => 'thisWeek',
                'label' => 'Cette semaine',
                'startDate' => (new \DateTime('this week'))->format('Y-m-d'),
                'endDate' => $today->format('Y-m-d'),
            ],
            [
                'id' => 'lastWeek',
                'label' => 'Semaine dernière',
                'startDate' => (new \DateTime('last week monday'))->format('Y-m-d'),
                'endDate' => (new \DateTime('last week sunday'))->format('Y-m-d'),
            ],
            [
                'id' => 'thisMonth',
                'label' => 'Ce mois',
                'startDate' => (new \DateTime('first day of this month'))->format('Y-m-d'),
                'endDate' => $today->format('Y-m-d'),
            ],
            [
                'id' => 'lastMonth',
                'label' => 'Mois dernier',
                'startDate' => (new \DateTime('first day of last month'))->format('Y-m-d'),
                'endDate' => (new \DateTime('last day of last month'))->format('Y-m-d'),
            ],
            [
                'id' => 'last30',
                'label' => '30 derniers jours',
                'startDate' => (new \DateTime('-29 days'))->format('Y-m-d'),
                'endDate' => $today->format('Y-m-d'),
            ],
            [
                'id' => 'last90',
                'label' => '90 derniers jours',
                'startDate' => (new \DateTime('-89 days'))->format('Y-m-d'),
                'endDate' => $today->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Get all agencies for filtering.
     */
    public function getAgenciesForFilter(): array
    {
        $agencies = $this->agencyRepository->findAll();

        $data = [];
        foreach ($agencies as $agency) {
            $data[] = [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
                'city' => $agency->getAddress() ?? '',
                'status' => $agency->getStatus(),
            ];
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }
}
