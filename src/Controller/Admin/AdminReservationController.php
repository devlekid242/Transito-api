<?php

namespace App\Controller\Admin;

use App\Entity\Agency;
use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Repository\PaymentLogRepository;
use App\Repository\ReservationRepository;
use App\Repository\TicketRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Admin Reservation Controller for Reservation Management in Super Admin Dashboard.
 * Provides endpoints for listing, filtering, creating, and managing platform reservations.
 */
#[Route('/api/admin/reservations')]
class AdminReservationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private ReservationRepository $reservationRepository,
        private PaymentLogRepository $paymentLogRepository,
        private UserRepository $userRepository,
        private AgencyRepository $agencyRepository,
        private TripRepository $tripRepository,
        private TicketRepository $ticketRepository,
    ) {}

    /**
     * Get all reservations with optional filtering and pagination.
     * Supports filtering by date range, status, agency, and search keyword.
     */
    #[Route('', name: 'api_admin_reservations_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Parse query parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;

        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');
        $status = $request->query->get('status');
        $agencyId = $request->query->get('agency_id');
        $search = $request->query->get('search', '');
        $sortBy = $request->query->get('sort_by', 'createdAt');
        $sortOrder = $request->query->get('sort_order', 'DESC');

        // Validate sort fields
        $validSortFields = ['createdAt', 'totalAmount', 'paymentStatus'];
        if (!in_array($sortBy, $validSortFields, true)) {
            $sortBy = 'createdAt';
        }

        // Build query
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')
            ->leftJoin('r.trip', 't')
            ->leftJoin('t.agency', 'a')
            ->orderBy("r.{$sortBy}", $sortOrder);

        // Apply filters
        if ($startDate) {
            $startDateTime = new \DateTime($startDate);
            $qb->andWhere('r.createdAt >= :startDate')->setParameter('startDate', $startDateTime);
        }

        if ($endDate) {
            $endDateTime = new \DateTime($endDate);
            $qb->andWhere('r.createdAt <= :endDate')->setParameter('endDate', $endDateTime);
        }

        if ($status) {
            $qb->andWhere('r.paymentStatus = :status')->setParameter('status', $status);
        }

        if ($agencyId) {
            $qb->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if (!empty($search)) {
            $searchParam = '%' . $search . '%';
            $qb->andWhere('(
                r.transactionReference LIKE :search OR
                u.fullName LIKE :search OR
                u.phoneNumber LIKE :search OR
                t.departureCity LIKE :search OR
                t.arrivalCity LIKE :search OR
                a.name LIKE :search
            )')->setParameter('search', $searchParam);
        }

        // Get total count
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        // Get paginated results
        $qb->setFirstResult($offset)->setMaxResults($limit);
        $reservations = $qb->getQuery()->getResult();

        // Normalize reservation data
        $data = array_map([$this, 'normalizeReservationForList'], $reservations);

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
     * Get reservation KPI statistics.
     */
    #[Route('/kpis', name: 'api_admin_reservations_kpis', methods: ['GET'])]
    public function getKpis(Request $request): JsonResponse
    {
        // Parse date range
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');

        // Total reservations
        $total = $this->reservationRepository->count([]);

        // Count by status
        $confirmed = $this->reservationRepository->count(['paymentStatus' => 'paye']);
        $completed = $this->reservationRepository->count(['paymentStatus' => 'termine']);
        $cancelled = $this->reservationRepository->count(['paymentStatus' => 'annule']);
        $noShow = $this->reservationRepository->count(['paymentStatus' => 'no_show']);
        $pending = $this->reservationRepository->count(['paymentStatus' => 'en_attente']);
        $failed = $this->reservationRepository->count(['paymentStatus' => 'echoue']);

        // Reservations today
        $startOfDay = new \DateTime('today');
        $endOfDay = new \DateTime('tomorrow');
        $todayCount = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startOfDay)
            ->setParameter('end', $endOfDay)
            ->getQuery()
            ->getSingleScalarResult();

        // Pending payments (not paye, not annule, not echoue)
        $pendingPayments = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.paymentStatus NOT IN (:statuses)')
            ->setParameter('statuses', ['paye', 'annule', 'echoue'])
            ->getQuery()
            ->getSingleScalarResult();

        // Total revenue (only paid reservations)
        $totalRevenue = $this->reservationRepository->createQueryBuilder('r')
            ->select('SUM(r.totalAmount)')
            ->where('r.paymentStatus = :status')
            ->setParameter('status', 'paye')
            ->getQuery()
            ->getSingleScalarResult();

        // New reservations this week
        $startOfWeek = new \DateTime('this week');
        $newThisWeek = $this->reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt >= :start')
            ->setParameter('start', $startOfWeek)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'confirmed' => $confirmed,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'noShow' => $noShow,
                'pending' => $pending,
                'failed' => $failed,
                'todayVolume' => (int) $todayCount,
                'pendingPayments' => (int) $pendingPayments,
                'totalRevenue' => (float) ($totalRevenue ?? 0),
                'newThisWeek' => (int) $newThisWeek,
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get a single reservation with full details.
     * Includes tickets, trip information, and payment logs.
     */
    #[Route('/{id}', name: 'api_admin_reservations_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $reservation = $this->reservationRepository->find($id);

        if (!$reservation) {
            return $this->json([
                'success' => false,
                'message' => 'Réservation introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = $this->normalizeReservationDetail($reservation);

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Create a new reservation manually on behalf of a client.
     */
    #[Route('', name: 'api_admin_reservations_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['userId']) || !$data['userId']) {
            return $this->json(['success' => false, 'message' => 'L\'utilisateur est obligatoire.'], 400);
        }

        if (!isset($data['tripId']) || !$data['tripId']) {
            return $this->json(['success' => false, 'message' => 'Le trajet est obligatoire.'], 400);
        }

        if (!isset($data['totalAmount']) || $data['totalAmount'] <= 0) {
            return $this->json(['success' => false, 'message' => 'Le montant doit être supérieur à 0.'], 400);
        }

        if (!isset($data['paymentMethod']) || !$data['paymentMethod']) {
            return $this->json(['success' => false, 'message' => 'Le moyen de paiement est obligatoire.'], 400);
        }

        $user = $this->userRepository->find($data['userId']);
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Utilisateur introuvable.'], 404);
        }

        $trip = $this->tripRepository->find($data['tripId']);
        if (!$trip) {
            return $this->json(['success' => false, 'message' => 'Trajet introuvable.'], 404);
        }

        // Check seat availability
        if (isset($data['seatCount']) && $data['seatCount'] > 0) {
            $availableSeats = $this->getAvailableSeatsForTrip($trip);
            if ($data['seatCount'] > $availableSeats) {
                return $this->json([
                    'success' => false,
                    'message' => sprintf('Seulement %d places disponibles pour ce trajet.', $availableSeats),
                ], 400);
            }
        }

        // Create reservation
        $reservation = new Reservation();
        $reservation->setUser($user);
        $reservation->setTrip($trip);
        $reservation->setTotalAmount(number_format($data['totalAmount'], 2, '.', ''));
        $reservation->setPaymentPhone($user->getPhoneNumber() ?? $data['paymentPhone'] ?? '');
        $reservation->setPaymentMethod($this->normalizePaymentMethod($data['paymentMethod']));
        $reservation->setPaymentStatus('en_attente');
        $reservation->setTransactionReference($this->generateTransactionReference());

        $this->em->persist($reservation);
        $this->em->flush();

        // Create tickets if seat count specified
        $seatCount = $data['seatCount'] ?? 1;
        $passengerName = $data['passengerName'] ?? $user->getFullName();
        $passengerPhone = $data['passengerPhone'] ?? $user->getPhoneNumber();
        $passengerCni = $data['passengerCni'] ?? '';

        for ($i = 0; $i < $seatCount; $i++) {
            $ticket = new Ticket();
            $ticket->setReservation($reservation);
            $ticket->setPassengerName($passengerName);
            $ticket->setPassengerPhone($passengerPhone);
            $ticket->setPassengerCni($passengerCni);
            $ticket->setSeatNumber($data['seatNumbers'][$i] ?? null);
            $ticket->setQrCodeToken($this->generateQrCodeToken($reservation->getId(), $i));
            $ticket->setStatus('en_attente');

            $this->em->persist($ticket);
        }

        // Handle payment linking
        $existingPaymentLogId = $data['existingPaymentLogId'] ?? null;
        if ($existingPaymentLogId) {
            $existingPaymentLog = $this->paymentLogRepository->find($existingPaymentLogId);
            if ($existingPaymentLog && !$existingPaymentLog->getReservation()) {
                $this->linkPaymentToReservation($existingPaymentLog, $reservation, $data);
            }
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Réservation créée avec succès',
            'data' => $this->normalizeReservationDetail($reservation),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing reservation.
     */
    #[Route('/{id}', name: 'api_admin_reservations_update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $reservation = $this->reservationRepository->find($id);

        if (!$reservation) {
            return $this->json([
                'success' => false,
                'message' => 'Réservation introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        // Update fields if provided
        if (isset($data['paymentMethod'])) {
            $reservation->setPaymentMethod($this->normalizePaymentMethod($data['paymentMethod']));
        }

        if (isset($data['totalAmount'])) {
            $reservation->setTotalAmount(number_format($data['totalAmount'], 2, '.', ''));
        }

        if (isset($data['paymentStatus'])) {
            $reservation->setPaymentStatus($data['paymentStatus']);
        }

        if (isset($data['tripId'])) {
            $trip = $this->tripRepository->find($data['tripId']);
            if ($trip) {
                $reservation->setTrip($trip);
            }
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Réservation mise à jour avec succès',
            'data' => $this->normalizeReservationDetail($reservation),
        ]);
    }

    /**
     * Cancel a reservation with optional refund.
     */
    #[Route('/{id}/cancel', name: 'api_admin_reservations_cancel', methods: ['PUT', 'PATCH'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $reservation = $this->reservationRepository->find($id);

        if (!$reservation) {
            return $this->json([
                'success' => false,
                'message' => 'Réservation introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Annulation administrative';
        $refund = $data['refund'] ?? false;

        // Check if cancellation is allowed
        if (!$this->canCancel($reservation)) {
            return $this->json([
                'success' => false,
                'message' => 'Impossible d\'annuler cette réservation (déjà terminée ou annulée)',
            ], 400);
        }

        // Update reservation status
        $reservation->setPaymentStatus('annule');

        // Update all tickets
        foreach ($reservation->getTickets() as $ticket) {
            $ticket->setStatus('annule');
        }

        // Handle refund if requested and payment was made
        if ($refund && $reservation->getPaymentStatus() === 'paye') {
            $this->processRefund($reservation, $reason);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Réservation annulée avec succès',
            'data' => $this->normalizeReservationDetail($reservation),
        ]);
    }

    /**
     * Get available trips for an agency (for SearchSelect).
     */
    #[Route('/trips', name: 'api_admin_reservations_trips', methods: ['GET'])]
    public function getTrips(Request $request): JsonResponse
    {
        $agencyId = $request->query->get('agency_id');
        $search = $request->query->get('search', '');
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $qb = $this->tripRepository->createQueryBuilder('t')
            ->leftJoin('t.agency', 'a')
            ->leftJoin('t.bus', 'b')
            ->where('t.departureTime >= :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('t.departureTime', 'ASC')
            ->setMaxResults($limit);

        if ($agencyId) {
            $qb->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if (!empty($search)) {
            $searchParam = '%' . $search . '%';
            $qb->andWhere('(
                t.departureCity LIKE :search OR
                t.arrivalCity LIKE :search OR
                CONCAT(t.departureCity, " -> ", t.arrivalCity) LIKE :search
            )')->setParameter('search', $searchParam);
        }

        $trips = $qb->getQuery()->getResult();

        $data = array_map([$this, 'normalizeTripForSelect'], $trips);

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get users for search (for SearchSelect).
     */
    #[Route('/users', name: 'api_admin_reservations_users', methods: ['GET'])]
    public function getUsers(Request $request): JsonResponse
    {
        $search = $request->query->get('search', '');
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $qb = $this->userRepository->createQueryBuilder('u')
            ->leftJoin('u.admin', 'a')
            ->leftJoin('u.agent', 'ag')
            // 👈 CORRECTIF : `u.roles LIKE '%ROLE_USER%'` ne filtrait rien, car la
            // colonne `roles` persistée vaut toujours ["ROLE_USER"] pour tout le
            // monde (les rôles ROLE_ADMIN/ROLE_AGENT sont dérivés à la volée dans
            // User::getRoles(), jamais stockés). On exclut donc explicitement les
            // comptes qui ont un Admin ou un Agent associé, pour ne garder que les
            // vrais clients dans ce sélecteur de réservation.
            ->andWhere('a.id IS NULL AND ag.id IS NULL')
            ->orderBy('u.fullName', 'ASC')
            ->setMaxResults($limit);

        if (!empty($search)) {
            $searchParam = '%' . $search . '%';
            $qb->andWhere('(
                u.fullName LIKE :search OR
                u.phoneNumber LIKE :search OR
                u.email LIKE :search
            )')->setParameter('search', $searchParam);
        }

        $users = $qb->getQuery()->getResult();

        $data = array_map([$this, 'normalizeUserForSelect'], $users);

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get unlinked payment logs for a user (for payment linking).
     */
    #[Route('/users/{userId}/unlinked-payments', name: 'api_admin_reservations_user_unlinked_payments', methods: ['GET'])]
    public function getUnlinkedPayments(int $userId): JsonResponse
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return $this->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $payments = $this->paymentLogRepository->createQueryBuilder('pl')
            ->where('pl.reservation IS NULL')
            ->andWhere('pl.status = :status')
            ->andWhere('pl.user = :userId')
            ->setParameter('status', 'SUCCESS')
            ->setParameter('userId', $userId)
            ->orderBy('pl.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $data = array_map([$this, 'normalizePaymentLogForSelect'], $payments);

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get trip details by ID for form autofill.
     */
    #[Route('/trips/{id}', name: 'api_admin_reservations_trip_detail', methods: ['GET'])]
    public function getTripDetail(int $id): JsonResponse
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

    // ========================================================================
    // PRIVATE HELPER METHODS
    // ========================================================================

    /**
     * Normalize reservation for list response.
     */
    private function normalizeReservationForList(Reservation $reservation): array
    {
        $trip = $reservation->getTrip();
        $user = $reservation->getUser();
        $agency = $trip ? $trip->getAgency() : null;

        $route = 'Inconnue';
        if ($trip) {
            $departureCity = $trip->getDepartureCity() ?? 'N/A';
            $arrivalCity = $trip->getArrivalCity() ?? 'N/A';
            $route = $departureCity !== 'N/A' && $arrivalCity !== 'N/A' 
                ? $departureCity . ' → ' . $arrivalCity 
                : 'Inconnue';
        }

        $departureTime = 'N/A';
        if ($trip && $trip->getDepartureTime()) {
            $departureTime = $trip->getDepartureTime()->format('H:i');
        }

        return [
            'id' => $reservation->getId(),
            'reference' => $reservation->getTransactionReference() ?? 'N/A',
            'user' => $user ? [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'phoneNumber' => $user->getPhoneNumber(),
            ] : null,
            'trip' => $trip ? [
                'id' => $trip->getId(),
                'route' => $route,
                'date' => $trip->getDepartureTime() ? $trip->getDepartureTime()->format('Y-m-d') : null,
                'departure' => $departureTime,
            ] : null,
            'agency' => $agency ? [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
            ] : null,
            'totalAmount' => (float) ($reservation->getTotalAmount() ?? 0),
            'seats' => $reservation->getTickets()->count(),
            'paymentMethod' => $this->formatPaymentMethod($reservation->getPaymentMethod()),
            'paymentStatus' => $this->normalizePaymentStatus($reservation->getPaymentStatus()),
            'status' => $this->normalizeReservationStatus($reservation->getPaymentStatus()),
            'createdAt' => $reservation->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'ticketsCount' => $reservation->getTickets()->count(),
        ];
    }

    /**
     * Normalize reservation detail with full information.
     */
    private function normalizeReservationDetail(Reservation $reservation): array
    {
        $trip = $reservation->getTrip();
        $user = $reservation->getUser();
        $agency = $trip ? $trip->getAgency() : null;

        // Route
        $route = 'Inconnue';
        $departureTime = 'N/A';
        $departureDate = 'N/A';
        if ($trip) {
            $departureCity = $trip->getDepartureCity() ?? 'N/A';
            $arrivalCity = $trip->getArrivalCity() ?? 'N/A';
            $route = $departureCity !== 'N/A' && $arrivalCity !== 'N/A' 
                ? $departureCity . ' → ' . $arrivalCity 
                : 'Inconnue';
            
            if ($trip->getDepartureTime()) {
                $departureTime = $trip->getDepartureTime()->format('H:i');
                $departureDate = $trip->getDepartureTime()->format('Y-m-d');
            }
        }

        // Tickets
        $tickets = [];
        foreach ($reservation->getTickets() as $ticket) {
            $tickets[] = [
                'id' => $ticket->getId(),
                'passengerName' => $ticket->getPassengerName(),
                'passengerPhone' => $ticket->getPassengerPhone(),
                'passengerCni' => $ticket->getPassengerCni(),
                'seatNumber' => $ticket->getSeatNumber(),
                'qrCodeToken' => $ticket->getQrCodeToken(),
                'status' => $this->normalizeTicketStatus($ticket->getStatus()),
            ];
        }

        // Payment logs
        $paymentLogs = $this->paymentLogRepository->findBy(['reservation' => $reservation]);
        $paymentLogsData = array_map([$this, 'normalizePaymentLog'], $paymentLogs);

        return [
            'id' => $reservation->getId(),
            'reference' => $reservation->getTransactionReference() ?? 'N/A',
            'user' => $user ? [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'phoneNumber' => $user->getPhoneNumber(),
                'email' => $user->getEmail(),
                'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ] : null,
            'trip' => $trip ? [
                'id' => $trip->getId(),
                'route' => $route,
                'date' => $departureDate,
                'departure' => $departureTime,
                'bus' => $trip->getBus() ? [
                    'id' => $trip->getBus()->getId(),
                    'licensePlate' => $trip->getBus()->getRegistrationNumber(),
                    'capacity' => $trip->getBus()->getCapacity(),
                ] : null,
            ] : null,
            'agency' => $agency ? [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
                'phone' => $agency->getPhone(),
                'email' => $agency->getEmail(),
            ] : null,
            'totalAmount' => (float) ($reservation->getTotalAmount() ?? 0),
            'paymentPhone' => $reservation->getPaymentPhone(),
            'paymentMethod' => $this->formatPaymentMethod($reservation->getPaymentMethod()),
            'paymentStatus' => $this->normalizePaymentStatus($reservation->getPaymentStatus()),
            'status' => $this->normalizeReservationStatus($reservation->getPaymentStatus()),
            'createdAt' => $reservation->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'tickets' => $tickets,
            'paymentLogs' => $paymentLogsData,
            'ticketsCount' => count($tickets),
        ];
    }

    /**
     * Normalize trip for search select.
     */
    private function normalizeTripForSelect(Trip $trip): array
    {
        $departureCity = $trip->getDepartureCity() ?? 'N/A';
        $arrivalCity = $trip->getArrivalCity() ?? 'N/A';
        $route = $departureCity !== 'N/A' && $arrivalCity !== 'N/A' 
            ? $departureCity . ' → ' . $arrivalCity 
            : 'Inconnue';

        $departureTime = $trip->getDepartureTime() ? $trip->getDepartureTime()->format('Y-m-d H:i') : 'N/A';
        $agency = $trip->getAgency();

        return [
            'id' => $trip->getId(),
            'label' => $route,
            'sublabel' => $departureTime . ' · ' . ($agency ? $agency->getName() : 'N/A'),
            'departureTime' => $departureTime,
            'agencyId' => $agency ? $agency->getId() : null,
            'agencyName' => $agency ? $agency->getName() : null,
        ];
    }

    /**
     * Normalize user for search select.
     */
    private function normalizeUserForSelect(User $user): array
    {
        return [
            'id' => $user->getId(),
            'label' => $user->getFullName(),
            'sublabel' => $user->getPhoneNumber() . ($user->getEmail() ? ' · ' . $user->getEmail() : ''),
            'phoneNumber' => $user->getPhoneNumber(),
            'email' => $user->getEmail(),
        ];
    }

    /**
     * Normalize payment log for select.
     */
    private function normalizePaymentLogForSelect(PaymentLog $paymentLog): array
    {
        return [
            'id' => $paymentLog->getId(),
            'label' => 'Paiement #' . $paymentLog->getId() . ' - ' . $paymentLog->getAmount() . ' FCFA',
            'sublabel' => $paymentLog->getOperator() . ' · ' . $paymentLog->getReference() . ' · ' . $paymentLog->getCreatedAt()?->format('Y-m-d H:i'),
            'amount' => (float) $paymentLog->getAmount(),
            'operator' => $paymentLog->getOperator(),
            'reference' => $paymentLog->getReference(),
            'createdAt' => $paymentLog->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Normalize payment log for detail.
     */
    private function normalizePaymentLog(PaymentLog $paymentLog): array
    {
        return [
            'id' => $paymentLog->getId(),
            'operator' => $paymentLog->getOperator(),
            'reference' => $paymentLog->getReference(),
            'amount' => (float) $paymentLog->getAmount(),
            'status' => $this->normalizeTransactionStatus($paymentLog->getStatus()),
            'createdAt' => $paymentLog->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Link existing payment log to reservation.
     */
    private function linkPaymentToReservation(PaymentLog $paymentLog, Reservation $reservation, array $data): void
    {
        $paymentLog->setReservation($reservation);
        $paymentLog->setStatus('SUCCESS');

        // Update reservation payment info
        $reservation->setPaymentStatus('paye');
        $reservation->setPaymentMethod($paymentLog->getOperator());
        
        if (!$reservation->getTransactionReference()) {
            $reservation->setTransactionReference($paymentLog->getReference());
        }

        $this->em->persist($paymentLog);
        $this->em->persist($reservation);
    }

    /**
     * Process refund for cancelled reservation.
     */
    private function processRefund(Reservation $reservation, string $reason): void
    {
        // Create refund record in payment logs
        $paymentLog = new PaymentLog();
        $paymentLog->setReservation($reservation);
        $paymentLog->setAmount('-' . $reservation->getTotalAmount());
        $paymentLog->setOperator('REFUND');
        $paymentLog->setReference('REFUND-' . $reservation->getTransactionReference());
        $paymentLog->setStatus('SUCCESS');
        $paymentLog->setRawResponse($reason);

        $this->em->persist($paymentLog);
    }

    /**
     * Check if reservation can be cancelled.
     */
    private function canCancel(Reservation $reservation): bool
    {
        $status = strtolower($reservation->getPaymentStatus() ?? '');
        
        // Cannot cancel if already cancelled, completed, or failed
        $cannotCancelStatuses = ['annule', 'annulée', 'termine', 'completee', 'completée', 'echoue', 'failed'];
        
        return !in_array($status, $cannotCancelStatuses, true);
    }

    /**
     * Get available seats for a trip.
     */
    private function getAvailableSeatsForTrip(Trip $trip): int
    {
        $bus = $trip->getBus();
        if (!$bus) {
            return 0;
        }

        $totalSeats = $bus->getCapacity();
        $bookedSeats = $this->ticketRepository->countPendingForTrip($trip);

        return max(0, $totalSeats - $bookedSeats);
    }

    /**
     * Generate transaction reference.
     */
    private function generateTransactionReference(): string
    {
        return 'TKT-' . strtoupper(uniqid());
    }

    /**
     * Generate QR code token.
     */
    private function generateQrCodeToken(int $reservationId, int $index): string
    {
        return 'QR-' . $reservationId . '-' . $index . '-' . strtoupper(uniqid());
    }

    /**
     * Normalize reservation status.
     */
    private function normalizeReservationStatus(?string $status): string
    {
        if (!$status) return 'PENDING';

        $statusMap = [
            'en_attente' => 'PENDING',
            'confirmed' => 'CONFIRMED',
            'confirmee' => 'CONFIRMED',
            'paye' => 'CONFIRMED',
            'payée' => 'CONFIRMED',
            'completee' => 'COMPLETED',
            'completée' => 'COMPLETED',
            'termine' => 'COMPLETED',
            'terminée' => 'COMPLETED',
            'cancelled' => 'CANCELLED',
            'annulee' => 'CANCELLED',
            'annulée' => 'CANCELLED',
            'no_show' => 'NO_SHOW',
            'echoue' => 'FAILED',
            'échouée' => 'FAILED',
            'remboursee' => 'REFUNDED',
            'remboursée' => 'REFUNDED',
        ];

        return $statusMap[strtolower($status)] ?? strtoupper($status);
    }

    /**
     * Normalize payment status.
     */
    private function normalizePaymentStatus(?string $status): string
    {
        if (!$status) return 'PENDING';

        $statusMap = [
            'en_attente' => 'PENDING',
            'pending' => 'PENDING',
            'paye' => 'PAID',
            'payée' => 'PAID',
            'success' => 'PAID',
            'réussi' => 'PAID',
            'reussi' => 'PAID',
            'annule' => 'CANCELLED',
            'annulée' => 'CANCELLED',
            'echoue' => 'FAILED',
            'échouée' => 'FAILED',
            'remboursee' => 'REFUNDED',
            'remboursée' => 'REFUNDED',
        ];

        return $statusMap[strtolower($status)] ?? strtoupper($status);
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
            'remboursé' => 'REFUNDED',
            'remboursee' => 'REFUNDED',
        ];

        return $statusMap[strtolower($status)] ?? strtoupper($status);
    }

    /**
     * Normalize ticket status.
     */
    private function normalizeTicketStatus(?string $status): string
    {
        if (!$status) return 'PENDING';

        $statusMap = [
            'en_attente' => 'PENDING',
            'embarque' => 'BOARDED',
            'embarqué' => 'BOARDED',
            'annule' => 'CANCELLED',
            'annulé' => 'CANCELLED',
            'annulée' => 'CANCELLED',
        ];

        return $statusMap[strtolower($status)] ?? strtoupper($status);
    }

    /**
     * Format payment method for display.
     */
    private function formatPaymentMethod(?string $method): string
    {
        if (!$method) return 'N/A';

        $methodMap = [
            'MTN_MOMO' => 'MTN Mobile Money',
            'AIRTEL_MONEY' => 'Airtel Money',
            'WAVE' => 'Wave',
            'ORANGE_MONEY' => 'Orange Money',
            'CARTE_BANCAIRE' => 'Carte bancaire',
            'ESPECES' => 'Espèces',
        ];

        return $methodMap[$method] ?? $method;
    }

    /**
     * Normalize payment method from frontend to backend format.
     */
    private function normalizePaymentMethod(string $method): string
    {
        $methodMap = [
            'Wave' => 'WAVE',
            'Orange Money' => 'ORANGE_MONEY',
            'MTN Mobile Money' => 'MTN_MOMO',
            'Airtel Money' => 'AIRTEL_MONEY',
            'Carte bancaire' => 'CARTE_BANCAIRE',
            'Espèces' => 'ESPECES',
        ];

        return $methodMap[$method] ?? strtoupper(str_replace(' ', '_', $method));
    }

    /**
     * Normalize trip detail for form autofill.
     */
    private function normalizeTripDetail(Trip $trip): array
    {
        $agency = $trip->getAgency();
        $bus = $trip->getBus();
        $departureCity = $trip->getDepartureCity() ?? 'N/A';
        $arrivalCity = $trip->getArrivalCity() ?? 'N/A';
        $route = $departureCity !== 'N/A' && $arrivalCity !== 'N/A' 
            ? $departureCity . ' → ' . $arrivalCity 
            : 'Inconnue';

        $departureTime = null;
        $departureDate = null;
        if ($trip->getDepartureTime()) {
            $departureTime = $trip->getDepartureTime()->format('H:i');
            $departureDate = $trip->getDepartureTime()->format('Y-m-d');
        }

        return [
            'id' => $trip->getId(),
            'route' => $route,
            'departureCity' => $departureCity,
            'arrivalCity' => $arrivalCity,
            'departureTime' => $departureTime,
            'departureDate' => $departureDate,
            'agency' => $agency ? [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
            ] : null,
            'bus' => $bus ? [
                'id' => $bus->getId(),
                'licensePlate' => $bus->getRegistrationNumber(),
                'capacity' => $bus->getCapacity(),
            ] : null,
            'price' => $trip->getPrice() ? (float) $trip->getPrice() : null,
        ];
    }
}