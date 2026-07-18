<?php

namespace App\Controller;

use App\Entity\Baggage;
use App\Entity\Notification;
use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Service\NotificationBroadcastService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;
use App\Repository\ReservationRepository;

class BookingController extends AbstractController
{
    /**
     * Nombre d'heures minimum requises entre "maintenant" et le départ du voyage
     * pour qu'une annulation soit encore autorisée.
     */
    private const CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE = 24;

    public function __construct(private EntityManagerInterface $em, private NotificationBroadcastService $notificationBroadcaster) {}

    #[Route('/api/bookings', name: 'create_booking', methods: ['POST'])]
    public function create(Request $request, ReservationRepository $reservationRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        // Check for duplicate booking based on user and trip

        $user = $this->getUser();
        $tripId = $data['tripId'] ?? null;



        // Required fields from frontend BookingRequest
        $tripId = $data['tripId'] ?? null;
        $passengers = $data['passengers'] ?? [];
        $baggages = $data['baggages'] ?? [];
        $totalPrice = $data['totalPrice'] ?? 0;

        if (!$tripId) {
            return new JsonResponse(['error' => 'tripId is required'], 400);
        }

        $trip = $this->em->getRepository(Trip::class)->find($tripId);
        if (!$trip) {
            return new JsonResponse(['error' => 'Trip not found'], 404);
        }

        // chech if there is stell place disponible.

        $reservation = new Reservation();
        $user = $this->getUser();
        if ($user instanceof User) {
            $reservation->setUser($user);
        }
        $reservation->setTrip($trip);
        $reservation->setTotalAmount((string)$totalPrice);

        // payment info - try multiple possible incoming keys
        $paymentPhone = $data['paymentPhone'] ?? $data['buyersPhone'] ?? ($user && method_exists($user, 'getPhoneNumber') ? $user->getPhoneNumber() : '');
        $reservation->setPaymentPhone($paymentPhone ?: '');
        $reservation->setPaymentMethod($data['paymentMethod'] ?? 'MTN_MOMO');

        // generate a transaction reference for tracking
        $reservation->setTransactionReference(uniqid('txn_', true));

        $this->em->persist($reservation);

        // Create tickets for each passenger
        $createdTickets = [];
        $seatsReserved = $trip->getSeatsReserved();
        foreach ($passengers as $p) {
            $ticket = new Ticket();
            $ticket->setReservation($reservation);
            $ticket->setPassengerName($p['fullName'] ?? ($p['name'] ?? 'Passager'));
            $ticket->setPassengerPhone($p['phoneNumber'] ?? $paymentPhone ?? '');
            $ticket->setPassengerCni($p['identityNumber'] ?? 'N/A');

            // assign next seat number
            $seatsReserved++;
            $ticket->setSeatNumber((int)$seatsReserved);

            // unique QR token
            try {
                $token = bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                $token = uniqid('qrtk_', true);
            }
            $ticket->setQrCodeToken($token);

            $this->em->persist($ticket);
            $createdTickets[] = ['seat' => $ticket->getSeatNumber(), 'qr' => $ticket->getQrCodeToken()];
        }

        // update trip seats reserved
        $trip->setSeatsReserved((int)$seatsReserved);
        $this->em->persist($trip);

        // Create baggage records
        foreach ($baggages as $b) {
            $bg = new Baggage();
            $bg->setReservation($reservation);
            if (isset($b['weight'])) $bg->setWeight((float)$b['weight']);
            $bg->setDescription($b['description'] ?? '');
            $bg->setBaggageType($b['baggageType'] ?? 'Bagage');
            $this->em->persist($bg);
        }

        if ($user instanceof User) {
            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($user->getId())
                ->setTitle('Réservation enregistrée')
                ->setContent(sprintf('Votre réservation pour le trajet %s → %s a été enregistrée. En attente de confirmation du paiement.', $trip->getDepartureCity(), $trip->getArrivalCity()))
                ->setCategory('BOOKING');
            $this->em->persist($notification);
        }

        $this->em->flush();

        if (isset($notification)) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        $response = [
            'id' => $reservation->getId(),
            'reservationId' => $reservation->getId(),
            'transactionReference' => $reservation->getTransactionReference(),
            'totalAmount' => $reservation->getTotalAmount(),
            'ticketNumber' => count($createdTickets) === 1 ? ('TKT-' . $reservation->getId()) : null,
            'tickets' => $createdTickets
        ];

        return new JsonResponse($response, 201);
    }

