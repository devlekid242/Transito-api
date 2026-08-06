<?php

namespace App\Controller\Admin;

use App\Entity\Agency;
use App\Entity\Reservation;
use App\Entity\WithdrawalRequest;
use App\Entity\Wallet;
use App\Entity\PaymentLog;
use App\Entity\WalletTransaction;
use App\Entity\RefundRequest;
use App\Repository\AgencyRepository;
use App\Repository\ReservationRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Repository\WalletRepository;
use App\Repository\PaymentLogRepository;
use App\Repository\WalletTransactionRepository;
use App\Repository\RefundRequestRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Admin Financial Statistics Controller
 * Provides endpoints for revenue analysis and financial statistics with date-range filtering.
 */
#[Route('/api/admin/financial')]
class AdminFinancialController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private AgencyRepository $agencyRepository,
        private ReservationRepository $reservationRepository,
        private WithdrawalRequestRepository $withdrawalRepository,
        private WalletRepository $walletRepository,
        private WalletTransactionRepository $walletTransactionRepository,
        private PaymentLogRepository $paymentLogRepository,
        private RefundRequestRepository $refundRequestRepository,
        private TicketRepository $ticketRepository,
        private UserRepository $userRepository,
    ) {}

    /**
     * Get platform-specific revenue analysis metrics.
     * Scope: Platform application fees, service charges, and platform net earnings.
     */
    #[Route('/revenue-analysis', name: 'api_admin_financial_revenue_analysis', methods: ['GET'])]
    public function getRevenueAnalysis(Request $request): JsonResponse
    {
        // Parse date range from query parameters
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');
        $period = $request->query->get('period', 'monthly'); // daily, weekly, monthly

        // Get platform revenue metrics (fees, commissions, etc.)
        $platformRevenue = $this->walletTransactionRepository->getPlatformRevenue($startDate, $endDate);
        $platformFees = $this->getPlatformFeesBreakdown($startDate, $endDate);

        // return $this->json([$platformRevenue]);

        // Get platform net earnings
        $platformNetEarnings = $this->getPlatformNetEarnings($startDate, $endDate);

        // Get revenue distribution by agency
        $revenueByAgency = $this->getRevenueByAgency($startDate, $endDate);

        // Get revenue distribution by route
        $revenueByRoute = $this->getRevenueByRoute($startDate, $endDate);

        // Get payment method distribution for platform revenue
        $paymentDistribution = $this->getPlatformPaymentDistribution($startDate, $endDate);

        // Get revenue time series data
        $revenueChartData = $this->getRevenueTimeSeries($startDate, $endDate, $period);

        // Get refunds trend for platform
        $refundsTrend = $this->getPlatformRefundsTrend($startDate, $endDate, $period);

        // Calculate growth metrics
        $previousStart = clone $startDate;
        $previousEnd = clone $endDate;

        $unit = match ($period) {
            'daily' => 'day',
            'weekly' => 'week',
            'monthly' => 'month',
            default => 'month',
        };

        $previousStart->modify('-1 ' . $unit);
        $previousEnd->modify('-1 ' . $unit);

        $previousRevenue = $this->walletTransactionRepository->getPlatformRevenue($previousStart, $previousEnd);
        $revenueGrowthRate = $this->calculateGrowthRate((float) $previousRevenue, (float) $platformRevenue);

        return $this->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'platformRevenue' => (float) $platformRevenue,
                    'platformFees' => $platformFees,
                    'netEarnings' => (float) $platformNetEarnings,
                    'revenueGrowthRate' => $revenueGrowthRate,
                ],
                'revenueByAgency' => $revenueByAgency,
                'revenueByRoute' => $revenueByRoute,
                'paymentDistribution' => $paymentDistribution,
                'chartData' => [
                    'labels' => $revenueChartData['labels'],
                    'caSeries' => $revenueChartData['revenueSeries'],
                    'beneficeSeries' => $revenueChartData['beneficeSeries'],
                    'commissionsSeries' => $revenueChartData['commissionsSeries'],
                ],
                'refundsTrend' => [
                    'labels' => $refundsTrend['labels'],
                    'series' => $refundsTrend['series'],
                ],
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get comprehensive reports data for the admin reports page.
     */
    #[Route('/reports/comprehensive', name: 'api_admin_financial_reports_comprehensive', methods: ['GET'])]
    public function getComprehensiveReports(Request $request): JsonResponse
    {
        $startDate = $request->query->get('startDate') ? new \DateTime($request->query->get('startDate')) : new \DateTime('today');
        $endDate = $request->query->get('endDate') ? new \DateTime($request->query->get('endDate')) : new \DateTime('now');

        if ($endDate < $startDate) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }

        $platformRevenue = $this->walletTransactionRepository->getPlatformRevenue($startDate, $endDate);
        $platformFees = $this->getPlatformFeesBreakdown($startDate, $endDate);
        $platformNetEarnings = $this->getPlatformNetEarnings($startDate, $endDate);
        $paymentDistribution = $this->getPlatformPaymentDistribution($startDate, $endDate);
        $revenueChartData = $this->getRevenueTimeSeries($startDate, $endDate, 'daily');
        $refundsTrend = $this->getPlatformRefundsTrend($startDate, $endDate, 'daily');

        $financialSummary = [
            'totalBalance' => (float) $this->walletRepository->getTotalBalance(),
            'availableBalance' => (float) $this->walletRepository->getTotalAvailableBalance(),
            'reservedBalance' => (float) $this->walletRepository->getTotalReservedBalance(),
            'pendingWithdrawals' => (float) $this->withdrawalRepository->getPendingWithdrawalsAmount(),
            'pendingRefunds' => (float) $this->paymentLogRepository->getPendingRefundsAmount(),
            'commissionEarnings' => $platformFees['platformFees'] ?? 0.0,
        ];

        $agencyMetrics = $this->getRevenueByAgency($startDate, $endDate);
        $agencyPerformance = [];
        foreach ($agencyMetrics as $agencyRow) {
            $agencyPerformance[] = [
                'agencyId' => '',
                'agencyName' => $agencyRow['agency'] ?? '',
                'revenue' => (float) ($agencyRow['revenue'] ?? 0),
                'reservations' => 0,
                'fillRate' => 0.0,
                'cancellationRate' => 0.0,
                'rating' => 4.5,
                'status' => 'ACTIVE',
            ];
        }

        $routes = $this->reservationRepository->findTopRoutes(5);
        $routePerformance = [];
        foreach ($routes as $routeRow) {
            $bookings = (int) ($routeRow['reservationCount'] ?? 0);
            $totalAmount = (float) ($routeRow['totalAmount'] ?? 0);
            $routePerformance[] = [
                'route' => $routeRow['route'] ?? '',
                'revenue' => $totalAmount,
                'bookings' => $bookings,
                'fillRate' => 0.0,
                'averagePrice' => $bookings > 0 ? round($totalAmount / $bookings, 2) : 0.0,
            ];
        }

        $revenueByPeriod = [];
        foreach ($revenueChartData['labels'] as $index => $label) {
            $revenueByPeriod[] = [
                'period' => $label,
                'revenue' => (float) ($revenueChartData['revenueSeries'][$index] ?? 0),
                'reservations' => 0,
                'growthRate' => 0.0,
            ];
        }

        $userActivity = [];
        $newUsersByPeriod = $this->userRepository->getNewUsersByPeriod($startDate, $endDate, 'daily');
        $reservationsByDay = $this->reservationRepository->getReservationsByDay();
        $reservationsByDayIndex = [];
        foreach ($reservationsByDay as $row) {
            $reservationsByDayIndex[$row['date']] = (int) ($row['count'] ?? 0);
        }

        foreach ($newUsersByPeriod as $row) {
            $date = is_string($row['period']) ? $row['period'] : $row['period']->format('Y-m-d');
            $userActivity[] = [
                'date' => $date,
                'newUsers' => (int) ($row['count'] ?? 0),
                'activeUsers' => (int) ($row['count'] ?? 0),
                'reservations' => $reservationsByDayIndex[$date] ?? 0,
                'totalRevenue' => 0,
            ];
        }

        $reservationStatusDistribution = $this->buildReservationStatusDistribution($startDate, $endDate);

        return $this->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'totalRevenue' => (float) $platformRevenue,
                    'grossTurnover' => 0.0,
                    'netRevenue' => (float) $platformNetEarnings,
                    'platformFees' => $platformFees['platformFees'] ?? 0.0,
                    'totalReservations' => array_sum(array_column($routePerformance, 'bookings')),
                    'totalTrips' => 0,
                    'totalUsers' => $this->userRepository->count([]),
                    'activeAgencies' => count($agencyMetrics),
                    'totalAgencies' => $this->agencyRepository->count([]),
                    'fillRate' => 0.0,
                    'cancellationRate' => 0.0,
                    'completionRate' => 0.0,
                    'totalTransactions' => 0,
                    'totalWithdrawals' => (float) $this->withdrawalRepository->getPendingWithdrawalsAmount(),
                    'totalRefunds' => (float) $this->paymentLogRepository->getPendingRefundsAmount(),
                    'revenueGrowthRate' => 0.0,
                    'userGrowthRate' => 0.0,
                    'reservationGrowthRate' => 0.0,
                ],
                'financialSummary' => $financialSummary,
                'revenueByPeriod' => $revenueByPeriod,
                'userActivity' => $userActivity,
                'agencyPerformance' => $agencyPerformance,
                'routePerformance' => $routePerformance,
                'paymentDistribution' => array_map(fn($row) => [
                    'method' => $row['label'] ?? '',
                    'amount' => (float) ($row['value'] ?? 0),
                    'count' => (int) ($row['value'] ?? 0),
                    'percentage' => (float) ($row['percentage'] ?? 0),
                ], $paymentDistribution),
                'reservationStatusDistribution' => array_map(fn($row) => [
                    'status' => $row['status'] ?? '',
                    'count' => (int) ($row['count'] ?? 0),
                    'percentage' => (float) ($row['percentage'] ?? 0),
                    'amount' => (float) ($row['amount'] ?? 0),
                ], $reservationStatusDistribution),
                'revenueChartData' => [
                    'labels' => $revenueChartData['labels'],
                    'datasets' => [[
                        'label' => 'Revenu total',
                        'data' => array_map('floatval', $revenueChartData['revenueSeries']),
                        'color' => '#2563eb',
                        'fill' => 'rgba(37, 99, 235, 0.1)',
                        'type' => 'line',
                    ]],
                ],
                'reservationsChartData' => [
                    'labels' => array_column($reservationsByDay, 'date'),
                    'datasets' => [[
                        'label' => 'Réservations',
                        'data' => array_map(fn($row) => (int) ($row['count'] ?? 0), $reservationsByDay),
                        'color' => '#2563eb',
                        'type' => 'bar',
                    ]],
                ],
                'userGrowthChartData' => [
                    'labels' => array_map(fn($row) => is_string($row['period']) ? $row['period'] : $row['period']->format('Y-m-d'), $newUsersByPeriod),
                    'datasets' => [[
                        'label' => 'Nouveaux utilisateurs',
                        'data' => array_map(fn($row) => (int) ($row['count'] ?? 0), $newUsersByPeriod),
                        'color' => '#16a34a',
                        'fill' => 'rgba(22, 163, 74, 0.1)',
                        'type' => 'line',
                    ]],
                ],
                'agencyPerformanceChartData' => [
                    'labels' => array_column($agencyPerformance, 'agencyName'),
                    'datasets' => [[
                        'label' => 'Revenu par agence',
                        'data' => array_map(fn($row) => (float) ($row['revenue'] ?? 0), $agencyPerformance),
                        'color' => '#2563eb',
                        'type' => 'bar',
                    ]],
                ],
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get reservation status distribution for the report.
     */
    private function buildReservationStatusDistribution(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $results = $this->reservationRepository->createQueryBuilder('r')
            ->select('r.paymentStatus as status, COUNT(r.id) as count, SUM(r.totalAmount) as amount')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('r.paymentStatus')
            ->getQuery()
            ->getResult();

        $distribution = [];
        foreach ($results as $row) {
            $distribution[] = [
                'status' => $row['status'] ?? 'UNKNOWN',
                'count' => (int) ($row['count'] ?? 0),
                'percentage' => 0.0,
                'amount' => (float) ($row['amount'] ?? 0),
            ];
        }

        $total = array_sum(array_map(static fn($item) => $item['count'], $distribution));
        if ($total > 0) {
            foreach ($distribution as &$item) {
                $item['percentage'] = round(($item['count'] / $total) * 100, 2);
            }
            unset($item);
        }

        return $distribution;
    }

    /**
     * Transaction history across all types (payments, refunds, withdrawals, commissions, etc.)
     * with filtering, pagination, and full lifecycle status (pending -> completed/rejected).
     *
     * 👈 RÉÉCRIT (audit "doublons") : l'ancienne implémentation construisait
     * UNE seule requête DQL qui LEFT JOIN-ait WalletTransaction avec
     * PaymentLog et RefundRequest sur `reservation_id` uniquement. Or une
     * même réservation a plusieurs PaymentLog (initiate() -> PENDING,
     * confirm() -> SUCCESS, cancel() -> REFUND_PENDING...) et peut avoir
     * plusieurs RefundRequest : chaque ligne WalletTransaction se
     * retrouvait donc dupliquée par le produit cartésien de ces deux
     * jointures "un-vers-plusieurs" (fan-out SQL classique). C'est la cause
     * directe des doublons observés dans la réponse JSON — et la raison
     * pour laquelle COUNT(DISTINCT wt.id) (correct) ne correspondait déjà
     * plus au nombre de lignes réellement renvoyées par la page (incorrect).
     *
     * Second bug corrigé au passage : le statut affiché était calculé via
     *   strtoupper($row['type']) ?? strtoupper($row['payment_status']) ?? ...
     * Or `wt.type` (CREDIT/DEBIT) n'est jamais null, donc l'opérateur `??`
     * s'arrêtait immédiatement dessus : TOUTES les lignes ressortaient
     * "SUCCESS", quel que soit le vrai statut (remboursement en attente,
     * retrait rejeté...).
     *
     * Nouvelle approche : chaque catégorie de mouvement est lue depuis SA
     * table source de vérité pour le cycle de vie (PaymentLog, RefundRequest,
     * WithdrawalRequest — qui ont chacune un vrai statut pending/approved/
     * rejected/completed), enrichie ponctuellement par une jointure
     * *garantie* 1-vers-1 vers le grand livre (WalletService est idempotent
     * par réservation/retrait, il ne peut jamais exister plus d'une
     * WalletTransaction SOURCE_REFUND ou SOURCE_RESERVATION_PAYMENT pour une
     * même réservation). Les écritures qui n'ont pas de cycle de vie propre
     * (commission plateforme, ajustements admin, gel/dégel) sont lues
     * directement depuis WalletTransaction, sans risque de fan-out puisque
     * seules des relations *-vers-1 y sont jointes.
     *
     * Les 4 listes obtenues sont fusionnées, triées, puis paginées en
     * mémoire — aucune ligne ne peut plus apparaître deux fois. Pour de très
     * gros volumes, cette pagination en mémoire devra être remplacée par une
     * requête SQL UNION native ; pour un tableau de bord admin filtré sur
     * une plage de dates, elle reste largement suffisante.
     */
    #[Route('/transactions', name: 'api_admin_financial_transactions', methods: ['GET'])]
    public function getTransactionHistory(Request $request): JsonResponse
    {
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');
        $transactionType = $request->query->get('type'); // PAYMENT, WITHDRAWAL, REFUND, COMMISSION, TOPUP, ADJUSTMENT, SYSTEM
        $status = $request->query->get('status'); // PENDING, SUCCESS, REJECTED, FAILED
        $agencyId = $request->query->get('agency_id');
        $search = $request->query->get('search');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, (int) $request->query->get('per_page', 50));

        $all = [];

        if (!$transactionType || $transactionType === 'PAYMENT') {
            $all = array_merge($all, $this->buildPaymentTransactions($startDate, $endDate, $agencyId, $search));
        }
        if (!$transactionType || $transactionType === 'REFUND') {
            $all = array_merge($all, $this->buildRefundTransactions($startDate, $endDate, $agencyId, $search));
        }
        if (!$transactionType || $transactionType === 'WITHDRAWAL') {
            $all = array_merge($all, $this->buildWithdrawalTransactions($startDate, $endDate, $agencyId, $search));
        }
        if (!$transactionType || in_array($transactionType, ['COMMISSION', 'TOPUP', 'ADJUSTMENT', 'SYSTEM'], true)) {
            $all = array_merge($all, $this->buildLedgerOnlyTransactions($startDate, $endDate, $agencyId, $search, $transactionType));
        }

        // Filtre par statut standardisé : appliqué après coup, une fois le
        // vrai statut métier calculé pour chaque catégorie (impossible à
        // pousser proprement en SQL puisque chaque catégorie a sa propre
        // colonne/entité de statut).
        if ($status) {
            $status = strtoupper($status);
            $all = array_values(array_filter($all, static fn(array $tx) => $tx['status'] === $status));
        }

        // Tri par date d'initiation décroissante (la plus récente en premier)
        usort($all, static fn(array $a, array $b) => strcmp((string) $b['initiatedAt'], (string) $a['initiatedAt']));

        $total = count($all);
        $offset = ($page - 1) * $perPage;
        $transactions = array_slice($all, $offset, $perPage);

        $stats = $this->calculateTransactionStats($all);

        return $this->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'stats' => $stats,
            ],
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Ligne de type PAYMENT : source de vérité = PaymentLog (cycle de vie
     * PENDING -> SUCCESS/FAILED/REFUNDED...). Enrichie, quand elle existe,
     * par la WalletTransaction SOURCE_RESERVATION_PAYMENT correspondante
     * (jointure 1-vers-1 garantie par l'idempotence de
     * WalletService::creditForReservationPayment()).
     */
    private function buildPaymentTransactions(\DateTimeInterface $start, \DateTimeInterface $end, ?string $agencyId, ?string $search): array
    {
        $qb = $this->paymentLogRepository->createQueryBuilder('pl')
            ->addSelect('r', 't', 'a')
            ->leftJoin('pl.reservation', 'r')
            ->leftJoin('r.trip', 't')
            ->leftJoin('t.agency', 'a')
            ->where('pl.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('pl.createdAt', 'DESC');

        if ($agencyId) {
            $qb->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }
        if ($search) {
            $qb->andWhere('pl.reference LIKE :search OR a.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        /** @var PaymentLog[] $logs */
        $logs = $qb->getQuery()->getResult();

        $out = [];
        foreach ($logs as $log) {
            $reservation = $log->getReservation();
            $agency = $reservation?->getTrip()?->getAgency();

            $creditTx = $reservation ? $this->em->getRepository(WalletTransaction::class)->findOneBy([
                'reservation' => $reservation,
                'source' => WalletTransaction::SOURCE_RESERVATION_PAYMENT,
            ]) : null;

            [$status, $validatedAt] = $this->resolvePaymentLogStatus($log);

            $out[] = [
                'id' => 'PAY-' . $log->getId(),
                'source' => WalletTransaction::SOURCE_RESERVATION_PAYMENT,
                'type' => 'PAYMENT',
                'amount' => (float) $log->getAmount(),
                'feeAmount' => $creditTx ? (float) $creditTx->getFeeAmount() : 0.0,
                'balanceAfter' => $creditTx ? (float) $creditTx->getBalanceAfter() : null,
                'description' => sprintf('Paiement réservation #%d via %s', $reservation?->getId() ?? 0, $log->getOperator()),
                'initiatedAt' => $log->getCreatedAt()?->format('c'),
                'validatedAt' => $validatedAt,
                'status' => $status,
                'agency' => ['id' => $agency?->getId(), 'name' => $agency?->getName() ?? 'Inconnue'],
                'wallet' => ['id' => $creditTx?->getWallet()?->getId()],
                'reservation' => [
                    'id' => $reservation?->getId(),
                    'ref' => $reservation?->getTransactionReference(),
                    'amount' => $reservation ? (float) $reservation->getTotalAmount() : null,
                ],
                'payment' => [
                    'reference' => $log->getReference(),
                    'operator' => $log->getOperator(),
                    'status' => $log->getStatus(),
                ],
            ];
        }

        return $out;
    }

    /**
     * Traduit le statut brut d'un PaymentLog (qui a beaucoup de valeurs
     * possibles : PENDING, SUCCESS, FAILED, REFUND_PENDING, REFUNDED,
     * REFUNDED_COMPLETED, REFUNDED_FORCE) en statut standardisé pour l'API,
     * et retourne la date de validation (processedAt) associée.
     *
     * @return array{0: string, 1: ?string}
     */
    private function resolvePaymentLogStatus(PaymentLog $log): array
    {
        $processedAt = $log->getProcessedAt()?->format('c');

        return match ($log->getStatus()) {
            'PENDING' => ['PENDING', null],
            'FAILED' => ['FAILED', $processedAt],
            // Un paiement remboursé reste, en tant que LIGNE DE PAIEMENT,
            // un encaissement réussi (l'argent a bien été reçu) : le
            // remboursement associé apparaît séparément comme sa propre
            // ligne de type REFUND (voir buildRefundTransactions()).
            'SUCCESS', 'REFUND_PENDING', 'REFUNDED', 'REFUNDED_COMPLETED', 'REFUNDED_FORCE' => ['SUCCESS', $processedAt],
            default => ['PENDING', null],
        };
    }

    /**
     * Ligne de type REFUND : source de vérité = RefundRequest (cycle de vie
     * pending -> approved/completed/rejected, déjà correctement modélisé et
     * horodaté via createdAt/processedAt). Enrichie, quand elle existe, par
     * la WalletTransaction SOURCE_REFUND correspondante (jointure 1-vers-1
     * garantie par l'idempotence PAR RÉSERVATION de
     * WalletService::debitForRefund()).
     */
    private function buildRefundTransactions(\DateTimeInterface $start, \DateTimeInterface $end, ?string $agencyId, ?string $search): array
    {
        $qb = $this->refundRequestRepository->createQueryBuilder('rr')
            ->addSelect('a', 'r')
            ->join('rr.agency', 'a')
            ->leftJoin('rr.reservation', 'r')
            ->where('rr.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('rr.createdAt', 'DESC');

        if ($agencyId) {
            $qb->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }
        if ($search) {
            $qb->andWhere('a.name LIKE :search OR r.transactionReference LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        /** @var RefundRequest[] $requests */
        $requests = $qb->getQuery()->getResult();

        $out = [];
        foreach ($requests as $rr) {
            $agency = $rr->getAgency();
            $reservation = $rr->getReservation();

            $debitTx = $reservation ? $this->em->getRepository(WalletTransaction::class)->findOneBy([
                'reservation' => $reservation,
                'source' => WalletTransaction::SOURCE_REFUND,
            ]) : null;

            $status = match ($rr->getStatus()) {
                RefundRequest::STATUS_PENDING => 'PENDING',
                RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_COMPLETED => 'SUCCESS',
                RefundRequest::STATUS_REJECTED => 'REJECTED',
                default => 'PENDING',
            };

            $amount = $rr->getStatus() === RefundRequest::STATUS_COMPLETED
                ? (float) $rr->getRefundedAmount()
                : (float) $rr->getRequestedAmount();

            $out[] = [
                'id' => 'REF-' . $rr->getId(),
                'source' => WalletTransaction::SOURCE_REFUND,
                'type' => 'REFUND',
                'amount' => $amount,
                'feeAmount' => 0.0,
                'balanceAfter' => $debitTx ? (float) $debitTx->getBalanceAfter() : null,
                'description' => sprintf('Remboursement réservation #%d (%s)', $reservation?->getId() ?? 0, $rr->getReason()),
                'initiatedAt' => $rr->getCreatedAt()?->format('c'),
                'validatedAt' => $rr->getProcessedAt()?->format('c'),
                'status' => $status,
                'agency' => ['id' => $agency?->getId(), 'name' => $agency?->getName() ?? 'Inconnue'],
                'wallet' => ['id' => $debitTx?->getWallet()?->getId()],
                'refund' => [
                    'id' => $rr->getId(),
                    'requestedAmount' => (float) $rr->getRequestedAmount(),
                    'refundedAmount' => (float) $rr->getRefundedAmount(),
                    'status' => $rr->getStatus(),
                    'processedByAdmin' => $rr->getProcessedByAdmin()?->getFullName(),
                ],
            ];
        }

        return $out;
    }

    /**
     * Ligne de type WITHDRAWAL : source de vérité = WithdrawalRequest (cycle
     * de vie pending -> approved/rejected, déjà correctement modélisé et
     * horodaté via createdAt/processedAt). Pas besoin de jointure vers
     * WalletTransaction ici : le montant et le statut viennent directement
     * de l'entité, qui est elle-même la référence.
     */
    private function buildWithdrawalTransactions(\DateTimeInterface $start, \DateTimeInterface $end, ?string $agencyId, ?string $search): array
    {
        $qb = $this->withdrawalRepository->createQueryBuilder('wr')
            ->addSelect('a', 'u')
            ->join('wr.agency', 'a')
            ->leftJoin('wr.requestedBy', 'u')
            ->where('wr.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('wr.createdAt', 'DESC');

        if ($agencyId) {
            $qb->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }
        if ($search) {
            $qb->andWhere('a.name LIKE :search')->setParameter('search', '%' . $search . '%');
        }

        /** @var WithdrawalRequest[] $withdrawals */
        $withdrawals = $qb->getQuery()->getResult();

        $out = [];
        foreach ($withdrawals as $wr) {
            $agency = $wr->getAgency();

            $status = match ($wr->getStatus()) {
                'pending' => 'PENDING',
                'approved' => 'SUCCESS',
                'rejected' => 'REJECTED',
                default => 'PENDING',
            };

            $source = match ($wr->getStatus()) {
                'approved' => WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED,
                'rejected' => WalletTransaction::SOURCE_WITHDRAWAL_RELEASED,
                default => WalletTransaction::SOURCE_WITHDRAWAL_HOLD,
            };

            $out[] = [
                'id' => 'WD-' . $wr->getId(),
                'source' => $source,
                'type' => 'WITHDRAWAL',
                'amount' => (float) $wr->getAmount(),
                'feeAmount' => 0.0,
                'balanceAfter' => null,
                'description' => sprintf('Retrait %s — %s', $wr->getMethod(), $agency?->getName() ?? 'Inconnue'),
                'initiatedAt' => $wr->getCreatedAt()?->format('c'),
                'validatedAt' => $wr->getProcessedAt()?->format('c'),
                'status' => $status,
                'agency' => ['id' => $agency?->getId(), 'name' => $agency?->getName() ?? 'Inconnue'],
                'wallet' => ['id' => null],
                'withdrawal' => [
                    'id' => $wr->getId(),
                    'amount' => (float) $wr->getAmount(),
                    'method' => $wr->getMethod(),
                    'status' => $wr->getStatus(),
                    'forcePaid' => $wr->isForcePaid(),
                    'processedByAdmin' => $wr->getProcessedByAdmin()?->getFullName(),
                ],
            ];
        }

        return $out;
    }

    /**
     * Lignes sans cycle de vie propre (commission plateforme, ajustements
     * admin, gel/dégel de portefeuille...) : lues directement depuis
     * WalletTransaction, la table étant elle-même la source de vérité. Pas
     * de risque de fan-out ici puisque seules des relations *-vers-1 sont
     * jointes (wallet, agency, admin).
     */
    private function buildLedgerOnlyTransactions(\DateTimeInterface $start, \DateTimeInterface $end, ?string $agencyId, ?string $search, ?string $transactionType): array
    {
        $ledgerSources = [
            WalletTransaction::SOURCE_PLATFORM_FEE,
            WalletTransaction::SOURCE_ADMIN_CREDIT,
            WalletTransaction::SOURCE_ADMIN_DEBIT,
            WalletTransaction::SOURCE_ADJUSTMENT,
            WalletTransaction::SOURCE_WALLET_FREEZE,
            WalletTransaction::SOURCE_WALLET_UNFREEZE,
        ];

        if ($transactionType) {
            $ledgerSources = array_values(array_filter(
                $ledgerSources,
                fn(string $source) => $this->mapSourceToType($source) === $transactionType
            ));
            if (!$ledgerSources) {
                return [];
            }
        }

        $qb = $this->walletTransactionRepository->createQueryBuilder('wt')
            ->addSelect('w', 'a', 'u')
            ->leftJoin('wt.wallet', 'w')
            ->leftJoin('w.agency', 'a')
            ->leftJoin('wt.admin', 'u')
            ->where('wt.source IN (:sources)')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('sources', $ledgerSources)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('wt.createdAt', 'DESC');

        if ($agencyId) {
            $qb->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }
        if ($search) {
            $qb->andWhere('wt.description LIKE :search OR a.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        /** @var WalletTransaction[] $rows */
        $rows = $qb->getQuery()->getResult();

        $out = [];
        foreach ($rows as $wt) {
            $wallet = $wt->getWallet();
            $agency = $wallet?->getAgency();
            $admin = $wt->getAdmin();

            $out[] = [
                'id' => 'TX-' . $wt->getId(),
                'source' => $wt->getSource(),
                'type' => $this->mapSourceToType($wt->getSource()),
                'amount' => (float) $wt->getAmount(),
                'feeAmount' => (float) $wt->getFeeAmount(),
                'balanceAfter' => (float) $wt->getBalanceAfter(),
                'description' => $wt->getDescription(),
                'initiatedAt' => $wt->getCreatedAt()?->format('c'),
                // Écritures instantanées par nature : pas de workflow
                // pending -> validé distinct, la date de validation est
                // donc la date d'écriture au grand livre elle-même.
                'validatedAt' => $wt->getCreatedAt()?->format('c'),
                'status' => 'SUCCESS',
                'agency' => [
                    'id' => $agency?->getId(),
                    'name' => $agency?->getName() ?? ($wallet?->isPlatform() ? 'Plateforme' : 'Inconnue'),
                ],
                'wallet' => ['id' => $wallet?->getId()],
                'admin' => $admin ? [
                    'id' => $admin->getId(),
                    'name' => $admin->getFullName(),
                    'email' => $admin->getEmail(),
                ] : null,
            ];
        }

        return $out;
    }

    /**
     * Map wallet transaction source to user-friendly transaction type
     */
    private function mapSourceToType(string $source): string
    {
        $mapping = [
            WalletTransaction::SOURCE_RESERVATION_PAYMENT => 'PAYMENT',
            WalletTransaction::SOURCE_PLATFORM_FEE => 'COMMISSION',
            WalletTransaction::SOURCE_REFUND => 'REFUND',
            WalletTransaction::SOURCE_WITHDRAWAL_HOLD => 'WITHDRAWAL',
            WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED => 'WITHDRAWAL',
            WalletTransaction::SOURCE_WITHDRAWAL_RELEASED => 'WITHDRAWAL',
            WalletTransaction::SOURCE_ADMIN_CREDIT => 'TOPUP',
            WalletTransaction::SOURCE_ADMIN_DEBIT => 'ADJUSTMENT',
            WalletTransaction::SOURCE_ADJUSTMENT => 'ADJUSTMENT',
            WalletTransaction::SOURCE_WALLET_FREEZE => 'SYSTEM',
            WalletTransaction::SOURCE_WALLET_UNFREEZE => 'SYSTEM',
        ];

        return $mapping[$source] ?? 'UNKNOWN';
    }

    /**
     * Calculate transaction statistics.
     * 👈 Calculées sur l'ensemble filtré ($all, avant pagination) et non
     * plus seulement sur la page courante — sinon les totaux changeaient
     * de manière incohérente selon la page affichée.
     */
    private function calculateTransactionStats(array $transactions): array
    {
        $stats = [
            'totalCount' => count($transactions),
            'totalVolume' => 0,
            'totalFees' => 0,
            'countByType' => [],
            'volumeByType' => [],
            'countByStatus' => [],
        ];

        foreach ($transactions as $tx) {
            $stats['totalVolume'] += abs($tx['amount']);
            $stats['totalFees'] += abs($tx['feeAmount']);

            if (!isset($stats['countByType'][$tx['type']])) {
                $stats['countByType'][$tx['type']] = 0;
                $stats['volumeByType'][$tx['type']] = 0;
            }
            $stats['countByType'][$tx['type']]++;
            $stats['volumeByType'][$tx['type']] += abs($tx['amount']);

            if (!isset($stats['countByStatus'][$tx['status']])) {
                $stats['countByStatus'][$tx['status']] = 0;
            }
            $stats['countByStatus'][$tx['status']]++;
        }

        return $stats;
    }

    /**
     * Get general financial statistics across the entire platform ecosystem.
     * Scope: GMV, total agency wallet balances, payouts/withdrawals, customer refunds, transaction volume.
     */
    #[Route('/stats', name: 'api_admin_financial_stats', methods: ['GET'])]
    public function getFinancialStats(Request $request): JsonResponse
    {
        // Parse date range from query parameters
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');
        $period = $request->query->get('period', 'monthly'); // daily, weekly, monthly

        // Gross Merchandise Value (GMV) - total transaction volume
        $gmv = $this->getGMV($startDate, $endDate);

        // Total agency wallet balances
        $totalWalletBalances = $this->getTotalWalletBalances();

        // Payouts/Withdrawals metrics
        $withdrawalMetrics = $this->getWithdrawalMetrics($startDate, $endDate);

        // Customer refunds metrics
        $refundMetrics = $this->getRefundMetrics($startDate, $endDate);

        // Overall transaction volume
        $transactionVolume = $this->getTransactionVolume($startDate, $endDate);

        // Platform balance
        $platformBalance = $this->getPlatformBalance();

        // Revenue by agency
        $revenueByAgency = $this->getRevenueByAgency($startDate, $endDate);

        // Chart data for GMV evolution
        $gmvChartData = $this->getGMVTimeSeries($startDate, $endDate, $period);

        // Chart data for benefit vs commissions
        $benefitVsCommissions = $this->getBenefitVsCommissionsTimeSeries($startDate, $endDate, $period);

        // Financial detail by agency
        $financialDetailByAgency = $this->getFinancialDetailByAgency($startDate, $endDate);

        // Calculate growth metrics
        $previousStart = clone $startDate;
        $previousEnd = clone $endDate;

        $unit = match ($period) {
            'daily' => 'day',
            'weekly' => 'week',
            'monthly' => 'month',
            default => 'month',
        };

        $previousStart->modify('-1 ' . $unit);
        $previousEnd->modify('-1 ' . $unit);

        $previousGMV = $this->getGMV($previousStart, $previousEnd);
        $gmvGrowthRate = $this->calculateGrowthRate((float) $previousGMV, (float) $gmv);

        return $this->json([
            'success' => true,
            'data' => [
                'kpis' => [
                    'grossMerchandiseValue' => (float) $gmv,
                    'totalWalletBalance' => (float) $totalWalletBalances['total'],
                    'availableBalance' => (float) $totalWalletBalances['available'],
                    'reservedBalance' => (float) $totalWalletBalances['reserved'],
                    'platformBalance' => (float) $platformBalance,
                    'totalWithdrawals' => (float) $withdrawalMetrics['totalAmount'],
                    'pendingWithdrawals' => (float) $withdrawalMetrics['pendingAmount'],
                    'totalRefunds' => (float) $refundMetrics['totalAmount'],
                    'pendingRefunds' => (float) $refundMetrics['pendingAmount'],
                    'transactionVolume' => $transactionVolume,
                    'gmvGrowthRate' => $gmvGrowthRate,
                    'netMargin' => $this->calculateNetMargin((float) $gmv, (float) $platformBalance),
                    'averageBasket' => $this->calculateAverageBasket((float) $gmv, $transactionVolume),
                ],
                'revenueByAgency' => $revenueByAgency,
                'chartData' => [
                    'labels' => $gmvChartData['labels'],
                    'caSeries' => $gmvChartData['series'],
                    'beneficeSeries' => $benefitVsCommissions['beneficeSeries'],
                    'commissionsSeries' => $benefitVsCommissions['commissionsSeries'],
                ],
                'financialDetailByAgency' => $financialDetailByAgency,
                'walletBalances' => [
                    'total' => $totalWalletBalances['total'],
                    'available' => $totalWalletBalances['available'],
                    'reserved' => $totalWalletBalances['reserved'],
                ],
                'withdrawals' => $withdrawalMetrics,
                'refunds' => $refundMetrics,
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get platform fees breakdown.
     */
    private function getPlatformFeesBreakdown(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $query = $this->walletTransactionRepository->createQueryBuilder('wt')
            ->select('wt.source, SUM(wt.amount) as total')
            ->where('wt.source IN (:sources)')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('sources', [
                WalletTransaction::SOURCE_PLATFORM_FEE,
                WalletTransaction::SOURCE_ADMIN_CREDIT,
                WalletTransaction::SOURCE_ADMIN_DEBIT,
            ])
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('wt.source');

        $results = $query->getQuery()->getResult();

        $fees = [
            'platformFees' => 0.0,
            'adminCredits' => 0.0,
            'adminDebits' => 0.0,
        ];

        foreach ($results as $row) {
            switch ($row['source']) {
                case WalletTransaction::SOURCE_PLATFORM_FEE:
                    $fees['platformFees'] += (float) $row['total'];
                    break;
                case WalletTransaction::SOURCE_ADMIN_CREDIT:
                    $fees['adminCredits'] += (float) $row['total'];
                    break;
                case WalletTransaction::SOURCE_ADMIN_DEBIT:
                    $fees['adminDebits'] += (float) $row['total'];
                    break;
            }
        }

        return $fees;
    }

    /**
     * Get platform net earnings (revenue minus costs).
     */
    private function getPlatformNetEarnings(\DateTimeInterface $startDate, \DateTimeInterface $endDate): string
    {
        $revenue = $this->walletTransactionRepository->getPlatformRevenue($startDate, $endDate);
        // $refunds = $this->paymentLogRepository->getPendingRefundsAmount($startDate, $endDate);
        $refunds = $this->paymentLogRepository->getPendingRefundsAmount();

        // Calculate net earnings (revenue minus refunds and other costs)
        $netEarnings = (float) $revenue - (float) $refunds;

        return number_format($netEarnings, 2, '.', '');
    }

    /**
     * Get revenue distribution by agency.
     */
    private function getRevenueByAgency(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $query = $this->walletTransactionRepository->createQueryBuilder('wt')
            ->select('a.name as agency, SUM(wt.amount) as revenue')
            ->join('wt.wallet', 'w')
            ->join('w.agency', 'a')
            ->where('wt.source = :source')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('a.name')
            ->orderBy('revenue', 'DESC');

        $results = $query->getQuery()->getResult();

        $revenueByAgency = [];
        $colors = ['#16a34a', '#0891b2', '#f59e0b', '#dc2626', '#8b5cf6'];

        foreach ($results as $index => $row) {
            $revenueByAgency[] = [
                'agency' => $row['agency'],
                'revenue' => (float) $row['revenue'],
                'color' => $colors[$index % count($colors)],
            ];
        }

        return $revenueByAgency;
    }

    /**
     * Get revenue distribution by route.
     */
    private function getRevenueByRoute(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $query = $this->reservationRepository->createQueryBuilder('r')
            ->join('r.trip', 't') // 👈 Jointure avec l'entité liée (ajustez 'trip' selon votre modèle)
            ->select('CONCAT(t.departureCity, ' . "' → '" . ', t.arrivalCity) as route, SUM(r.totalAmount) as revenue')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['confirmed', 'completed', 'paye'])
            ->groupBy('route')
            ->orderBy('revenue', 'DESC')
            ->setMaxResults(5);

        $results = $query->getQuery()->getResult();

        $revenueByRoute = [];
        foreach ($results as $row) {
            $revenueByRoute[] = [
                'route' => $row['route'],
                'revenue' => (float) $row['revenue'],
            ];
        }

        return $revenueByRoute;
    }

    /**
     * Get platform payment method distribution.
     */
    private function getPlatformPaymentDistribution(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $query = $this->reservationRepository->createQueryBuilder('r')
            ->select('r.paymentMethod, COUNT(r.id) as count')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['confirmed', 'completed', 'paye'])
            ->groupBy('r.paymentMethod');

        $results = $query->getQuery()->getResult();

        $colors = ['#f59e0b', '#16a34a', '#3b82f6', '#ef4444', '#8b5cf6'];
        $paymentDistribution = [];

        foreach ($results as $index => $row) {
            $paymentDistribution[] = [
                'label' => $row['paymentMethod'] ?? 'Unknown',
                'value' => (int) $row['count'],
                'color' => $colors[$index % count($colors)],
            ];
        }

        return $paymentDistribution;
    }

    /**
     * Get revenue time series data.
     */
    private function getRevenueTimeSeries(\DateTimeInterface $startDate, \DateTimeInterface $endDate, string $period): array
    {
        // Get platform revenue series
        $revenueChartData = $this->walletTransactionRepository->getRevenueChartData($startDate, $endDate, $period);

        // Get benefice series (platform net earnings over time)
        $beneficeSeries = [];
        $commissionsSeries = [];

        foreach ($revenueChartData['labels'] as $label) {
            // For simplicity, we'll use the same values for demo
            // In a real implementation, these would come from separate queries
            $beneficeSeries[] = (float) ($revenueChartData['revenueSeries'][array_key_last($beneficeSeries)] ?? 0 * 0.3);
            $commissionsSeries[] = (float) ($revenueChartData['revenueSeries'][array_key_last($commissionsSeries)] ?? 0 * 0.1);
        }

        return [
            'labels' => $revenueChartData['labels'],
            'revenueSeries' => $revenueChartData['revenueSeries'],
            'beneficeSeries' => $beneficeSeries,
            'commissionsSeries' => $commissionsSeries,
        ];
    }

    /**
     * Get platform refunds trend.
     */
    private function getPlatformRefundsTrend(\DateTimeInterface $startDate, \DateTimeInterface $endDate, string $period): array
    {
        $query = $this->paymentLogRepository->createQueryBuilder('pl')
            ->select('DATE_FORMAT(pl.createdAt, :format) as period, SUM(pl.amount) as total')
            ->where('pl.status IN (:statuses)')
            ->andWhere('pl.createdAt BETWEEN :start AND :end')
            ->setParameter('statuses', ['REFUND_PENDING', 'REFUNDED', 'REFUNDED_COMPLETED', 'REFUNDED_FORCE'])
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('format', $this->getDateFormat($period))
            ->groupBy('period')
            ->orderBy('period', 'ASC');

        $results = $query->getQuery()->getResult();

        $labels = [];
        $series = [];

        foreach ($results as $row) {
            $labels[] = $row['period'];
            $series[] = (float) ($row['total'] ?? 0);
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * Get Gross Merchandise Value (total transaction volume).
     */
    private function getGMV(\DateTimeInterface $startDate, \DateTimeInterface $endDate): string
    {
        $query = $this->reservationRepository->createQueryBuilder('r')
            ->select('SUM(r.totalAmount) as total')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['confirmed', 'completed', 'paye'])
            ->getQuery();

        $result = $query->getSingleScalarResult();

        return $result ?? '0.00';
    }

    /**
     * Get total wallet balances.
     */
    private function getTotalWalletBalances(): array
    {
        $total = $this->walletRepository->getTotalBalance();
        $available = $this->walletRepository->getTotalAvailableBalance();
        $reserved = $this->walletRepository->getTotalReservedBalance();

        return [
            'total' => (float) $total,
            'available' => (float) $available,
            'reserved' => (float) $reserved,
        ];
    }

    /**
     * Get withdrawal metrics.
     */
    private function getWithdrawalMetrics(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $query = $this->withdrawalRepository->createQueryBuilder('w')
            ->select('COUNT(w.id) as totalCount, SUM(w.amount) as totalAmount')
            ->where('w.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery();

        $result = $query->getSingleResult();

        $pendingQuery = $this->withdrawalRepository->createQueryBuilder('w')
            ->select('COUNT(w.id) as pendingCount, SUM(w.amount) as pendingAmount')
            ->where('w.status = :status')
            ->andWhere('w.createdAt BETWEEN :start AND :end')
            ->setParameter('status', 'pending')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery();

        $pendingResult = $pendingQuery->getSingleResult();

        return [
            'totalCount' => (int) ($result['totalCount'] ?? 0),
            'totalAmount' => (float) ($result['totalAmount'] ?? 0),
            'pendingCount' => (int) ($pendingResult['pendingCount'] ?? 0),
            'pendingAmount' => (float) ($pendingResult['pendingAmount'] ?? 0),
        ];
    }

    /**
     * Get refund metrics.
     */
    private function getRefundMetrics(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $query = $this->refundRequestRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as totalCount, SUM(r.requestedAmount) as totalAmount')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery();

        $result = $query->getSingleResult();

        $pendingQuery = $this->refundRequestRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as pendingCount, SUM(r.requestedAmount) as pendingAmount')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.createdAt BETWEEN :start AND :end')
            ->setParameter('statuses', ['REFUND_PENDING', 'PENDING'])
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery();

        $pendingResult = $pendingQuery->getSingleResult();

        return [
            'totalCount' => (int) ($result['totalCount'] ?? 0),
            'totalAmount' => (float) ($result['totalAmount'] ?? 0),
            'pendingCount' => (int) ($pendingResult['pendingCount'] ?? 0),
            'pendingAmount' => (float) ($pendingResult['pendingAmount'] ?? 0),
        ];
    }

    /**
     * Get transaction volume.
     */
    private function getTransactionVolume(\DateTimeInterface $startDate, \DateTimeInterface $endDate): int
    {
        $query = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) as count')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['confirmed', 'completed', 'paye', 'cancelled'])
            ->getQuery();

        $result = $query->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Get platform balance.
     */
    private function getPlatformBalance(): string
    {
        // This would be the platform's own wallet balance
        // For now, return platform revenue as a proxy
        $startDate = new \DateTime('-30 days');
        $endDate = new \DateTime('now');

        return $this->walletTransactionRepository->getPlatformRevenue($startDate, $endDate);
    }

    /**
     * Get GMV time series data.
     */
    private function getGMVTimeSeries(\DateTimeInterface $startDate, \DateTimeInterface $endDate, string $period): array
    {
        // Obtenir le format SQL en fonction de la période ('daily', 'weekly', 'monthly')
        $dateFormat = $this->getDateFormat($period);

        $query = $this->reservationRepository->createQueryBuilder('r')
            ->select("DATE_FORMAT(r.createdAt, '$dateFormat') as period, SUM(r.totalAmount) as total") // 👈 Alias 'r' utilisé ici
            ->where('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['confirmed', 'completed', 'paye'])
            ->groupBy('period')
            ->orderBy('period', 'ASC');

        $results = $query->getQuery()->getResult();

        $labels = [];
        $series = [];

        foreach ($results as $row) {
            $labels[] = $row['period'];
            $series[] = (float) ($row['total'] ?? 0);
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * Get benefit vs commissions time series.
     */
    private function getBenefitVsCommissionsTimeSeries(\DateTimeInterface $startDate, \DateTimeInterface $endDate, string $period): array
    {
        // For simplicity, we'll generate mock data based on GMV series
        $gmvData = $this->getGMVTimeSeries($startDate, $endDate, $period);

        $beneficeSeries = [];
        $commissionsSeries = [];

        foreach ($gmvData['series'] as $gmv) {
            // Assume 30% net margin and 10% commission rate
            $beneficeSeries[] = $gmv * 0.3;
            $commissionsSeries[] = $gmv * 0.1;
        }

        return [
            'labels' => $gmvData['labels'],
            'beneficeSeries' => $beneficeSeries,
            'commissionsSeries' => $commissionsSeries,
        ];
    }

    /**
     * Get financial detail by agency.
     */
    private function getFinancialDetailByAgency(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        // On retire SUM(r.commissionAmount) pour sélectionner uniquement le CA et le nombre de transactions
        $query = $this->reservationRepository->createQueryBuilder('r')
            ->select('a.name as agency, SUM(r.totalAmount) as ca, COUNT(r.id) as transactions')
            ->join('r.trip', 't')
            ->join('t.agency', 'a')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['confirmed', 'completed', 'paye'])
            ->groupBy('a.name')
            ->orderBy('ca', 'DESC');

        $results = $query->getQuery()->getResult();

        $financialDetail = [];
        $fixedCommissionPerReservation = WalletService::PLATFORM_FEE; // Platform fee per reservation

        foreach ($results as $row) {
            $ca = (float) ($row['ca'] ?? 0);
            $transactions = (int) ($row['transactions'] ?? 0);

            // Calcul dynamique : 500 FCFA * nombre de transactions
            $commission = $transactions * $fixedCommissionPerReservation;

            $netRevenue = $ca - $commission;
            $margin = $ca > 0 ? round(($netRevenue / $ca) * 100, 2) : 0;

            $financialDetail[] = [
                'agency' => $row['agency'],
                'ca' => $ca,
                'commission' => $commission,
                'netRevenue' => $netRevenue,
                'margin' => $margin,
                'transactions' => $transactions,
            ];
        }

        return $financialDetail;
    }

    /**
     * Calculate growth rate.
     */
    private function calculateGrowthRate(float $previousValue, float $currentValue): float
    {
        if ($previousValue == 0) {
            return $currentValue > 0 ? 100.0 : 0.0;
        }

        return round((($currentValue - $previousValue) / $previousValue) * 100, 2);
    }

    /**
     * Calculate net margin percentage.
     */
    private function calculateNetMargin(float $gmv, float $platformBalance): float
    {
        if ($gmv == 0) {
            return 0.0;
        }

        return round(($platformBalance / $gmv) * 100, 2);
    }

    /**
     * Calculate average basket size.
     */
    private function calculateAverageBasket(float $gmv, int $transactionCount): float
    {
        if ($transactionCount == 0) {
            return 0.0;
        }

        return round($gmv / $transactionCount, 2);
    }

    /**
     * Get the date format based on period.
     */
    private function getDateFormat(string $period): string
    {
        switch ($period) {
            case 'daily':
                return '%Y-%m-%d';
            case 'weekly':
                return '%Y-%u';
            case 'monthly':
            default:
                return '%Y-%m';
        }
    }
}
