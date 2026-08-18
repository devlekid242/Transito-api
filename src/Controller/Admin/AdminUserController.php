<?php

namespace App\Controller\Admin;

use App\Security\AdminRoleVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Agent;
use App\Entity\Admin;
use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AdminRepository;
use App\Repository\PaymentLogRepository;
use App\Repository\ReservationRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\AdminNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Admin User Controller for User Management in Super Admin Dashboard.
 * Provides endpoints for listing, filtering, and managing platform users (Clients and Agents).
 */
#[Route('/api/admin/users')]
#[IsGranted(AdminRoleVoter::MODERATION)]
class AdminUserController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private UserRepository $userRepository,
        private AgentRepository $agentRepository,
        private AdminRepository $adminRepository,
        private ReservationRepository $reservationRepository,
        private PaymentLogRepository $paymentLogRepository,
        private TicketRepository $ticketRepository,
        private AdminNotificationService $adminNotificationService,
    ) {}

    /**
     * Get all users with optional filtering and pagination.
     * Supports filtering by role (CLIENT, AGENT, ADMIN), status (active, suspended), and search keyword.
     */
    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Parse query parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;

        $role = $request->query->get('role'); // CLIENT, AGENT, ADMIN, or null for all
        $status = $request->query->get('status'); // active, suspended, or null for all
        $search = $request->query->get('search', '');
        $sortBy = $request->query->get('sort_by', 'createdAt');
        $sortOrder = $request->query->get('sort_order', 'DESC');

        // Validate sort fields
        $validSortFields = ['createdAt', 'fullName', 'phoneNumber', 'email', 'status'];
        if (!in_array($sortBy, $validSortFields, true)) {
            $sortBy = 'createdAt';
        }

        // Build query for all users (excluding pure admins who only have Admin entity)
        // We need to identify user types: CLIENT (regular User), AGENT (User with Agent entity), ADMIN (User with Admin entity)
        $qb = $this->userRepository->createQueryBuilder('u')
            ->leftJoin('u.admin', 'a')
            ->leftJoin('u.agent', 'ag')
            ->leftJoin('ag.agency', 'agency')
            ->orderBy("u.{$sortBy}", $sortOrder);

        // Apply filters
        if ($role) {
            $role = strtoupper($role);
            if ($role === 'CLIENT') {
                // Users who are not admins and not agents
                $qb->andWhere('a.id IS NULL AND ag.id IS NULL');
            } elseif ($role === 'AGENT') {
                // Users who have an Agent record
                $qb->andWhere('ag.id IS NOT NULL');
            } elseif ($role === 'ADMIN') {
                // Users who have an Admin record
                $qb->andWhere('a.id IS NOT NULL');
            }
        }

        if ($status) {
            $status = strtolower($status);
            if (in_array($status, ['active', 'suspended', 'inactive'], true)) {
                $qb->andWhere('u.status = :status')->setParameter('status', $status);
            }
        }

        if (!empty($search)) {
            $searchParam = '%' . $search . '%';
            $qb->andWhere('(
                u.fullName LIKE :search OR
                u.email LIKE :search OR
                u.phoneNumber LIKE :search OR
                agency.name LIKE :search
            )')->setParameter('search', $searchParam);
        }

        // Get total count
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT u.id)')->getQuery()->getSingleScalarResult();

        // Get paginated results
        $qb->setFirstResult($offset)->setMaxResults($limit);
        $users = $qb->getQuery()->getResult();

        // Normalize user data with role and agency information
        $data = array_map([$this, 'normalizeUserForList'], $users);

        return $this->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Normalize user entity for list response.
     */
    private function normalizeUserForList(User $user): array
    {
        $isAgent = $user->getAdmin() === null && $this->agentRepository->findOneBy(['user' => $user]) !== null;
        $isAdmin = $user->getAdmin() !== null;
        $role = $isAdmin ? 'ADMIN' : ($isAgent ? 'AGENT' : 'CLIENT');

        $agent = $isAgent ? $this->agentRepository->findOneBy(['user' => $user]) : null;
        $admin = $user->getAdmin();

        // Count user reservations
        $reservationsCount = $this->reservationRepository->count(['user' => $user]);

        // Count user cancellations (reservations with cancelled or no_show status)
        $cancellationsCount = $this->reservationRepository->countUserCancellations($user);

        $avatarColor = $this->getAvatarColor($user->getId());

        $userData = [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'status' => $user->getStatus(),
            'role' => $role,
            'avatarColor' => $avatarColor,
            'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'reservationsCount' => $reservationsCount,
            'cancellationsCount' => $cancellationsCount,
        ];

        if ($isAgent && $agent) {
            $agency = $agent->getAgency();
            $userData['agency'] = $agency ? [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
            ] : null;
        }

        if ($isAdmin && $admin) {
            $userData['adminRole'] = $admin->getAdminRole();
            $userData['adminStatus'] = $admin->getStatus();
        }

        return $userData;
    }

    /**
     * Get user KPI statistics.
     */
    #[Route('/kpis', name: 'api_admin_users_kpis', methods: ['GET'])]
    public function getKpis(Request $request): JsonResponse
    {
        // Get date range for calculations
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');

        // Total users (excluding pure admins for client count)
        $totalUsers = $this->userRepository->count([]);

        // Count by role
        $totalClients = $this->countUsersByRole('CLIENT');
        $totalAgents = $this->countUsersByRole('AGENT');
        $totalAdmins = $this->adminRepository->count([]);

        // Active/inactive counts
        $activeUsers = $this->userRepository->count(['status' => 'active']);
        $suspendedUsers = $this->userRepository->count(['status' => 'suspended']);
        $inactiveUsers = $this->userRepository->count(['status' => 'inactive']);

        // New users this week
        $startOfWeek = new \DateTime('this week');
        $newUsersThisWeek = $this->userRepository->countNewUsersSince($startOfWeek);

        // New users this month
        $startOfMonth = new \DateTime('first day of this month');
        $newUsersThisMonth = $this->userRepository->countNewUsersSince($startOfMonth);

        // Total reservations
        $totalReservations = $this->reservationRepository->count([]);

        // Average cancellation rate
        $cancellationRate = $this->reservationRepository->getCancellationRate();

        return $this->json([
            'success' => true,
            'data' => [
                'totalUsers' => $totalUsers,
                'totalClients' => $totalClients,
                'totalAgents' => $totalAgents,
                'totalAdmins' => $totalAdmins,
                'activeUsers' => $activeUsers,
                'blockedUsers' => $suspendedUsers + $inactiveUsers,
                'newUsersThisWeek' => $newUsersThisWeek,
                'newUsersThisMonth' => $newUsersThisMonth,
                'totalReservations' => $totalReservations,
                'cancellationRate' => round($cancellationRate, 2),
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get a single user profile with detailed information.
     * Includes user details, statistics, reservations, and transactions.
     */
    #[Route('/{id}', name: 'api_admin_users_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getUserProfile(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $isAgent = $this->agentRepository->findOneBy(['user' => $user]) !== null;
        $isAdmin = $user->getAdmin() !== null;
        $role = $isAdmin ? 'ADMIN' : ($isAgent ? 'AGENT' : 'CLIENT');

        $agent = $isAgent ? $this->agentRepository->findOneBy(['user' => $user]) : null;
        $admin = $user->getAdmin();

        // Get user reservations
        $reservations = $this->reservationRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        // Get user payment logs (transactions) via reservations
        $reservationIds = array_map(function ($r) {
            return $r->getId();
        }, $reservations);
        $transactions = empty($reservationIds) ? [] : $this->paymentLogRepository->createQueryBuilder('pl')
            ->where('pl.reservation IN (:reservationIds)')
            ->setParameter('reservationIds', $reservationIds)
            ->orderBy('pl.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Calculate statistics
        $stats = $this->calculateUserStats($user, $reservations, $transactions);

        $userData = [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'role' => $role,
            'status' => $user->getStatus(),
            'avatarColor' => $this->getAvatarColor($user->getId()),
            'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'villeResidence' => $user->getVilleResidence(),
            'quartier' => $user->getQuartier(),
            'emergencyContactName' => $user->getEmergencyContactName(),
            'emergencyContactPhone' => $user->getEmergencyContactPhone(),
        ];

        if ($isAgent && $agent) {
            $agency = $agent->getAgency();
            $userData['agency'] = $agency ? [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
                'phone' => $agency->getPhone(),
                'email' => $agency->getEmail(),
            ] : null;
            $userData['agentRole'] = $agent->getAgentRole();
            $userData['agentStatus'] = $agent->getStatus();
        }

        if ($isAdmin && $admin) {
            $userData['adminRole'] = $admin->getAdminRole();
            $userData['adminStatus'] = $admin->getStatus();
            $userData['adminPermissions'] = $admin->getPermissions();
        }

        return $this->json([
            'success' => true,
            'data' => [
                'user' => $userData,
                'stats' => $stats,
                'reservations' => array_map([$this, 'normalizeReservationForProfile'], $reservations),
                'cancellations' => array_map([$this, 'normalizeReservationForProfile'], array_filter($reservations, function ($r) {
                    return in_array(strtolower($r->getPaymentStatus() ?? ''), ['rembourse', 'echoue']);
                })),
                'transactions' => array_map([$this, 'normalizeTransactionForProfile'], $transactions),
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Calculate user statistics from reservations and transactions.
     */
    private function calculateUserStats(User $user, array $reservations, array $transactions): array
    {
        $totalReservations = count($reservations);
        $completed = 0;
        $cancelled = 0;
        $noShow = 0;
        $totalSpent = 0;
        $totalAmount = 0;
        $routeCounts = [];

        foreach ($reservations as $reservation) {
            $status = strtolower($reservation->getPaymentStatus() ?? '');
            $amount = (float) ($reservation->getTotalAmount() ?? 0);
            $route = $reservation->getTrip() ? $reservation->getTrip()->getRoute() : 'Inconnue';

            if (in_array($status, ['paye'])) {
                $completed++;
            } elseif (in_array($status, ['echoue', 'rembourse'])) {
                $cancelled++;
            }
            // NB: le modèle actuel (paymentStatus: en_attente/paye/echoue/rembourse)
            // ne distingue pas les "no-show" des autres échecs — il faudrait un
            // vrai champ de statut de réservation pour ça. noShow reste à 0 tant
            // que ce champ n'existe pas.

            // Count total amount for all reservations non annulées/non remboursées
            if (!in_array($status, ['echoue', 'rembourse'])) {
                $totalSpent += $amount;
            }
            $totalAmount += $amount;

            // Track routes
            if (!isset($routeCounts[$route])) {
                $routeCounts[$route] = 0;
            }
            $routeCounts[$route]++;
        }

        // Calculate average ticket
        $avgTicket = $totalReservations > 0 ? round($totalAmount / $totalReservations) : 0;

        // Find favorite route
        $favoriteRoute = '—';
        if (!empty($routeCounts)) {
            arsort($routeCounts);
            $favoriteRoute = key($routeCounts);
        }

        return [
            'totalReservations' => $totalReservations,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'noShow' => $noShow,
            'totalSpent' => (float) $totalSpent,
            'avgTicket' => (float) $avgTicket,
            'favoriteRoute' => $favoriteRoute,
        ];
    }

    /**
     * Normalize reservation for profile response.
     */
    private function normalizeReservationForProfile(Reservation $reservation): array
    {
        $trip = $reservation->getTrip();

        // Create route string from departure and arrival cities
        $route = 'Inconnue';
        $departureTime = 'N/A';
        $agencyName = 'Inconnue';

        if ($trip) {
            $departureCity = $trip->getDepartureCity() ?? 'N/A';
            $arrivalCity = $trip->getArrivalCity() ?? 'N/A';
            $route = $departureCity !== 'N/A' && $arrivalCity !== 'N/A' ? $departureCity . ' → ' . $arrivalCity : 'Inconnue';

            $departureTimeObj = $trip->getDepartureTime();
            $departureTime = $departureTimeObj ? $departureTimeObj->format('H:i') : 'N/A';

            $agency = $trip->getAgency();
            $agencyName = $agency ? $agency->getName() : 'Inconnue';
        }

        return [
            'id' => $reservation->getId(),
            'reference' => $reservation->getTransactionReference() ?? 'N/A',
            'route' => $route,
            'date' => $reservation->getCreatedAt()?->format('d/m/Y'),
            'departure' => $departureTime,
            'agency' => $agencyName,
            'amount' => (float) $reservation->getTotalAmount(),
            'seats' => 1, // Simplified - would need to check tickets
            'status' => $this->normalizeReservationStatus($reservation->getPaymentStatus()),
            'paymentMethod' => $reservation->getPaymentMethod(),
            'paymentStatus' => $reservation->getPaymentStatus(),
        ];
    }

    /**
     * Normalize reservation status for frontend.
     */
    private function normalizeReservationStatus(?string $status): string
    {
        if (!$status) return 'PENDING';

        $statusMap = [
            'en_attente' => 'PENDING',
            'confirmed' => 'CONFIRMED',
            'confirmee' => 'CONFIRMED',
            'paye' => 'PAID',
            'payée' => 'PAID',
            'completee' => 'COMPLETED',
            'completée' => 'COMPLETED',
            'terminée' => 'COMPLETED',
            'termine' => 'COMPLETED',
            'cancelled' => 'CANCELLED',
            'annule' => 'CANCELLED',
            'annulée' => 'CANCELLED',
            'no_show' => 'NO_SHOW',
            'echoue' => 'FAILED',
            'échouée' => 'FAILED',
            'rembourse' => 'REFUNDED',
            'remboursée' => 'REFUNDED',
        ];

        return $statusMap[strtolower($status)] ?? strtoupper($status);
    }

    /**
     * Normalize transaction for profile response.
     */
    private function normalizeTransactionForProfile(PaymentLog $paymentLog): array
    {
        $reservation = $paymentLog->getReservation();
        $amount = (float) $paymentLog->getAmount();

        // Infer type from context
        $type = 'PAYMENT'; // Default to payment
        if ($amount < 0) {
            $type = 'REFUND';
        }

        // Get payment method from reservation
        $paymentMethod = $reservation ? $reservation->getPaymentMethod() : $paymentLog->getOperator();

        // Create label based on reservation info
        $label = 'Transaction #' . $paymentLog->getId();
        if ($reservation) {
            $trip = $reservation->getTrip();
            if ($trip) {
                $label = sprintf('Paiement: %s -> %s', $trip->getDepartureCity(), $trip->getArrivalCity());
            }
        }

        return [
            'id' => $paymentLog->getId(),
            'type' => $type,
            'label' => $label,
            'amount' => $amount,
            'date' => $paymentLog->getCreatedAt()?->format('d/m/Y H:i'),
            'status' => $this->normalizeTransactionStatus($paymentLog->getStatus()),
            'paymentMethod' => $paymentMethod ?? 'UNKNOWN',
            'description' => $paymentLog->getReference() ?? '',
            'operator' => $paymentLog->getOperator(),
            'reference' => $paymentLog->getReference(),
        ];
    }

    /**
     * Normalize transaction status.
     */
    private function normalizeTransactionStatus(?string $status): string
    {
        if (!$status) return 'PENDING';

        $statusMap = [
            'en_attente' => 'PENDING',
            'pending' => 'PENDING',
            'success' => 'SUCCESS',
            'succes' => 'SUCCESS',
            'réussi' => 'SUCCESS',
            'reussi' => 'SUCCESS',
            'failed' => 'FAILED',
            'échoué' => 'FAILED',
            'echoue' => 'FAILED',
            'refunded' => 'REFUNDED',
            'rembourse' => 'REFUNDED',
            'remboursée' => 'REFUNDED',
        ];

        return $statusMap[strtolower($status)] ?? strtoupper($status);
    }

    /**
     * Toggle user status (active <-> suspended).
     */
    #[Route('/{id}/toggle-status', name: 'api_admin_users_toggle_status', methods: ['PUT', 'PATCH'])]
    public function toggleUserStatus(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        // Check if user is an agent
        $agent = $this->agentRepository->findOneBy(['user' => $user]);

        // Determine current effective status
        $currentStatus = $user->getStatus();
        $newStatus = $currentStatus === 'active' ? 'suspended' : 'active';

        // Update user status
        $user->setStatus($newStatus);

        // If user is an agent, also update agent status
        if ($agent) {
            $agent->setStatus($newStatus === 'active' ? 'active' : 'inactive');
            $this->em->persist($agent);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Statut utilisateur mis à jour',
            'data' => [
                'id' => $user->getId(),
                'status' => $newStatus,
            ],
        ]);
    }

    /**
     * Count users by role type.
     */
    private function countUsersByRole(string $role): int
    {
        if ($role === 'ADMIN') {
            return $this->adminRepository->count([]);
        }

        if ($role === 'AGENT') {
            return $this->agentRepository->count([]);
        }

        // CLIENT: Users who are not admins and not agents
        $totalUsers = $this->userRepository->count([]);
        $totalAdmins = $this->adminRepository->count([]);
        $totalAgents = $this->agentRepository->count([]);

        return $totalUsers - $totalAdmins - $totalAgents;
    }

    /**
     * Generate consistent avatar color based on user ID.
     */
    private function getAvatarColor(?int $userId): string
    {
        if (!$userId) return 'bg-gray-500';

        $colors = [
            'bg-rose-500',
            'bg-green-500',
            'bg-amber-500',
            'bg-cyan-500',
            'bg-violet-500',
            'bg-pink-500',
            'bg-indigo-500',
            'bg-emerald-500',
            'bg-teal-500',
            'bg-orange-500',
            'bg-sky-500',
            'bg-lime-500',
        ];

        $index = ($userId % count($colors));
        return $colors[$index] ?? 'bg-green-500';
    }
}
