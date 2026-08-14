<?php

namespace App\Controller\Admin;

use App\Security\AdminRoleVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Agency;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Repository\AgencyRepository;
use App\Repository\ReservationRepository;
use App\Repository\TicketRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use App\Service\StatusMapperService;
use App\Service\TripCancellationService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Admin Trip Controller for Trip Management & Passenger Manifest in Super Admin Dashboard.
 * Provides endpoints for listing, filtering trips, and retrieving trip details with manifest data.
 */
#[Route('/api/admin/trips')]
#[IsGranted(AdminRoleVoter::MODERATION)]
class AdminTripController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private TripRepository $tripRepository,
        private ReservationRepository $reservationRepository,
        private TicketRepository $ticketRepository,
        private AgencyRepository $agencyRepository,
        private UserRepository $userRepository,
        private StatusMapperService $statusMapperService,
        private TripCancellationService $tripCancellationService,
        private AuditLogger $auditLogger,
    ) {}

    private function resolveBackendStatusesForFilter(string $status): array
    {
        $map = [
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

        return $map[$status] ?? [];
    }

    /**
     * Get all trips with optional filtering and pagination.
     * Supports filtering by date range, status, agency, and search keyword.
     */
    #[Route('', name: 'api_admin_trips_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Parse query parameters
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 10);
        $offset = ($page - 1) * $limit;
        
        $status = $request->query->get('status');
        $agencyId = $request->query->get('agency_id');
        $search = $request->query->get('search');
        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');

        // Build query
        $qb = $this->tripRepository->createQueryBuilder('t')
            ->leftJoin('t.agency', 'a')
            ->leftJoin('t.bus', 'b')
            ->leftJoin('t.reservations', 'r')
            ->select('t', 'a', 'b', 'COUNT(r.id) as reservationCount', 'SUM(CASE WHEN r.paymentStatus = :confirmedStatus THEN 1 ELSE 0 END) as confirmedReservations')
            ->groupBy('t.id', 'a.id', 'b.id')
            ->orderBy('t.departureTime', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Apply filters
        if ($status && $status !== 'ALL') {
            $backendStatuses = $this->resolveBackendStatusesForFilter($status);
            if (count($backendStatuses) > 0) {
                if (count($backendStatuses) > 1) {
                    $qb->andWhere('t.status IN (:status)')->setParameter('status', $backendStatuses);
                } else {
                    $qb->andWhere('t.status = :status')->setParameter('status', $backendStatuses[0]);
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

        $qb->setParameter('confirmedStatus', 'paye');

        $trips = $qb->getQuery()->getResult();
        
        // Get total count for pagination
        $countQb = $this->tripRepository->createQueryBuilder('t')
            ->leftJoin('t.agency', 'a')
            ->leftJoin('t.bus', 'b')
            ->select('COUNT(DISTINCT t.id)');

        // Apply same filters as main query
        if ($status && $status !== 'ALL') {
            $backendStatuses = $this->resolveBackendStatusesForFilter($status);
            if (count($backendStatuses) > 0) {
                if (count($backendStatuses) > 1) {
                    $countQb->andWhere('t.status IN (:status)')->setParameter('status', $backendStatuses);
                } else {
                    $countQb->andWhere('t.status = :status')->setParameter('status', $backendStatuses[0]);
                }
            }
        }

        if ($agencyId) {
            $countQb->andWhere('t.agency = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if ($startDate) {
            $countQb->andWhere('t.departureTime >= :startDate')->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $countQb->andWhere('t.departureTime <= :endDate')->setParameter('endDate', $endDate);
        }

        if ($search) {
            $countQb->andWhere('(
                t.departureCity LIKE :search OR
                t.arrivalCity LIKE :search OR
                CONCAT(t.departureCity, \' → \', t.arrivalCity) LIKE :search OR
                a.name LIKE :search OR
                t.driverName LIKE :search OR
                b.registrationNumber LIKE :search
            )')->setParameter('search', '%' . $search . '%');
        }
        
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = ceil($total / $limit);

        // Normalize response
        $data = array_map([$this, 'normalizeTripForList'], $trips);

        return $this->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ]);
    }

    /**
     * Get trip details by ID with full manifest data.
     * Returns comprehensive trip information including passenger manifest.
     */
    #[Route('/{id}', name: 'api_admin_trips_detail', requirements: ['id' => '\d+'],  methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $trip = $this->tripRepository->find($id);

        if (!$trip) {
            return $this->json([
                'success' => false,
                'message' => 'Trajet introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = $this->normalizeTripDetail($trip);

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Cancel a future trip administratively.
     *
     * This uses the same transactional cancellation engine as the agency
     * workflow: paid reservations become refund requests, tickets are
     * invalidated, seats are released, and the operation is audited.
     */
    #[Route('/{id}/cancel', name: 'api_admin_trips_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $trip = $this->tripRepository->find($id);
        if (!$trip) {
            return $this->json(['success' => false, 'message' => 'Voyage introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $actor = $this->getUser();
        if (!$actor instanceof \App\Entity\User) {
            return $this->json(['success' => false, 'message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $reason = trim((string) ($payload['reason'] ?? 'Voyage annulé par l’administration'));
        if ($reason === '') {
            $reason = 'Voyage annulé par l’administration';
        }

        try {
            $before = [
                'status' => $trip->getStatus(),
                'seatsReserved' => $trip->getSeatsReserved(),
            ];

            $result = $this->tripCancellationService->cancel($trip, $actor, $reason);

            $this->auditLogger->record(
                'TRIP_ADMIN_CANCELLED',
                'Trip',
                (string) $id,
                $before,
                [
                    'status' => $result['trip']->getStatus(),
                    'seatsReserved' => $result['trip']->getSeatsReserved(),
                ],
                [
                    'reason' => $reason,
                    'cancelledReservations' => $result['cancelledReservations'],
                    'refundRequests' => $result['refundRequests'],
                    'refundAmountPending' => $result['refundedAmount'],
                    'agencyId' => $trip->getAgency()?->getId(),
                ]
            );

            return $this->json([
                'success' => true,
                'message' => 'Voyage annulé. Les demandes de remboursement ont été transmises au traitement financier.',
                'data' => [
                    'trip' => $this->normalizeTripDetail($result['trip']),
                    'cancelledReservations' => $result['cancelledReservations'],
                    'refundRequests' => $result['refundRequests'],
                    'refundAmountPending' => $result['refundedAmount'],
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => 'Impossible d’annuler ce voyage pour le moment.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get passenger manifest for a specific trip.
     * Returns only the manifest data (passenger list).
     */
    #[Route('/{id}/manifest', name: 'api_admin_trips_manifest', methods: ['GET'])]
    public function manifest(int $id): JsonResponse
    {
        $trip = $this->tripRepository->find($id);

        if (!$trip) {
            return $this->json([
                'success' => false,
                'message' => 'Trajet introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $manifest = $this->getManifestData($trip);

        return $this->json([
            'success' => true,
            'data' => $manifest,
            'counts' => [
                'total' => count($manifest),
                'boarded' => count(array_filter($manifest, fn($p) => $p['status'] === 'BOARDED')),
                'boarding' => count(array_filter($manifest, fn($p) => $p['status'] === 'BOARDING')),
                'noShow' => count(array_filter($manifest, fn($p) => $p['status'] === 'NO_SHOW')),
            ],
        ]);
    }

    /**
     * Get trip KPI statistics.
     */
    #[Route('/kpis', name: 'api_admin_trips_kpis', methods: ['GET'])]
    public function kpis(Request $request): JsonResponse
    {
        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');
        $agencyId = $request->query->get('agency_id');

        $qb = $this->tripRepository->createQueryBuilder('t')
            ->leftJoin('t.agency', 'a')
            ->leftJoin('t.bus', 'b')
            ->leftJoin('t.reservations', 'r');

        if ($agencyId) {
            $qb->andWhere('t.agency = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if ($startDate) {
            $qb->andWhere('t.departureTime >= :startDate')->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('t.departureTime <= :endDate')->setParameter('endDate', $endDate);
        }

        $trips = $qb->getQuery()->getResult();

        $totalTrips = count($trips);
        $totalPassengers = 0;
        $totalRevenue = 0;
        $completedTrips = 0;
        $scheduledTrips = 0;
        $inProgressTrips = 0;

        $backendStatusMap = [
            'termine' => 'COMPLETED',
            'planifie' => 'SCHEDULED',
            'embarquement' => 'IN_PROGRESS',
            'en_route' => 'IN_PROGRESS',
            'annule' => 'CANCELLED'
        ];

        foreach ($trips as $trip) {
            $status = $backendStatusMap[$trip->getStatus()] ?? $trip->getStatus();
            
            if ($status === 'COMPLETED') $completedTrips++;
            if ($status === 'SCHEDULED') $scheduledTrips++;
            if ($status === 'IN_PROGRESS') $inProgressTrips++;

            $totalPassengers += $trip->getSeatsReserved();
            $totalRevenue += $trip->getPrice() * $trip->getSeatsReserved();
        }

        $todayTrips = $this->tripRepository->createQueryBuilder('t')
            ->where('t.departureTime >= :todayStart')
            ->andWhere('t.departureTime <= :todayEnd')
            ->setParameter('todayStart', date('Y-m-d 00:00:00'))
            ->setParameter('todayEnd', date('Y-m-d 23:59:59'))
            ->getQuery()->getResult();

        return $this->json([
            'success' => true,
            'data' => [
                'total' => $totalTrips,
                'scheduled' => $scheduledTrips,
                'inProgress' => $inProgressTrips,
                'completed' => $completedTrips,
                'cancelled' => $totalTrips - $scheduledTrips - $inProgressTrips - $completedTrips,
                'totalPassengers' => $totalPassengers,
                'totalRevenue' => $totalRevenue,
                'todayVolume' => count($todayTrips),
            ],
        ]);
    }

    /**
     * Normalize trip for list response.
     */
    private function normalizeTripForList(array $tripData): array
    {
        $trip = $tripData[0];
        // 👈 CORRECTIF : en hydratation objet Doctrine, les entités jointes 'a'
        // et 'b' sont fusionnées dans l'entité racine 't' (accessibles via ses
        // relations), et n'apparaissent PAS comme clés de tableau séparées.
        // `$tripData['a']`/`$tripData['b']` valaient donc toujours null, et
        // l'agence/le bus s'affichaient systématiquement comme "N/A".
        $agency = $trip->getAgency();
        $bus = $trip->getBus();
        
        $status = $this->statusMapperService->mapTripStatus($trip->getStatus());
        
        $capacity = $bus ? $bus->getCapacity() : 0;
        $bookedSeats = $trip->getSeatsReserved();
        $fillRate = $capacity > 0 ? round(($bookedSeats * 100) / $capacity, 2) : 0;
        
        $departureTime = $trip->getDepartureTime();
        $departureDate = $trip->getTripDate()?->format('Y-m-d') ?? $departureTime?->format('Y-m-d');
        $departureHour = $trip->getDepartureTimeOfDay()?->format('H:i') ?? $departureTime?->format('H:i');
        
        $arrivalTime = $trip->getEstimatedArrivalTime();
        $arrivalHour = $trip->getArrivalTimeOfDay()?->format('H:i') ?? $arrivalTime?->format('H:i');
        
        $price = $trip->getPrice() ? (float) $trip->getPrice() : 0;
        $reservationCount = (int) ($tripData['reservationCount'] ?? 0);
        $revenue = $price * $bookedSeats;

        return [
            'id' => $trip->getId(),
            'ref' => 'TRP-' . str_pad($trip->getId(), 6, '0', STR_PAD_LEFT),
            'route' => $trip->getRoute(),
            'agency' => $agency ? $agency->getName() : 'N/A',
            'agencyId' => $agency ? $agency->getId() : null,
            'date' => $departureDate,
            'tripDate' => $departureDate,
            'departure' => $departureHour,
            'departureTimeOfDay' => $departureHour,
            'arrival' => $arrivalHour,
            'arrivalTimeOfDay' => $arrivalHour,
            'busType' => $bus ? $bus->getModel() : 'N/A',
            'busPlate' => $bus ? $bus->getRegistrationNumber() : 'N/A',
            'driver' => $trip->getDriverName() ?? 'N/A',
            'bookedSeats' => $bookedSeats,
            'totalSeats' => $capacity,
            'fillRate' => $fillRate,
            'revenue' => $revenue,
            'status' => $status,
        ];
    }

    /**
     * Normalize trip detail with manifest data.
     */
    private function normalizeTripDetail(Trip $trip): array
    {
        $agency = $trip->getAgency();
        $bus = $trip->getBus();
        
        $status = $this->statusMapperService->mapTripStatus($trip->getStatus());
        
        $departureTime = $trip->getDepartureTime();
        $departureDate = $trip->getTripDate()?->format('Y-m-d') ?? $departureTime?->format('Y-m-d');
        $departureHour = $trip->getDepartureTimeOfDay()?->format('H:i') ?? $departureTime?->format('H:i');
        
        $arrivalTime = $trip->getEstimatedArrivalTime();
        $arrivalHour = $trip->getArrivalTimeOfDay()?->format('H:i') ?? $arrivalTime?->format('H:i');
        
        $capacity = $bus ? $bus->getCapacity() : 0;
        $bookedSeats = $trip->getSeatsReserved();
        $availableSeats = max(0, $capacity - $bookedSeats);
        $fillRate = $capacity > 0 ? round(($bookedSeats * 100) / $capacity, 2) : 0;
        
        $price = $trip->getPrice() ? (float) $trip->getPrice() : 0;
        $revenue = $price * $bookedSeats;
        $commissionRate = $agency ? (float) $agency->getCommissionRate() : 10.0;
        $commission = $revenue * ($commissionRate / 100);
        
        // Calculate duration
        $duration = 'N/A';
        if ($departureTime && $arrivalTime) {
            $interval = $departureTime->diff($arrivalTime);
            $hours = $interval->h;
            $minutes = $interval->i;
            $duration = $hours . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '');
        }

        return [
            'id' => $trip->getId(),
            'ref' => 'TRP-' . str_pad($trip->getId(), 6, '0', STR_PAD_LEFT),
            'route' => $trip->getRoute(),
            'departureCity' => $trip->getDepartureCity() ?? 'N/A',
            'arrivalCity' => $trip->getArrivalCity() ?? 'N/A',
            'agency' => $agency ? [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
                'phone' => $agency->getPhone() ?? '',
                'city' => $agency->getAddress() ?? '',
            ] : null,
            'date' => $departureDate,
            'tripDate' => $departureDate,
            'departure' => $departureHour,
            'departureTimeOfDay' => $departureHour,
            'arrival' => $arrivalHour,
            'arrivalTimeOfDay' => $arrivalHour,
            'duration' => $duration,
            'busType' => $bus ? $bus->getModel() : 'N/A',
            'busPlate' => $bus ? $bus->getRegistrationNumber() : 'N/A',
            'bus' => $bus ? [
                'id' => $bus->getId(),
                'licensePlate' => $bus->getRegistrationNumber(),
                'model' => $bus->getModel(),
                'capacity' => $bus->getCapacity(),
            ] : null,
            'driver' => $trip->getDriverName() ?? 'N/A',
            'driverPhone' => '' ?? '',
            'totalSeats' => $capacity,
            'bookedSeats' => $bookedSeats,
            'availableSeats' => $availableSeats,
            'price' => $price,
            'revenue' => $revenue,
            'commission' => $commission,
            'fillRate' => $fillRate,
            'status' => $status,
            'boardingPoints' => $this->getBoardingPoints($trip),
            'stops' => $this->getTripStops($trip),
            'manifest' => $this->getManifestData($trip),
            'createdAt' => $trip->getCreatedAt() ? $trip->getCreatedAt()->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Get manifest data for a trip.
     */
    private function getManifestData(Trip $trip): array
    {
        $manifest = [];
        $reservations = $this->reservationRepository->findBy(['trip' => $trip]);
        // $reservations = $trip->getReservations();
        
        foreach ($reservations as $reservation) {
            // Note: Reservation entity doesn't have status field, only tickets have status
            // So we include all reservations and let individual tickets determine passenger status
            $tickets = $reservation->getTickets();
            foreach ($tickets as $ticket) {
                $manifest[] = $this->normalizeManifestPassenger($ticket, $reservation);
            }
        }
        
        return $manifest;
    }

    /**
     * Normalize passenger for manifest.
     */
    private function normalizeManifestPassenger(Ticket $ticket, Reservation $reservation): array
    {
        $statusMap = [
            'en_attente' => 'BOARDING',
            'PENDING' => 'BOARDING',
            'confirmee' => 'BOARDED',
            'CONFIRMED' => 'BOARDED',
            'BOARDED' => 'BOARDED',
            'annulee' => 'NO_SHOW',
            'CANCELLED' => 'NO_SHOW',
            'NO_SHOW' => 'NO_SHOW',
        ];
        
        $backendTicketStatus = $ticket->getStatus();
        $ticketStatus = $statusMap[$backendTicketStatus] ?? $backendTicketStatus;
        
        // Note: boardingPoint field doesn't exist in current Reservation entity
        // Using departure city from trip as default boarding point
        $boardingPoint = '';
        if (method_exists($reservation, 'getBoardingPoint')) {
            $boardingPoint = $reservation->getBoardingPoint() ?? '';
        }
        
        return [
            'id' => $ticket->getId(),
            'name' => $ticket->getPassengerName() ?? 'N/A',
            'phone' => $ticket->getPassengerPhone() ?? '',
            'cni' => $ticket->getPassengerCni() ?? '',
            'seat' => $ticket->getSeatNumber() ?? 'N/A',
            'ticketRef' => $reservation->getId() ?? '',
            'boardingPoint' => $boardingPoint,
            'paymentMethod' => $reservation->getPaymentMethod() ?? 'N/A',
            'amount' => $reservation->getTotalAmount() ?? 0,
            'status' => $ticketStatus,
            'checkedIn' => $ticketStatus === 'BOARDED',
            'qrCodeToken' => $ticket->getQrCodeToken() ?? '',
        ];
    }

    /**
     * Get boarding points for a trip.
     */
    private function getBoardingPoints(Trip $trip): array
    {
        // Note: boardingPoint field doesn't exist in current Reservation entity
        // For now, return departure city as the main boarding point
        $boardingPoints = [];
        $departureCity = $trip->getDepartureCity();
        
        if ($departureCity && !in_array($departureCity, $boardingPoints, true)) {
            $boardingPoints[] = $departureCity;
        }
        
        return $boardingPoints;
    }

    /**
     * Get stops for a trip.
     */
    private function getTripStops(Trip $trip): array
    {
        $stops = [];
        
        // Check if trip has departure city
        $departureCity = $trip->getDepartureCity();
        $arrivalCity = $trip->getArrivalCity();
        $departureTime = $trip->getDepartureTime();
        $arrivalTime = $trip->getEstimatedArrivalTime();
        
        if ($departureCity) {
            $stops[] = [
                'city' => $departureCity,
                'time' => $departureTime ? $departureTime->format('H:i') : '',
                'type' => 'BOARDING',
                'address' => '',
            ];
        }
        
        if ($arrivalCity) {
            $stops[] = [
                'city' => $arrivalCity,
                'time' => $arrivalTime ? $arrivalTime->format('H:i') : '',
                'type' => 'DROPOFF',
                'address' => '',
            ];
        }
        
        return $stops;
    }

    /**
     * Get trip status counts.
     */
    private function getTripStatusCounts(): array
    {
        $statusCounts = [
            'SCHEDULED' => 0,
            'IN_PROGRESS' => 0,
            'COMPLETED' => 0,
            'CANCELLED' => 0,
            'DELAYED' => 0,
        ];
        
        $statusMap = [
            'planifie' => 'SCHEDULED',
            'embarquement' => 'IN_PROGRESS',
            'en_route' => 'IN_PROGRESS',
            'termine' => 'COMPLETED',
            'annule' => 'CANCELLED',
        ];
        
        $trips = $this->tripRepository->findAll();
        foreach ($trips as $trip) {
            $status = $statusMap[$trip->getStatus()] ?? $trip->getStatus();
            $statusCounts[$status]++;
        }
        
        return $statusCounts;
    }
}