    #[Route('/api/bookings/{id}/receipt', name: 'booking_receipt', methods: ['GET'])]
    public function bookingReceipt(int $id): Response
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);
        if (!$reservation) {
            return new Response('Not found', 404);
        }

        // Minimal PDF-like placeholder content (simple text as PDF placeholder)
        $content = "Receipt for reservation #{$reservation->getId()}\n";
        $content .= "Passenger: " . ($this->em->getRepository(Ticket::class)->findOneBy(['reservation' => $reservation])?->getPassengerName() ?? 'N/A') . "\n";
        $content .= "Amount: " . $reservation->getTotalAmount() . "\n";
        $content .= "Trip ID: " . ($reservation->getTrip()?->getId() ?? 'N/A') . "\n";

        return new Response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="receipt_reservation_%d.pdf"', $reservation->getId()),
        ]);
    }

    #[Route('/api/bookings/{id}', name: 'booking_detail', methods: ['GET'])]
    public function getBookingDetail(int $id): JsonResponse
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);
        if (!$reservation) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        return new JsonResponse($this->mapReservation($reservation), 200);
    }

    #[Route('/api/bookings/my-bookings', name: 'my_bookings', methods: ['GET'])]
    public function myBookings(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse([], 200);
        }
        $reservations = $this->em->getRepository(Reservation::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);
        $data = array_map([$this, 'mapReservation'], $reservations);
        return new JsonResponse($data, 200);
    }

    #[Route('/api/bookings/active', name: 'active_bookings', methods: ['GET'])]
    public function activeBookings(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([], 200);
        }

        $qb = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->join('r.user', 'u')
            ->join('r.trip', 't')
            ->where('u.id = :uid')
            ->andWhere('t.departureTime > :now')
            ->andWhere('r.paymentStatus != :cancelled')
            ->setParameter('uid', $user->getId())
            ->setParameter('now', new \DateTime())
            ->setParameter('cancelled', 'rembourse')
            ->orderBy('t.departureTime', 'ASC');

        $reservations = $qb->getQuery()->getResult();
        $data = array_map([$this, 'mapReservation'], $reservations);
        return new JsonResponse($data, 200);
    }

    #[Route('/api/bookings/history', name: 'booking_history', methods: ['GET'])]
    public function bookingHistory(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse([], 200);
        }

        $reservations = $this->em->getRepository(Reservation::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);
        $data = array_map([$this, 'mapReservation'], $reservations);
        return new JsonResponse($data, 200);
    }

    #[Route('/api/bookings/validate-seats', name: 'validate_seats', methods: ['POST'])]
    public function validateSeats(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $tripId = $data['trip_id'] ?? $data['tripId'] ?? null;
        $seatNumbers = $data['seat_numbers'] ?? $data['seatNumbers'] ?? $data['seatNumbers'] ?? [];

        if (!$tripId || !is_array($seatNumbers)) {
            return new JsonResponse(['error' => 'trip_id and seat_numbers are required'], 400);
        }

        $trip = $this->em->getRepository(Trip::class)->find($tripId);
        if (!$trip) return new JsonResponse(['error' => 'Trip not found'], 404);

        $bus = $trip->getBus();
        $capacity = $bus ? $bus->getCapacity() : null;

        // fetch already taken seats for this trip
        $qb = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->select('t.seatNumber')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.id = :trip')
            ->setParameter('trip', $tripId);
        $results = $qb->getQuery()->getArrayResult();
        $taken = array_map(fn($r) => (int)$r['seatNumber'], $results);

        $requested = [];
        $allAvailable = true;
        foreach ($seatNumbers as $s) {
            $num = (int)$s;
            $available = true;
            $reason = null;
            if ($capacity !== null && $num > $capacity) {
                $available = false;
                $reason = 'exceeds_capacity';
            } elseif (in_array($num, $taken, true)) {
                $available = false;
                $reason = 'already_taken';
            }
            if (!$available) $allAvailable = false;
            $requested[] = ['seat' => $num, 'available' => $available, 'reason' => $reason];
        }

        return new JsonResponse([
            'tripId' => (int)$tripId,
            'capacity' => $capacity,
            'takenSeats' => $taken,
            'requested' => $requested,
            'allAvailable' => $allAvailable
        ], 200);
    }

    /**
     * Annulation d'une réservation par le client.
     *
     * Règles métier :
     *  - Seul le propriétaire de la réservation (ou un admin) peut l'annuler.
     *  - Impossible d'annuler une réservation déjà annulée/remboursée.
     *  - L'annulation n'est autorisée que jusqu'à 24h avant le départ du voyage.
     *
     * Effets, dans une seule transaction :
     *  1) Reservation.paymentStatus -> 'rembourse'
     *  2) Tous les Ticket liés -> status 'annule' (invalides, non scannables)
     *  3) Un PaymentLog de type remboursement est créé avec le statut
     *     'REFUND_PENDING' afin que l'équipe administrative soit informée
     *     et puisse déclencher le remboursement réel (cf. PaymentController::refund()).
     *  4) Une notification est envoyée au client.
     */
    #[Route('/api/bookings/{id}/cancel', name: 'cancel_booking', methods: ['POST'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);
        if (!$reservation) {
            return new JsonResponse(['error' => 'Réservation introuvable.'], 404);
        }

        // --- Autorisation : seul le propriétaire de la réservation peut l'annuler ---
        $user = $this->getUser();
        if ($reservation->getUser() && (!$user instanceof User || $reservation->getUser()->getId() !== $user->getId())) {
            return new JsonResponse(['error' => "Vous n'êtes pas autorisé à annuler cette réservation."], 403);
        }

        // --- Réservation déjà annulée/remboursée : opération idempotente-safe ---
        if ($reservation->getPaymentStatus() === 'rembourse') {
            return new JsonResponse(['error' => 'Cette réservation a déjà été annulée.'], 409);
        }

        // --- Règle des 24h avant le départ ---
        $trip = $reservation->getTrip();
        $departureTime = $trip?->getDepartureTime();
        if (!$departureTime) {
            return new JsonResponse(['error' => "Impossible de déterminer l'heure de départ de ce voyage."], 422);
        }

        $now = new \DateTime();
        $hoursBeforeDeparture = ($departureTime->getTimestamp() - $now->getTimestamp()) / 3600;

        if ($hoursBeforeDeparture < self::CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE) {
            return new JsonResponse([
                'error' => sprintf(
                    "L'annulation n'est possible que jusqu'à %dh avant l'embarquement.",
                    self::CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE,
                ),
                'hoursRemaining' => max(0, round($hoursBeforeDeparture, 1)),
            ], 422);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reason = trim((string)($data['reason'] ?? '')) ?: "Annulation à l'initiative du client";

        // 1) Réservation -> remboursée / annulée
        $reservation->setPaymentStatus('rembourse');
        $this->em->persist($reservation);

        // 2) Tous les billets liés -> annulés (invalides, plus scannables/validables)
        $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
        foreach ($tickets as $ticket) {
            $ticket->setStatus('annule');
            $this->em->persist($ticket);
        }

        // 3) Transaction de remboursement à traiter par l'administration
        $refundLog = new PaymentLog();
        $refundLog->setReservation($reservation);
        $refundLog->setOperator($reservation->getPaymentMethod() ?? 'N/A');
        $refundLog->setReference(uniqid('refund_', true));
        $refundLog->setAmount($reservation->getTotalAmount() - 500 ?? '0');
        $refundLog->setStatus('REFUND_PENDING');
        $refundLog->setRawResponse(json_encode([
            'type' => 'refund_request',
            'reason' => $reason,
            'requested_at' => $now->format('c'),
            'requested_by_user_id' => $reservation->getUser()->getId(),
            'original_transaction_reference' => $reservation->getTransactionReference(),
        ]));
        $this->em->persist($refundLog);

        // 4) Notification au client
        if ($reservation->getUser()) {
            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($reservation->getUser()->getId())
                ->setTitle('Réservation annulée')
                ->setContent(sprintf(
                    'Votre réservation pour le trajet %s → %s a été annulée. Le remboursement de %s est en cours de traitement par notre équipe.',
                    $trip->getDepartureCity(),
                    $trip->getArrivalCity(),
                    $reservation->getTotalAmount() - 500,
                ))
                ->setCategory('BOOKING');
            $this->em->persist($notification);
        }

        $this->em->flush();

        if (isset($notification)) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        return new JsonResponse([
            'ok' => true,
            'reservationId' => $reservation->getId(),
            'paymentStatus' => $reservation->getPaymentStatus(),
            'ticketsCancelled' => count($tickets),
            'refund' => [
                'reference' => $refundLog->getReference(),
                'status' => $refundLog->getStatus(),
                'amount' => $refundLog->getAmount(),
            ],
            'message' => 'Réservation annulée. Le remboursement est en cours de traitement par notre équipe.',
        ], 200);
    }

    private function mapReservation(Reservation $reservation): array
    {
        $trip = $reservation->getTrip();
        $user = $reservation->getUser();
        $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
        $seatNumbers = array_map(fn($ticket) => (string)$ticket->getSeatNumber(), $tickets);
        $passengerName = $tickets[0]?->getPassengerName() ?? '';
        $passengerPhone = $tickets[0]?->getPassengerPhone() ?? $reservation->getPaymentPhone();
        $passengerEmail = $user?->getEmail() ?? '';
        $ticketStatus = $tickets[0]?->getStatus();

        $status = 'En attente';
        if ($reservation->getPaymentStatus() === 'rembourse') {
            // Une réservation annulée reste "Annulé" même si le voyage est déjà passé.
            $status = 'Annulé';
        } elseif ($trip->getDepartureTime() < new \DateTime()) {
            $status = 'Expiré';
        } else {
            switch ($reservation->getPaymentStatus()) {
                case 'paye':
                    $status = 'Confirmé';
                    break;
                case 'echoue':
                    $status = 'Annulé';
                    break;
            }
        }

        $finalTicketStatus = '';
        switch ($ticketStatus) {
            case 'en_attente':
                $finalTicketStatus = 'En attente';
                break;
            case 'embarque':
                $finalTicketStatus = 'Utilisé';
                break;
            case 'annule':
                $finalTicketStatus = 'Annulé';
                break;
        }

        $departureTime = $trip?->getDepartureTime();
        $departureDate = $departureTime ? $departureTime->format('Y-m-d') : null;

        return [
            'id' => $reservation->getId(),
            'tripId' => $trip?->getId(),
            'userId' => $user?->getId(),
            'passengerName' => $passengerName,
            'passengerEmail' => $passengerEmail,
            'passengerPhone' => $passengerPhone,
            'seatNumber' => implode(', ', $seatNumbers),
            'totalPrice' => (float)$reservation->getTotalAmount(),
            'status' => $status,
            'canCancel' => $status !== 'Annulé' && $status !== 'Expiré' && $trip && $trip->getDepartureTime() && (($trip->getDepartureTime()->getTimestamp() - (new \DateTime())->getTimestamp()) / 3600) > self::CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE,
            'bookingDate' => $departureTime ? $departureTime->format('c') : $reservation->getCreatedAt()?->format('c'),
            'trip' => $trip ? [
                'id' => $trip->getId(),
                'departureCity' => $trip->getDepartureCity() ?? $trip->getDeparturePoint()?->getCity() ?? '',
                'arrivalCity' => $trip->getArrivalCity() ?? $trip->getArrivalPoint()?->getCity() ?? '',
                'departureTime' => $departureTime ? $departureTime->format('c') : null,
                'arrivalTime' => ($trip->getEstimatedArrivalTime()?->format('c')) ?: null,
                'departureDate' => $departureDate,
                'agencyId' => $trip->getAgency()?->getId(),
                'agencyName' => $trip->getAgency()?->getName(),
                'pricePerSeat' => (float)$trip->getPrice(),
            ] : null,
            'tickets' => array_map(fn($ticket) => [
                'id' => $ticket->getId(),
                'seatNumber' => $ticket->getSeatNumber(),
                'qrCodeToken' => $ticket->getStatus() === 'annule' ? null : $ticket->getQrCodeToken(),
                'status' => $finalTicketStatus,
            ], $tickets),
            'createdAt' => $reservation->getCreatedAt()?->format('c'),
            'updatedAt' => $reservation->getCreatedAt()?->format('c'),
        ];
    }
}