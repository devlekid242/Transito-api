<?php

namespace App\Controller\Admin;

use App\Service\ModerationStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use DateTimeInterface;

/**
 * Moderation Stats Controller for the Moderation & Analytics Dashboard.
 * Provides comprehensive statistical endpoints for platform analytics and agency comparison.
 */
#[Route('/api/admin/moderation')]
class ModerationStatsController extends AbstractController
{
    public function __construct(
        private ModerationStatsService $moderationStatsService,
    ) {}

    /**
     * CORRECTIF : `new \DateTime($request->query->get('start_date'))` lève
     * une \Exception non gérée si la chaîne fournie n'est pas une date
     * valide (ex: ?start_date=n-importe-quoi), ce qui remontait en 500 côté
     * client au lieu d'un message d'erreur exploitable. On centralise le
     * parsing ici avec un retour null explicite en cas d'échec, et les
     * endpoints renvoient désormais un 400 avec un message clair.
     */
    private function parseDate(Request $request, string $param): ?\DateTime
    {
        $value = $request->query->get($param);

        if (!$value) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf(
                "Le paramètre '%s' n'est pas une date valide.",
                $param
            ));
        }
    }

    /**
     * Parse la liste d'IDs d'agences depuis la query string.
     */
    private function parseAgencyIds(Request $request): ?array
    {
        $agencyIds = $request->query->all('agency_ids');

        if ($agencyIds && is_array($agencyIds)) {
            return array_map('intval', $agencyIds);
        }

        return null;
    }

    /**
     * Get comprehensive moderation statistics.
     * Main endpoint that returns all statistics for the dashboard.
     */
    #[Route('/stats', name: 'api_admin_moderation_stats', methods: ['GET'])]
    public function getStats(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getModerationStats($startDate, $endDate, $agencyIds);

        return $this->json($result);
    }

    /**
     * Get agency comparison summary.
     * Returns leaderboard-style comparison data for agencies.
     */
    #[Route('/comparison', name: 'api_admin_moderation_comparison', methods: ['GET'])]
    public function getComparison(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $limit = (int) $request->query->get('limit', 10);
        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getAgencyComparisonSummary(
            $startDate,
            $endDate,
            $agencyIds,
            $limit
        );

        return $this->json($result);
    }

    /**
     * Get chart data for different chart types.
     * Returns formatted data for line charts (users, reservations, revenue).
     */
    #[Route('/charts/{chartType}', name: 'api_admin_moderation_charts', requirements: ['chartType' => 'users|reservations|revenue'], methods: ['GET'])]
    public function getChartData(string $chartType, Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $period = $request->query->get('period', 'monthly'); // daily, weekly, monthly
        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getChartData(
            $chartType,
            $startDate,
            $endDate,
            $agencyIds,
            $period
        );

        if (!$result['success']) {
            return $this->json($result, Response::HTTP_BAD_REQUEST);
        }

        return $this->json($result);
    }

    /**
     * Get date presets for frontend filtering.
     * Returns predefined date ranges for easy filtering.
     */
    #[Route('/date-presets', name: 'api_admin_moderation_date_presets', methods: ['GET'])]
    public function getDatePresets(): JsonResponse
    {
        $presets = $this->moderationStatsService->getDatePresets();

        return $this->json([
            'success' => true,
            'data' => $presets,
        ]);
    }

    /**
     * Get agencies for filter dropdown.
     * Returns list of agencies for multi-select filtering.
     */
    #[Route('/agencies', name: 'api_admin_moderation_agencies', methods: ['GET'])]
    public function getAgencies(): JsonResponse
    {
        $result = $this->moderationStatsService->getAgenciesForFilter();

        return $this->json($result);
    }

    /**
     * Get user statistics specifically.
     * Endpoint focused on user-related metrics.
     */
    #[Route('/users/stats', name: 'api_admin_moderation_users_stats', methods: ['GET'])]
    public function getUserStats(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getModerationStats($startDate, $endDate, $agencyIds);

        return $this->json([
            'success' => true,
            'data' => $result['data']['users'] ?? [],
            'timestamp' => $result['timestamp'] ?? null,
        ]);
    }

    /**
     * Get agency statistics specifically.
     * Endpoint focused on agency-related metrics.
     */
    #[Route('/agencies/stats', name: 'api_admin_moderation_agencies_stats', methods: ['GET'])]
    public function getAgencyStats(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getModerationStats($startDate, $endDate, $agencyIds);

        return $this->json([
            'success' => true,
            'data' => $result['data']['agencies'] ?? [],
            'timestamp' => $result['timestamp'] ?? null,
        ]);
    }

    /**
     * Get reservation statistics specifically.
     * Endpoint focused on reservation-related metrics.
     */
    #[Route('/reservations/stats', name: 'api_admin_moderation_reservations_stats', methods: ['GET'])]
    public function getReservationStats(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getModerationStats($startDate, $endDate, $agencyIds);

        return $this->json([
            'success' => true,
            'data' => $result['data']['reservations'] ?? [],
            'timestamp' => $result['timestamp'] ?? null,
        ]);
    }

    /**
     * Get financial statistics specifically.
     * Endpoint focused on financial metrics.
     */
    #[Route('/finance/stats', name: 'api_admin_moderation_finance_stats', methods: ['GET'])]
    public function getFinanceStats(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getModerationStats($startDate, $endDate, $agencyIds);

        return $this->json([
            'success' => true,
            'data' => $result['data']['finance'] ?? [],
            'timestamp' => $result['timestamp'] ?? null,
        ]);
    }

    /**
     * Get specific agency performance data.
     * Returns detailed performance metrics for a specific agency.
     */
    #[Route('/agencies/{agencyId}/performance', name: 'api_admin_moderation_agency_performance', methods: ['GET'])]
    public function getAgencyPerformance(int $agencyId, Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->moderationStatsService->getModerationStats(
            $startDate,
            $endDate,
            [$agencyId]
        );

        if (!isset($result['data']['comparison']) || empty($result['data']['comparison'])) {
            return $this->json([
                'success' => false,
                'message' => 'Agency not found or no data available',
            ], Response::HTTP_NOT_FOUND);
        }

        $agencyData = $result['data']['comparison'][0] ?? [];

        return $this->json([
            'success' => true,
            'data' => $agencyData,
            'timestamp' => $result['timestamp'] ?? null,
        ]);
    }

    /**
     * Get leaderboard data.
     * Returns top-performing agencies by different metrics.
     */
    #[Route('/leaderboard', name: 'api_admin_moderation_leaderboard', methods: ['GET'])]
    public function getLeaderboard(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $limit = (int) $request->query->get('limit', 10);
        $metric = $request->query->get('metric', 'reservations'); // reservations, revenue, fillRate, lowestCancellation
        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getAgencyComparisonSummary(
            $startDate,
            $endDate,
            $agencyIds,
            $limit
        );

        if (!$result['success']) {
            return $this->json($result, Response::HTTP_BAD_REQUEST);
        }

        $dataKey = 'topByReservations';
        switch ($metric) {
            case 'revenue':
                $dataKey = 'topByRevenue';
                break;
            case 'fillRate':
                $dataKey = 'topByFillRate';
                break;
            case 'lowestCancellation':
                $dataKey = 'topByLowestCancellation';
                break;
            default:
                $dataKey = 'topByReservations';
        }

        return $this->json([
            'success' => true,
            'data' => $result['data'][$dataKey] ?? [],
            'metric' => $metric,
            'timestamp' => $result['timestamp'] ?? null,
        ]);
    }

    /**
     * Get combined chart data for dashboard overview.
     * Returns multiple chart datasets in a single request.
     */
    #[Route('/charts/combined', name: 'api_admin_moderation_charts_combined', methods: ['GET'])]
    public function getCombinedChartData(Request $request): JsonResponse
    {

        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $period = $request->query->get('period', 'monthly');
        $agencyIds = $this->parseAgencyIds($request);

        $userChart = $this->moderationStatsService->getChartData('users', $startDate, $endDate, $agencyIds, $period);
        $reservationChart = $this->moderationStatsService->getChartData('reservations', $startDate, $endDate, $agencyIds, $period);
        $revenueChart = $this->moderationStatsService->getChartData('revenue', $startDate, $endDate, $agencyIds, $period);

        // Get reservation status distribution for donut chart
        $reservationStats = $this->moderationStatsService->getModerationStats($startDate, $endDate, $agencyIds);

        return $this->json([
            'success' => true,
            'data' => [
                'users' => $userChart['data'] ?? [],
                'reservations' => $reservationChart['data'] ?? [],
                'revenue' => $revenueChart['data'] ?? [],
                'reservationsByStatus' => $reservationStats['data']['reservations']['reservationsByStatus'] ?? [],
                'usersByType' => $reservationStats['data']['users']['usersByType'] ?? [],
            ],
            'timestamp' => (new \DateTime())->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get KPI summary for dashboard header.
     * Returns top-level KPIs in a compact format.
     */
    #[Route('/kpis', name: 'api_admin_moderation_kpis', methods: ['GET'])]
    public function getKpis(Request $request): JsonResponse
    {
        try {
            $startDate = $this->parseDate($request, 'start_date');
            $endDate = $this->parseDate($request, 'end_date');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $agencyIds = $this->parseAgencyIds($request);

        $result = $this->moderationStatsService->getModerationStats($startDate, $endDate, $agencyIds);

        if (!$result['success']) {
            return $this->json($result, Response::HTTP_BAD_REQUEST);
        }

        $data = $result['data'];

        $kpis = [
            'users' => [
                'total' => $data['users']['total'] ?? 0,
                'active' => $data['users']['active'] ?? 0,
                'newThisWeek' => $data['users']['newThisWeek'] ?? 0,
                'newThisMonth' => $data['users']['newThisMonth'] ?? 0,
            ],
            'agencies' => [
                'total' => $data['agencies']['total'] ?? 0,
                'active' => $data['agencies']['active'] ?? 0,
                'kycVerified' => $data['agencies']['kycVerified'] ?? 0,
            ],
            'reservations' => [
                'total' => $data['reservations']['total'] ?? 0,
                'fillRate' => $data['reservations']['fillRate'] ?? 0,
                'cancellationRate' => $data['reservations']['cancellationRate'] ?? 0,
            ],
            'finance' => [
                'totalRevenue' => $data['finance']['totalRevenue'] ?? 0,
                'totalTransactions' => $data['finance']['totalTransactions'] ?? 0,
                'successfulPayments' => $data['finance']['successfulPayments'] ?? 0,
                'pendingPayments' => $data['finance']['pendingPayments'] ?? 0,
            ],
        ];

        return $this->json([
            'success' => true,
            'data' => $kpis,
            'timestamp' => $result['timestamp'] ?? null,
        ]);
    }
}