<?php

namespace App\Controller\Admin;

use App\Entity\Agency;
use App\Entity\Reservation;
use App\Entity\WithdrawalRequest;
use App\Entity\Wallet;
use App\Entity\PaymentLog;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Repository\AdminRepository;
use App\Repository\ReservationRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Repository\WalletRepository;
use App\Repository\PaymentLogRepository;
use App\Repository\SupportTicketRepository;
use App\Repository\UserRepository;
use App\Repository\AgentRepository;
use App\Repository\WalletTransactionRepository;
use App\Repository\RefundRequestRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Admin Dashboard KPI endpoints for the Super Admin Dashboard.
 * Provides aggregated metrics and analytics for the overview dashboard.
 */
#[Route('/api/admin/dashboard')]
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private AgencyRepository $agencyRepository,
        private AdminRepository $adminRepository,
        private UserRepository $userRepository,
        private AgentRepository $agentRepository,
        private ReservationRepository $reservationRepository,
        private WithdrawalRequestRepository $withdrawalRepository,
        private WalletRepository $walletRepository,
        private WalletTransactionRepository $walletTransactionRepository,
        private PaymentLogRepository $paymentLogRepository,
        private SupportTicketRepository $supportTicketRepository,
        private RefundRequestRepository $refundRequestRepository,
        private TicketRepository $ticketRepository,
    ) {}

    /**
     * Get all KPI metrics for the dashboard overview.
     */
    #[Route('/kpis', name: 'api_admin_dashboard_kpis', methods: ['GET'])]
    public function getKpis(Request $request): JsonResponse
    {
        // Parse date range from query parameters
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');
        
        // Active and total agencies
        $totalAgencies = $this->agencyRepository->count([]);
        $activeAgencies = $this->agencyRepository->count(['status' => 'active']);
        
        // Total users (clients + agents)
        $totalUsers = $this->userRepository->count([]);
        $newUsersThisWeek = $this->getNewUsersThisWeek();
        
        // Financial metrics
        $totalBalanceLocked = $this->walletRepository->getTotalReservedBalance();
        $totalBalanceAvailable = $this->walletRepository->getTotalAvailableBalance();
        $totalBlockedBalance = $this->walletRepository->getTotalBlockedBalance(
            $this->refundRequestRepository,
            $this->ticketRepository
        );
        $platformRevenue = $this->walletTransactionRepository->getPlatformRevenue($startDate, $endDate);
        $pendingRefundsAmount = $this->paymentLogRepository->getPendingRefundsAmount();
        
        // Daily/Weekly reservations
        $reservationsToday = $this->reservationRepository->countReservationsToday();
        $reservationsThisWeek = $this->reservationRepository->countReservationsThisWeek();
        
        // Withdrawal alerts
        $pendingWithdrawalsCount = $this->withdrawalRepository->count(['status' => 'pending']);
        $pendingWithdrawalsAmount = $this->withdrawalRepository->getPendingWithdrawalsAmount();
        
        // Agents count
        // $totalAgents = $this->userRepository->count(['roles' => 'ROLE_PARTNER']);
        $totalAgents = $this->agentRepository->count([]);
        $activeClientsToday = $this->getActiveClientsToday();
        
        // Fill rate and cancellation rate (calculated from reservations)
        $fillRate = $this->reservationRepository->getAverageFillRate();
        $cancellationRate = $this->reservationRepository->getCancellationRate();
        
        return $this->json([
            'success' => true,
            'data' => [
                'agencies' => [
                    'total' => $totalAgencies,
                    'active' => $activeAgencies,
                    'inactive' => $totalAgencies - $activeAgencies,
                ],
                'users' => [
                    'total' => $totalUsers,
                    'newThisWeek' => $newUsersThisWeek,
                ],
                'finance' => [
                    'totalBalanceLocked' => (float) $totalBalanceLocked,
                    'totalBalanceAvailable' => (float) $totalBalanceAvailable,
                    'totalBlockedBalance' => (float) $totalBlockedBalance,
                    'platformRevenue' => (float) $platformRevenue,
                    'pendingRefunds' => (float) $pendingRefundsAmount,
                ],
                'reservations' => [
                    'today' => $reservationsToday,
                    'thisWeek' => $reservationsThisWeek,
                    'fillRate' => round($fillRate, 2),
                    'cancellationRate' => round($cancellationRate, 2),
                ],
                'withdrawals' => [
                    'pendingCount' => $pendingWithdrawalsCount,
                    'pendingAmount' => (float) $pendingWithdrawalsAmount,
                ],
                'agents' => [
                    'total' => $totalAgents,
                    'activeClientsToday' => $activeClientsToday,
                ],
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get recent activity feed for dashboard.
     */
    #[Route('/activity', name: 'api_admin_dashboard_activity', methods: ['GET'])]
    public function getRecentActivity(): JsonResponse
    {
        $recentWithdrawals = $this->withdrawalRepository->findBy(
            ['status' => 'pending'],
            ['createdAt' => 'DESC'],
            5
        );
        
        $recentReservations = $this->reservationRepository->findBy(
            [],
            ['createdAt' => 'DESC'],
            5
        );
        
        $recentSupportTickets = $this->supportTicketRepository->findBy(
            ['status' => 'open'],
            ['createdAt' => 'DESC'],
            5
        );
        
        $activity = [];
        
        // Process withdrawals
        foreach ($recentWithdrawals as $withdrawal) {
            $activity[] = [
                'id' => 'w_' . $withdrawal->getId(),
                'type' => 'withdrawal',
                'text' => 'Nouvelle demande de retrait',
                'detail' => sprintf('Agence %s - %s FCFA', $withdrawal->getAgency()?->getName() ?? 'Inconnue', number_format((float) $withdrawal->getAmount(), 0, '', ' ')),
                'time' => $withdrawal->getCreatedAt()?->format('H:i'),
                'date' => $withdrawal->getCreatedAt()?->format('Y-m-d'),
                'severity' => 'warning',
                'icon' => 'fa-hand-holding-dollar',
                'iconBg' => 'bg-amber-100',
                'iconColor' => 'text-amber-600',
            ];
        }
        
        // Process reservations
        foreach ($recentReservations as $reservation) {
            $activity[] = [
                'id' => 'r_' . $reservation->getId(),
                'type' => 'reservation',
                'text' => 'Nouvelle réservation',
                'detail' => sprintf('Réservation #%d - %s FCFA', $reservation->getId(), number_format((float) $reservation->getTotalAmount(), 0, '', ' ')),
                'time' => $reservation->getCreatedAt()?->format('H:i'),
                'date' => $reservation->getCreatedAt()?->format('Y-m-d'),
                'severity' => 'info',
                'icon' => 'fa-ticket',
                'iconBg' => 'bg-blue-100',
                'iconColor' => 'text-blue-600',
            ];
        }
        
        // Process support tickets
        foreach ($recentSupportTickets as $ticket) {
            $activity[] = [
                'id' => 's_' . $ticket->getId(),
                'type' => 'support',
                'text' => 'Nouveau ticket de support',
                'detail' => sprintf('Ticket #%d - %s', $ticket->getId(), substr($ticket->getSubject() ?? 'Sans sujet', 0, 50)),
                'time' => $ticket->getCreatedAt()?->format('H:i'),
                'date' => $ticket->getCreatedAt()?->format('Y-m-d'),
                'severity' => 'danger',
                'icon' => 'fa-headset',
                'iconBg' => 'bg-red-100',
                'iconColor' => 'text-red-600',
            ];
        }
        
        // Sort by date and time (most recent first)
        usort($activity, function($a, $b) {
            $dateA = $a['date'] . ' ' . $a['time'];
            $dateB = $b['date'] . ' ' . $b['time'];
            return strtotime($dateB) <=> strtotime($dateA);
        });
        
        return $this->json([
            'success' => true,
            'data' => array_slice($activity, 0, 10), // Return top 10
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get alert summaries for dashboard.
     */
    #[Route('/alerts', name: 'api_admin_dashboard_alerts', methods: ['GET'])]
    public function getAlerts(): JsonResponse
    {
        $alerts = [];
        
        // Pending withdrawals alert
        $pendingWithdrawals = $this->withdrawalRepository->findBy(['status' => 'pending']);
        if (!empty($pendingWithdrawals)) {
            $totalAmount = array_reduce($pendingWithdrawals, function($sum, $w) {
                return $sum + (float) $w->getAmount();
            }, 0);
            
            $alerts[] = [
                'id' => 'alert_withdrawals',
                'type' => 'withdrawal',
                'label' => sprintf('%d demande(s) de retrait en attente', count($pendingWithdrawals)),
                'description' => sprintf('Montant total: %s FCFA', number_format($totalAmount, 0, '', ' ')),
                'amount' => $totalAmount,
                'count' => count($pendingWithdrawals),
                'severity' => count($pendingWithdrawals) > 5 ? 'danger' : 'warning',
            ];
        }
        
        // Pending refunds alert
        $pendingRefunds = $this->paymentLogRepository->findBy(['status' => 'REFUND_PENDING']);
        if (!empty($pendingRefunds)) {
            $refundAmount = array_reduce($pendingRefunds, function($sum, $pl) {
                return $sum + (float) ($pl->getAmount() ?? 0);
            }, 0);
            
            $alerts[] = [
                'id' => 'alert_refunds',
                'type' => 'refund',
                'label' => sprintf('%d remboursement(s) en attente', count($pendingRefunds)),
                'description' => sprintf('Montant total: %s FCFA', number_format($refundAmount, 0, '', ' ')),
                'amount' => $refundAmount,
                'count' => count($pendingRefunds),
                'severity' => count($pendingRefunds) > 3 ? 'danger' : 'warning',
            ];
        }
        
        // Low balance agencies alert
        $lowBalanceAgencies = $this->walletRepository->findAgenciesWithLowBalance(1000);
        if (!empty($lowBalanceAgencies)) {
            $alerts[] = [
                'id' => 'alert_low_balance',
                'type' => 'agency',
                'label' => sprintf('%d agence(s) avec solde faible', count($lowBalanceAgencies)),
                'description' => 'Agences avec moins de 1000 FCFA disponibles',
                'amount' => null,
                'count' => count($lowBalanceAgencies),
                'severity' => 'warning',
            ];
        }
        
        return $this->json([
            'success' => true,
            'data' => $alerts,
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get chart data for revenue and new users.
     * Returns both revenue and new users series for the combined chart.
     */
    #[Route('/charts/revenue', name: 'api_admin_dashboard_charts_revenue', methods: ['GET'])]
    public function getRevenueChartData(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'monthly'); // daily, weekly, monthly
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-8 months');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');
        
        // Get revenue data
        $revenueChartData = $this->walletTransactionRepository->getRevenueChartData($startDate, $endDate, $period);
        
        // Get new users data (grouped by same period)
        $newUsersByPeriod = $this->userRepository->getNewUsersByPeriod($startDate, $endDate, $period);
        
        // Merge labels (revenue labels are already formatted)
        $labels = $revenueChartData['labels'];
        
        // Ensure new users series matches the same periods
        $newUsersSeries = [];
        foreach ($revenueChartData['labels'] as $label) {
            $found = false;
            foreach ($newUsersByPeriod as $row) {
                $periodLabel = $this->formatPeriodLabel($row['period'], $period);
                if ($periodLabel === $label) {
                    $newUsersSeries[] = (int) ($row['count'] ?? 0);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $newUsersSeries[] = 0;
            }
        }
        
        return $this->json([
            'success' => true,
            'data' => [
                'labels' => $labels,
                'revenueSeries' => $revenueChartData['revenueSeries'],
                'newUsersSeries' => $newUsersSeries,
            ],
        ]);
    }

    /**
     * Format period label based on period type.
     */
    private function formatPeriodLabel($period, string $periodType): string
    {
        if (is_string($period)) {
            return $period;
        }
        
        if ($periodType === 'monthly' && $period instanceof \DateTime) {
            return $period->format('Y-m');
        }
        
        if ($periodType === 'weekly' && $period instanceof \DateTime) {
            return $period->format('Y-W');
        }
        
        if ($periodType === 'daily' && $period instanceof \DateTime) {
            return $period->format('Y-m-d');
        }
        
        return (string) $period;
    }

    /**
     * Get chart data for user distribution.
     */
    #[Route('/charts/users', name: 'api_admin_dashboard_charts_users', methods: ['GET'])]
    public function getUserDistribution(): JsonResponse
    {
        $totalUsers = $this->userRepository->count([]);
        $totalAgents = $this->agentRepository->count([]);
        $totalAdmin = $this->adminRepository->count([]);
        $totalClients = $totalUsers - ( $totalAgents + $totalAdmin);
        
        return $this->json([
            'success' => true,
            'data' => [
                ['label' => 'Clients', 'value' => $totalClients, 'color' => '#2563eb'],
                ['label' => 'Agents', 'value' => $totalAgents, 'color' => '#16a34a'],
                ['label' => 'Administratuers', 'value' => $totalAdmin, 'color' => '#9b1818'],
            ],
        ]);
    }

    /**
     * Get chart data for payment methods.
     */
    #[Route('/charts/payments', name: 'api_admin_dashboard_charts_payments', methods: ['GET'])]
    public function getPaymentDistribution(): JsonResponse
    {
        $paymentMethods = $this->reservationRepository->getPaymentMethodDistribution();
        
        $data = [];
        foreach ($paymentMethods as $method => $count) {
            $color = $this->getPaymentMethodColor($method);
            $data[] = [
                'label' => $method,
                'value' => $count,
                'color' => $color,
            ];
        }
        
        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get KYC compliance distribution.
     */
    #[Route('/charts/kyc', name: 'api_admin_dashboard_charts_kyc', methods: ['GET'])]
    public function getKycDistribution(): JsonResponse
    {
        $kycStats = $this->agencyRepository->getKycStatusDistribution();
        
        $data = [];
        foreach ($kycStats as $status => $count) {
            $color = $this->getKycStatusColor($status);
            $data[] = [
                'label' => $status,
                'value' => $count,
                'color' => $color,
            ];
        }
        
        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get top routes by reservations and revenue.
     */
    #[Route('/top-routes', name: 'api_admin_dashboard_top_routes', methods: ['GET'])]
    public function getTopRoutes(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 5);
        $topRoutes = $this->reservationRepository->findTopRoutes($limit);

        $data = [];
        foreach ($topRoutes as $route) {
            $data[] = [
                'route' => $route['route'],
                'bookings' => $route['reservationCount'],
                'revenue' => (float) $route['totalAmount'],
                'fillRate' => round((float) ($route['fillRate'] ?? 0), 1),
            ];
        }
        
        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get reservations trend for the current week.
     * Returns daily reservations count for the last 7 days.
     */
    #[Route('/charts/reservations', name: 'api_admin_dashboard_charts_reservations', methods: ['GET'])]
    public function getReservationsTrend(): JsonResponse
    {
        $reservationsByDay = $this->reservationRepository->getReservationsByDay();
        
        $labels = [];
        $series = [];
        
        // Get last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = new \DateTime('-' . $i . ' days');
            $dateStr = $date->format('Y-m-d');
            
            $labels[] = $date->format('D'); // Short weekday name
            
            // Find count for this date
            $count = 0;
            foreach ($reservationsByDay as $row) {
                if ($row['date'] === $dateStr) {
                    $count = (int) $row['count'];
                    break;
                }
            }
            $series[] = $count;
        }
        
        return $this->json([
            'success' => true,
            'data' => [
                'labels' => $labels,
                'series' => $series,
            ],
        ]);
    }

    /**
     * Get new users trend for chart.
     * Returns weekly new users count for the last 8 months.
     */
    #[Route('/charts/new-users', name: 'api_admin_dashboard_charts_new_users', methods: ['GET'])]
    public function getNewUsersTrend(): JsonResponse
    {
        $newUsersByMonth = $this->userRepository->getNewUsersByMonth();
        
        $labels = [];
        $series = [];
        
        // Get last 8 months
        for ($i = 7; $i >= 0; $i--) {
            $date = new \DateTime('-' . $i . ' months');
            $monthStr = $date->format('Y-m');
            
            $labels[] = $date->format('M'); // Short month name
            
            // Find count for this month
            $count = 0;
            foreach ($newUsersByMonth as $row) {
                if ($row['month'] === $monthStr) {
                    $count = (int) $row['count'];
                    break;
                }
            }
            $series[] = $count;
        }
        
        return $this->json([
            'success' => true,
            'data' => [
                'labels' => $labels,
                'series' => $series,
            ],
        ]);
    }

    /**
     * Helper: Get new users count for this week.
     */
    private function getNewUsersThisWeek(): int
    {
        $startOfWeek = new \DateTime('this week');
        return $this->userRepository->countNewUsersSince($startOfWeek);
    }

    /**
     * Helper: Get active clients count for today.
     */
    private function getActiveClientsToday(): int
    {
        $startOfDay = new \DateTime('today');
        return $this->userRepository->countActiveClientsToday($startOfDay);
    }

    /**
     * Helper: Get color for payment method.
     */
    private function getPaymentMethodColor(string $method): string
    {
        $colors = [
            'Mobile Money' => '#0891b2',
            'Orange Money' => '#ff6b35',
            'MTN Mobile Money' => '#ffcc00',
            'Airtel Money' => '#e91e63',
            'Cash' => '#9e9e9e',
            'Card' => '#2196f3',
        ];
        return $colors[strtolower($method)] ?? '#6c757d';
    }

    /**
     * Helper: Get color for KYC status.
     */
    private function getKycStatusColor(string $status): string
    {
        $colors = [
            'verified' => '#10b981',
            'pending' => '#f59e0b',
            'missing' => '#ef4444',
            'rejected' => '#6b7280',
        ];
        return $colors[strtolower($status)] ?? '#6c757d';
    }
}
