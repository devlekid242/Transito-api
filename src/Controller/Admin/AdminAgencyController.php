<?php

namespace App\Controller\Admin;

use App\Security\AdminRoleVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Agency;
use App\Entity\AgencyPoint;
use App\Entity\Bus;
use App\Entity\AgencyDocument;
use App\Entity\Agent;
use App\Entity\Reservation;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Wallet;
use App\Repository\AgencyRepository;
use App\Repository\ReservationRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use App\Repository\WalletRepository;
use App\Repository\WalletTransactionRepository;
use App\Service\StatusMapperService;
use App\Service\AgencyOperationalImpactService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Admin Agency Controller for Super Admin Dashboard.
 * Provides full CRUD operations for agencies management.
 */
#[Route('/api/admin/agencies')]
#[IsGranted(AdminRoleVoter::MODERATION)]
class AdminAgencyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private AgencyRepository $agencyRepository,
        private TripRepository $tripRepository,
        private ReservationRepository $reservationRepository,
        private WalletRepository $walletRepository,
        private WalletTransactionRepository $walletTransactionRepository,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private StatusMapperService $statusMapperService,
        private AgencyOperationalImpactService $agencyOperationalImpactService,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Get all agencies with optional filtering and pagination.
     */
    #[Route('', name: 'api_admin_agencies_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Parse query parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;

        $status = $request->query->get('status');
        $search = $request->query->get('search', '');
        $sortBy = $request->query->get('sort_by', 'createdAt');
        $sortOrder = $request->query->get('sort_order', 'DESC');

        // Build query
        $qb = $this->agencyRepository->createQueryBuilder('a')
            ->leftJoin('a.wallet', 'w')
            ->leftJoin('a.documents', 'd')
            ->orderBy("a.{$sortBy}", $sortOrder);

        // Apply filters
        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        if (!empty($search)) {
            $qb->andWhere('(
                a.name LIKE :search OR
                a.email LIKE :search OR
                a.phone LIKE :search OR
                a.registrationNumber LIKE :search OR
                a.address LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }

        // Get total count
        $countQb = clone $qb;
        $total = count($countQb->getQuery()->getResult());

        // Get paginated results
        $qb->setFirstResult($offset)->setMaxResults($limit);
        $agencies = $qb->getQuery()->getResult();

        $data = array_map([$this, 'normalizeAgencyWithStats'], $agencies);

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
     * Get a single agency with detailed information.
     */
    #[Route('/{id}', name: 'api_admin_agency_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);

        if (!$agency) {
            return $this->json([
                'success' => false,
                'message' => 'Agence introuvable',
            ], Response::HTTP_NOT_FOUND);
        }


        return $this->json([
            'success' => true,
            'data' => $this->normalizeAgencyWithStats($agency),
        ]);
    }

    /**
     * Create a new agency.
     */
    #[Route('', name: 'api_admin_agency_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate required fields
        $requiredFields = ['name', 'email', 'phone', 'passwordHash'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return $this->json([
                    'success' => false,
                    'message' => "Le champ '{$field}' est obligatoire",
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        // Check if email already exists
        $existingAgency = $this->agencyRepository->findOneBy(['email' => $data['email']]);
        if ($existingAgency) {
            return $this->json([
                'success' => false,
                'message' => 'Cette adresse email est déjà utilisée',
            ], Response::HTTP_CONFLICT);
        }

        // --- Validate the "admin" sub-payload (compte responsable de l'agence) ---
        $adminData = $data['admin'] ?? null;
        if (!is_array($adminData)) {
            return $this->json([
                'success' => false,
                'message' => "Les informations du compte administrateur ('admin') sont obligatoires",
            ], Response::HTTP_BAD_REQUEST);
        }

        $requiredAdminFields = ['name', 'email', 'phone', 'password'];
        foreach ($requiredAdminFields as $field) {
            if (empty($adminData[$field])) {
                return $this->json([
                    'success' => false,
                    'message' => "Le champ 'admin.{$field}' est obligatoire",
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        if ($this->userRepository->findOneBy(['email' => $adminData['email']])) {
            return $this->json([
                'success' => false,
                'message' => 'Cette adresse email est déjà utilisée par un compte utilisateur existant',
            ], Response::HTTP_CONFLICT);
        }

        if ($this->userRepository->findOneBy(['phoneNumber' => $adminData['phone']])) {
            return $this->json([
                'success' => false,
                'message' => 'Ce numéro de téléphone est déjà utilisé par un compte utilisateur existant',
            ], Response::HTTP_CONFLICT);
        }

        $agency = new Agency();

        // Set fields from request
        $fields = [
            'name',
            'email',
            'phone',
            'passwordHash',
            'registrationNumber',
            'address',
            'logoUrl',
            'bannerUrl',
            'websiteUrl',
            'mapUrl',
            'description',
            'status',
            'commissionRate',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $method = 'set' . str_replace('_', '', ucwords($field, '_'));
                if (method_exists($agency, $method)) {
                    $agency->$method($data[$field] !== null ? $data[$field] : null);
                }
            }
        }

        // Set defaults
        if (!isset($data['status'])) {
            $agency->setStatus('pending');
        }
        if (!isset($data['commissionRate'])) {
            $agency->setCommissionRate('10.00');
        }
        if (!isset($data['ratingCache'])) {
            $agency->setRatingCache('0.00');
        }

        try {
            $this->em->wrapInTransaction(function () use ($agency, $adminData) {
                // Create associated wallet
                $wallet = new Wallet();
                $wallet->setAgency($agency);
                $wallet->setAvailableBalance(0.00);
                $wallet->setReservedBalance(0.00);
                // $wallet->setCurrency('FCFA');
                $agency->setWallet($wallet);

                $this->em->persist($agency);
                $this->em->persist($wallet);

                // Create the User account for the agency's responsible/admin
                $user = new User();
                $user->setFullName($adminData['name']);
                $user->setEmail($adminData['email']);
                $user->setPhoneNumber($adminData['phone']);
                // villeResidence est obligatoire en base mais n'est pas collecté par ce
                // formulaire de création interne (réservé au formulaire d'inscription passager).
                $user->setVilleResidence($agency->getCity() ?: 'Non renseigné');
                $user->setPassword($this->passwordHasher->hashPassword($user, $adminData['password']));
                $this->em->persist($user);

                // Create the Agent record linking this User to the Agency as its admin
                $agent = new Agent();
                $agent->setUser($user);
                $agent->setAgency($agency);
                $agent->setAgentRole('admin_agence');
                $agent->setStatus('active');
                $agent->setCommissionRate('0.00');
                $this->em->persist($agent);

                $this->em->flush();
            });
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => "Erreur lors de la création de l'agence et du compte administrateur",
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success' => true,
            'message' => "Agence et compte administrateur créés avec succès",
            'data' => $this->normalizeAgencyWithStats($agency),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an agency.
     */
    #[Route('/{id}', name: 'api_admin_agency_update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);

        if (!$agency) {
            return $this->json([
                'success' => false,
                'message' => 'Agence introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if email is being changed and already exists
        if (isset($data['email']) && $data['email'] !== $agency->getEmail()) {
            $existingAgency = $this->agencyRepository->findOneBy(['email' => $data['email']]);
            if ($existingAgency) {
                return $this->json([
                    'success' => false,
                    'message' => 'Cette adresse email est déjà utilisée',
                ], Response::HTTP_CONFLICT);
            }
        }

        // Update fields
        $fields = [
            'name',
            'email',
            'phone',
            'passwordHash',
            'registrationNumber',
            'address',
            'logoUrl',
            'bannerUrl',
            'websiteUrl',
            'mapUrl',
            'description',
            'status',
            'commissionRate',
            'ratingCache',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $method = 'set' . str_replace('_', '', ucwords($field, '_'));
                if (method_exists($agency, $method)) {
                    $agency->$method($data[$field] !== null ? $data[$field] : null);
                }
            }
        }

        $adminData = $data['admin'] ?? null;
        // return $this->Json($data, Response::HTTP_BAD_REQUEST);
        
        if (is_array($adminData)) {
            $adminAgence = $this->em->getRepository(Agent::class)->findOneBy([
                'agency' => $agency,
                'agentRole' => 'admin_agence',
            ]);

            if ($adminAgence) {
                $user = $adminAgence->getUser();
                if (isset($adminData['name'])) {
                    $user->setFullName($adminData['name']);
                }
                if (isset($adminData['email'])) {
                    $user->setEmail($adminData['email']);
                }
                if (isset($adminData['phone'])) {
                    $user->setPhoneNumber($adminData['phone']);
                }
                if (isset($adminData['password']) && !empty($adminData['password'])) {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $adminData['password']));
                }
                $this->em->persist($user);
            }
        }

        $this->em->persist($agency);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Agence mise à jour avec succès',
            'data' => $this->normalizeAgencyWithStats($agency),
        ]);
    }

    /**
     * Preview the operational impact of suspending an agency. Read-only.
     */
    #[Route('/{id}/operational-impact', name: 'api_admin_agency_operational_impact', methods: ['GET'])]
    public function operationalImpact(int $id): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);
        if (!$agency) {
            return $this->json(['success' => false, 'message' => 'Agence introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $this->agencyOperationalImpactService->preview($agency),
        ]);
    }

    /**
     * Suspend or activate an agency. Suspension never cancels/refunds automatically.
     * When future paid reservations exist, confirmation is required explicitly.
     */
    #[Route('/{id}/toggle-status', name: 'api_admin_agency_toggle_status', methods: ['PUT'])]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);
        if (!$agency) {
            return $this->json(['success' => false, 'message' => 'Agence introuvable'], Response::HTTP_NOT_FOUND);
        }

        $newStatus = $agency->getStatus() === 'active' ? 'suspended' : 'active';
        if ($newStatus === 'suspended') {
            $impact = $this->agencyOperationalImpactService->preview($agency);
            $confirm = $request->request->getBoolean('confirm', false);
            if (!$confirm && $impact['requiresTripDecision']) {
                return $this->json([
                    'success' => false,
                    'code' => 'AGENCY_SUSPENSION_CONFIRMATION_REQUIRED',
                    'message' => 'Cette agence possède des voyages futurs avec des réservations payées. La suspension bloque les nouvelles ventes mais n’annule aucun voyage ni remboursement automatiquement. Confirmez explicitement la suspension.',
                    'data' => $impact,
                ], Response::HTTP_CONFLICT);
            }
        } else {
            if ($agency->getStatus() === 'pending') {
                return $this->json([
                    'success' => false,
                    'message' => 'Une agence en attente doit être approuvée avant activation.',
                ], Response::HTTP_CONFLICT);
            }
        }

        $before = ['status' => $agency->getStatus()];
        $agency->setStatus($newStatus);
        $this->em->persist($agency);
        $this->auditLogger->record(
            $newStatus === 'suspended' ? 'AGENCY_SUSPENDED' : 'AGENCY_REACTIVATED',
            'Agency',
            (string) $agency->getId(),
            $before,
            ['status' => $newStatus],
            ['source' => 'admin.agency.toggle-status']
        );
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => $newStatus === 'suspended' ? 'Agence suspendue. Les opérations futures restent intactes jusqu’à décision explicite sur les voyages concernés.' : 'Agence réactivée avec succès',
            'data' => [
                'id' => $agency->getId(),
                'status' => $agency->getStatus(),
                'operationalImpact' => $newStatus === 'suspended' ? $this->agencyOperationalImpactService->preview($agency) : null,
            ],
        ]);
    }

    /**
     * Delete an agency (soft delete - mark as suspended).
     */
    #[Route('/{id}', name: 'api_admin_agency_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);

        if (!$agency) {
            return $this->json([
                'success' => false,
                'message' => 'Agence introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        // Check if agency has active trips or reservations
        $activeTrips = $this->tripRepository->count([
            'agency' => $agency,
            'status' => 'planifie',
        ]);

        $activeReservations = $this->reservationRepository->count([
            'trip' => $agency->getTrips(),
            'paymentStatus' => 'en_attente',
        ]);

        if ($activeTrips > 0 || $activeReservations > 0) {
            return $this->json([
                'success' => false,
                'message' => 'Impossible de supprimer une agence avec des trajets ou réservations actifs. Suspendez-la plutôt.',
                'data' => [
                    'activeTrips' => $activeTrips,
                    'activeReservations' => $activeReservations,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        // Soft delete by suspending
        $agency->setStatus('suspended');
        $this->em->persist($agency);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Agence suspendue avec succès',
        ]);
    }

    /**
     * Get agency statistics.
     */
    #[Route('/{id}/stats', name: 'api_admin_agency_stats', methods: ['GET'])]
    public function stats(int $id, Request $request): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);

        if (!$agency) {
            return $this->json([
                'success' => false,
                'message' => 'Agence introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        // Parse date range
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');

        // Get trips count
        $tripsCount = $this->tripRepository->count(['agency' => $agency]);
        $activeTripsCount = $this->tripRepository->count([
            'agency' => $agency,
            'status' => 'planifie',
        ]);

        // Get reservations stats
        $reservationsCount = $this->reservationRepository->countReservationsByAgency($agency, $startDate, $endDate);
        $totalRevenue = $this->reservationRepository->getTotalRevenueByAgency($agency, $startDate, $endDate);
        $fillRate = $this->reservationRepository->getAverageFillRateByAgency($agency);
        $cancellationRate = $this->reservationRepository->getCancellationRateByAgency($agency);

        // Get wallet info
        $wallet = $agency->getWallet();
        $balance = $wallet ? (float) $wallet->getTotalEarned() : 0;
        $reservedBalance = $wallet ? (float) $wallet->getReservedBalance() : 0;

        // Get top routes for this agency
        $topRoutes = $this->tripRepository->getTopRoutesByAgency($agency, 5);

        // Get recent reservations
        // Dans AdminAgencyController::stats()
        $recentReservations = $this->reservationRepository->findRecentByAgency($agency, 10);

        return $this->json([
            'success' => true,
            'data' => [
                'general' => [
                    'tripsCount' => $tripsCount,
                    'activeTripsCount' => $activeTripsCount,
                    'reservationsCount' => $reservationsCount,
                    'totalRevenue' => (float) $totalRevenue,
                    'fillRate' => round((float) $fillRate, 2),
                    'cancellationRate' => round((float) $cancellationRate, 2),
                    'rating' => (float) ($agency->getRatingCache() ?? 0),
                ],
                'finance' => [
                    'balance' => $balance,
                    'reservedBalance' => $reservedBalance,
                    'availableBalance' => $balance - $reservedBalance,
                    'commissionRate' => (float) $agency->getCommissionRate(),
                ],
                'topRoutes' => array_map([$this, 'normalizeRoute'], $topRoutes),
                'recentReservations' => array_map([$this, 'normalizeReservation'], $recentReservations),
            ],
        ]);
    }

    /**
     * Get trips for an agency.
     */
    #[Route('/{id}/trips', name: 'api_admin_agency_trips', methods: ['GET'])]
    public function getTrips(int $id, Request $request): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);

        if (!$agency) {
            return $this->json([
                'success' => false,
                'message' => 'Agence introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;
        $status = $request->query->get('status');

        $qb = $this->tripRepository->createQueryBuilder('t')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('t.departureTime', 'DESC');

        if ($status) {
            $statusMap = [
                'SCHEDULED' => ['planifie'],
                'IN_PROGRESS' => ['embarquement', 'en_route'],
                'COMPLETED' => ['termine'],
                'CANCELLED' => ['annule'],
                'DELAYED' => ['annule'],
                'planifie' => ['planifie'],
                'embarquement' => ['embarquement'],
                'en_route' => ['en_route'],
                'termine' => ['termine'],
                'annule' => ['annule'],
            ];

            $resolved = $statusMap[$status] ?? null;
            if ($resolved !== null) {
                if (count($resolved) > 1) {
                    $qb->andWhere('t.status IN (:status)')->setParameter('status', $resolved);
                } else {
                    $qb->andWhere('t.status = :status')->setParameter('status', $resolved[0]);
                }
            }
        }

        $countQb = clone $qb;
        $total = count($countQb->getQuery()->getResult());

        $qb->setFirstResult($offset)->setMaxResults($limit);
        $trips = $qb->getQuery()->getResult();

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeTrip'], $trips),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Get reservations for an agency.
     */
    #[Route('/{id}/reservations', name: 'api_admin_agency_reservations', methods: ['GET'])]
    public function getReservations(int $id, Request $request): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);

        if (!$agency) {
            return $this->json([
                'success' => false,
                'message' => 'Agence introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;
        $status = $request->query->get('status');

        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('r.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('r.paymentStatus = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $total = count($countQb->getQuery()->getResult());

        $qb->setFirstResult($offset)->setMaxResults($limit);
        $reservations = $qb->getQuery()->getResult();

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeReservation'], $reservations),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Get all agents linked to an agency.
     * Includes the agency administrator (agentRole = admin_agence).
     */
    #[Route('/{id}/agents', name: 'api_admin_agency_agents', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getAgents(int $id): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);
        if (!$agency) {
            return $this->json(['success' => false, 'message' => 'Agence introuvable'], Response::HTTP_NOT_FOUND);
        }

        $agents = $this->em->getRepository(Agent::class)->findBy(
            ['agency' => $agency],
            ['id' => 'ASC']
        );

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeAgent'], $agents),
        ]);
    }

    /** Get all buses belonging to an agency. */
    #[Route('/{id}/buses', name: 'api_admin_agency_buses', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getBuses(int $id): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);
        if (!$agency) {
            return $this->json(['success' => false, 'message' => 'Agence introuvable'], Response::HTTP_NOT_FOUND);
        }

        $buses = $this->em->getRepository(Bus::class)->findBy(
            ['agency' => $agency],
            ['id' => 'DESC']
        );

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeBus'], $buses),
        ]);
    }

    /** Get all boarding points belonging to an agency. */
    #[Route('/{id}/boarding-points', name: 'api_admin_agency_boarding_points', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getBoardingPoints(int $id): JsonResponse
    {
        $agency = $this->agencyRepository->find($id);
        if (!$agency) {
            return $this->json(['success' => false, 'message' => 'Agence introuvable'], Response::HTTP_NOT_FOUND);
        }

        $points = $this->em->getRepository(AgencyPoint::class)->findBy(
            ['agency' => $agency],
            ['city' => 'ASC', 'name' => 'ASC']
        );

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeBoardingPoint'], $points),
        ]);
    }

    private function normalizeAgent(Agent $agent): array
    {
        $user = $agent->getUser();
        return [
            'id' => $agent->getId(),
            'userId' => $user?->getId(),
            'name' => $user?->getFullName(),
            'email' => $user?->getEmail(),
            'phone' => $user?->getPhoneNumber(),
            'role' => $agent->getAgentRole(),
            'status' => $agent->getStatus(),
            'commissionRate' => method_exists($agent, 'getCommissionRate') ? (float) $agent->getCommissionRate() : 0,
        ];
    }

    private function normalizeBus(Bus $bus): array
    {
        return [
            'id' => $bus->getId(),
            'registrationNumber' => $bus->getRegistrationNumber(),
            'capacity' => $bus->getCapacity(),
            'brand' => $bus->getBrand(),
            'model' => $bus->getModel(),
            'category' => $bus->getCategory(),
            'status' => $bus->getStatus(),
            'color' => method_exists($bus, 'getColor') ? $bus->getColor() : null,
            'acquisitionDate' => $bus->getAcquisitionDate()?->format('Y-m-d'),
            'lastMaintenanceDate' => $bus->getLastMaintenanceDate()?->format('Y-m-d'),
            'mileage' => $bus->getMileage(),
        ];
    }

    private function normalizeBoardingPoint(AgencyPoint $point): array
    {
        return [
            'id' => $point->getId(),
            'city' => $point->getCity(),
            'name' => $point->getName(),
            'address' => $point->getAddress(),
            'quartier' => $point->getQuartier(),
            'phoneNumber' => $point->getPhone(),
            'latitude' => $point->getLatitude(),
            'longitude' => $point->getLongitude(),
            'pointType' => $point->getPointType(),
            'status' => $point->getStatus(),
            'isActive' => (bool) $point->isActive(),
            'hasVipLounge' => (bool) $point->getHasVipLounge(),
            'hasWifi' => (bool) $point->getHasWifi(),
            'hasAc' => (bool) $point->getHasAc(),
            'hasParking' => (bool) $point->getHasParking(),
            'createdAt' => $point->getCreatedAt()?->format('c'),
        ];
    }

    /**
     * Get agency KYC status distribution.
     */
    #[Route('/kyc-distribution', name: 'api_admin_agencies_kyc_distribution', methods: ['GET'])]
    public function getKycDistribution(): JsonResponse
    {
        $distribution = $this->agencyRepository->getKycStatusDistribution();

        return $this->json([
            'success' => true,
            'data' => $distribution,
        ]);
    }

    /**
     * Get agencies by status.
     */
    #[Route('/by-status', name: 'api_admin_agencies_by_status', methods: ['GET'])]
    public function getByStatus(Request $request): JsonResponse
    {
        $status = $request->query->get('status');

        if (!$status) {
            return $this->json([
                'success' => false,
                'message' => 'Le paramètre status est obligatoire',
            ], Response::HTTP_BAD_REQUEST);
        }

        $agencies = $this->agencyRepository->findBy(['status' => $status], ['createdAt' => 'DESC']);

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeAgency'], $agencies),
        ]);
    }

    /**
     * Normalize agency for JSON response.
     */
    private function normalizeAgency(Agency $agency): array
    {
        // $wallet = $agency->getWallet();

        $adminAgence = $this->em->getRepository(Agent::class)->findOneBy([
            'agency' => $agency,
            'agentRole' => 'admin_agence',
        ]);

        return [
            'id' => $agency->getId(),
            'name' => $agency->getName(),
            'email' => $agency->getEmail(),
            'phone' => $agency->getPhone(),
            'registrationNumber' => $agency->getRegistrationNumber(),
            'address' => $agency->getAddress(),
            'logoUrl' => $agency->getLogoUrl(),
            'bannerUrl' => $agency->getBannerUrl(),
            'websiteUrl' => $agency->getWebsiteUrl(),
            'mapUrl' => $agency->getMapUrl(),
            'description' => $agency->getDescription(),
            'status' => $agency->getStatus(),
            'ratingCache' => $agency->getRatingCache(),
            'commissionRate' => $agency->getCommissionRate(),
            'createdAt' => $agency->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'admin' => $adminAgence ? [
                'id' => $adminAgence->getUser()?->getId(),
                'name' => $adminAgence->getUser()?->getFullName(),
                'email' => $adminAgence->getUser()?->getEmail(),
                'phone' => $adminAgence->getUser()?->getPhoneNumber(),
            ] : null,
        ];
    }

    /**
     * Normalize agency with additional stats.
     */
    private function normalizeAgencyWithStats(Agency $agency): array
    {
        $data = $this->normalizeAgency($agency);

        // Add KYC status
        $documents = $agency->getDocuments();
        $kycStatus = 'missing';
        if ($documents->count() > 0) {
            $hasApproved = false;
            $hasPending = false;
            $hasRejected = false;

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
                $kycStatus = 'rejected';
            } elseif ($hasPending && !$hasApproved) {
                $kycStatus = 'pending';
            } elseif ($hasApproved) {
                $kycStatus = 'verified';
            }
        }

        $data['kyc'] = $kycStatus;

        // Add wallet info
        $wallet = $agency->getWallet();
        if ($wallet) {
            $data['wallet'] = [
                'balance' => (float) $wallet->getAvailableBalance(),
                'reservedBalance' => (float) $wallet->getReservedBalance(),
                'availableBalance' => (float) ($wallet->getAvailableBalance() - $wallet->getReservedBalance()),
                'currency' => 'XAF',
                // 'currency' => $wallet->getCurrency(),
            ];
        }

        // Add operational counts used by the admin agency detail page.
        $data['tripsCount'] = $this->tripRepository->count(['agency' => $agency]);
        $data['reservationsCount'] = $this->reservationRepository->countReservationsByAgency($agency);
        $data['agentsCount'] = $this->em->getRepository(Agent::class)->count(['agency' => $agency]);
        $data['busesCount'] = $this->em->getRepository(Bus::class)->count(['agency' => $agency]);
        $data['boardingPointsCount'] = $this->em->getRepository(AgencyPoint::class)->count(['agency' => $agency]);

        return $data;
    }

    /**
     * Normalize trip for JSON response.
     */
    private function normalizeTrip(Trip $trip): array
    {
        $capacity = $trip->getBus()?->getCapacity() ?? 0;
        $reserved = $trip->getSeatsReserved();

        $tripDate = $trip->getTripDate()?->format('Y-m-d') ?? $trip->getDepartureTime()?->format('Y-m-d');
        $departureTimeOfDay = $trip->getDepartureTimeOfDay()?->format('H:i') ?? $trip->getDepartureTime()?->format('H:i');
        $arrivalTimeOfDay = $trip->getArrivalTimeOfDay()?->format('H:i') ?? $trip->getEstimatedArrivalTime()?->format('H:i');

        return [
            'id' => $trip->getId(),
            'departureCity' => $trip->getDepartureCity(),
            'arrivalCity' => $trip->getArrivalCity(),
            'departureTime' => $trip->getDepartureTime()?->format(\DateTimeInterface::ATOM),
            'estimatedArrivalTime' => $trip->getEstimatedArrivalTime()?->format(\DateTimeInterface::ATOM),
            'tripDate' => $tripDate,
            'departureTimeOfDay' => $departureTimeOfDay,
            'arrivalTimeOfDay' => $arrivalTimeOfDay,
            'price' => (float) $trip->getPrice(),
            'status' => $this->statusMapperService->mapTripStatus($trip->getStatus()),
            'seatsReserved' => $reserved,
            'maxSeats' => $capacity,
            'availableSeats' => max(0, $capacity - $reserved),
            'driverName' => $trip->getDriverName(),
            'busType' => $trip->getBus()?->getModel(),
            'busPlate' => $trip->getBus()?->getRegistrationNumber(),
            'createdAt' => $trip->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Normalize reservation for JSON response.
     */
    private function normalizeReservation(Reservation $reservation): array
    {
        return [
            'id' => $reservation->getId(),
            'reference' => $reservation->getTransactionReference(),
            'totalAmount' => (float) $reservation->getTotalAmount(),
            'paymentMethod' => $reservation->getPaymentMethod(),
            'paymentStatus' => $reservation->getPaymentStatus(),
            'paymentPhone' => $reservation->getPaymentPhone(),
            'createdAt' => $reservation->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'tripId' => $reservation->getTrip()?->getId(),
            'tripRoute' => $reservation->getTrip()?->getDepartureCity() . ' → ' . $reservation->getTrip()?->getArrivalCity(),
            'userId' => $reservation->getUser()?->getId(),
            'userName' => $reservation->getUser()?->getFullName(),
            'userPhone' => $reservation->getUser()?->getPhoneNumber(),
        ];
    }

    /**
     * Normalize route for stats.
     */
    private function normalizeRoute(array $routeData): array
    {
        return [
            'route' => $routeData['route'] ?? '',
            'reservationsCount' => $routeData['reservationCount'] ?? 0,
            'totalRevenue' => (float) ($routeData['totalRevenue'] ?? 0),
            'fillRate' => round((float) ($routeData['fillRate'] ?? 0), 1),
        ];
    }
}
