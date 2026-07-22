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
use Doctrine\DBAL\LockMode;
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

    /**
     * Frais de plateforme standardisés, appliqués à chaque réservation.
     * Doit rester alignée avec la valeur affichée côté front (booking-form.page.ts).
     */
    private const SERVICE_FEE = 500;

    /** Nombre maximum de passagers autorisés sur une seule réservation. */
    private const MAX_PASSENGERS_PER_BOOKING = 10;

    public function __construct(private EntityManagerInterface $em, private NotificationBroadcastService $notificationBroadcaster) {}

    #[Route('/api/bookings', name: 'create_booking', methods: ['POST'])]
    public function create(Request $request, ReservationRepository $reservationRepository): JsonResponse
    {
        // --- Authentification obligatoire : une réservation doit toujours être rattachée
        // à un utilisateur connu du serveur, jamais créée "anonyme" ---
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Vous devez être connecté pour réserver.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        $tripId = $data['tripId'] ?? null;
        $passengers = $data['passengers'] ?? [];
        $baggages = $data['baggages'] ?? [];
        // NOTE SÉCURITÉ : `totalPrice` envoyé par le client n'est utilisé que pour
        // information ; le montant réellement facturé est TOUJOURS recalculé
        // ci-dessous à partir du prix du trajet en base (voir $computedTotal).
        // Ne jamais faire confiance à un montant transmis par le front.

        if (!$tripId) {
            return new JsonResponse(['error' => 'tripId is required'], 400);
        }

        if (!is_array($passengers) || count($passengers) === 0) {
            return new JsonResponse(['error' => 'Au moins un passager est requis.'], 400);
        }
        if (count($passengers) > self::MAX_PASSENGERS_PER_BOOKING) {
            return new JsonResponse(['error' => sprintf('Une réservation est limitée à %d passagers.', self::MAX_PASSENGERS_PER_BOOKING)], 400);
        }
        foreach ($passengers as $p) {
            $fullName = trim((string)($p['fullName'] ?? ($p['name'] ?? '')));
            $phone = trim((string)($p['phoneNumber'] ?? ''));
            if ($fullName === '' || $phone === '') {
                return new JsonResponse(['error' => 'Nom complet et numéro de téléphone requis pour chaque passager.'], 400);
            }
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            // Verrou pessimiste sur le trajet : empêche deux réservations concurrentes
            // de dépasser la capacité du bus (race condition sur seatsReserved).
            $trip = $this->em->getRepository(Trip::class)->find($tripId, LockMode::PESSIMISTIC_WRITE);
            if (!$trip) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Trip not found'], 404);
            }

            // --- Règles métier appliquées par les agences de transport ---
            if (in_array($trip->getStatus(), ['annule', 'termine'], true)) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Ce voyage n\'est plus disponible à la réservation.'], 422);
            }
            $departureTime = $trip->getDepartureTime();
            if ($departureTime && $departureTime <= new \DateTime()) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Ce voyage est déjà parti, la réservation est fermée.'], 422);
            }

            $bus = $trip->getBus();
            $capacity = $bus ? $bus->getCapacity() : null;
            $seatsReserved = (int) $trip->getSeatsReserved();
            $seatsRequested = count($passengers);

            if ($capacity !== null && ($seatsReserved + $seatsRequested) > $capacity) {
                $connection->rollBack();
                $remaining = max(0, $capacity - $seatsReserved);
                return new JsonResponse([
                    'error' => $remaining > 0
                        ? sprintf('Il ne reste que %d place(s) disponible(s) sur ce trajet.', $remaining)
                        : 'Ce trajet est complet.',
                    'availableSeats' => $remaining,
                ], 409);
            }

            // --- Prix recalculé côté serveur : la seule source de vérité ---
            $pricePerSeat = (float) $trip->getPrice();
            $computedSubtotal = $pricePerSeat * $seatsRequested;
            $computedTotal = $computedSubtotal + self::SERVICE_FEE;

            $reservation = new Reservation();
            $reservation->setUser($user);
            $reservation->setTrip($trip);
            $reservation->setTotalAmount((string)$computedTotal);

            $paymentPhone = $data['paymentPhone'] ?? (method_exists($user, 'getPhoneNumber') ? $user->getPhoneNumber() : '');
            $reservation->setPaymentPhone($paymentPhone ?: '');

            $allowedMethods = ['MTN_MOMO', 'AIRTEL_MONEY'];
            $paymentMethod = $data['paymentMethod'] ?? null;
            if (!in_array($paymentMethod, $allowedMethods, true)) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Méthode de paiement invalide.'], 400);
            }
            $reservation->setPaymentMethod($paymentMethod);
            $reservation->setTransactionReference(uniqid('txn_', true));

            $this->em->persist($reservation);

            $createdTickets = [];
            foreach ($passengers as $p) {
                $ticket = new Ticket();
                $ticket->setReservation($reservation);
                $ticket->setPassengerName(trim((string)($p['fullName'] ?? ($p['name'] ?? 'Passager'))));
                $ticket->setPassengerPhone(trim((string)($p['phoneNumber'] ?? $paymentPhone ?? '')));
                $ticket->setPassengerCni($p['identityNumber'] ?? 'N/A');

                $seatsReserved++;
                $ticket->setSeatNumber($seatsReserved);

                try {
                    $token = bin2hex(random_bytes(16));
                } catch (\Exception $e) {
                    $token = uniqid('qrtk_', true);
                }
                $ticket->setQrCodeToken($token);

                $this->em->persist($ticket);
                $createdTickets[] = [
                    'seat' => $ticket->getSeatNumber(),
                    'qr' => $ticket->getQrCodeToken(),
                    'ticketNumber' => null, // renseigné après flush() une fois l'id du ticket connu
                ];
            }

            $trip->setSeatsReserved($seatsReserved);
            $this->em->persist($trip);

            foreach ($baggages as $b) {
                $bg = new Baggage();
                $bg->setReservation($reservation);
                if (isset($b['weight'])) {
                    $bg->setWeight((float)$b['weight']);
                }
                $bg->setDescription($b['description'] ?? '');
                $bg->setBaggageType($b['baggageType'] ?? 'Bagage');
                $this->em->persist($bg);
            }

            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($user->getId())
                ->setTitle('Réservation enregistrée')
                ->setContent(sprintf('Votre réservation pour le trajet %s → %s a été enregistrée. En attente de confirmation du paiement.', $trip->getDepartureCity(), $trip->getArrivalCity()))
                ->setCategory('BOOKING');
            $this->em->persist($notification);

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->notificationBroadcaster->broadcast($notification);

        // Complète le numéro de billet maintenant que les tickets ont un id.
        $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
        $ticketsPayload = array_map(fn(Ticket $t) => [
            'seat' => $t->getSeatNumber(),
            'qr' => $t->getStatus() === 'annule' ? null : $t->getQrCodeToken(),
            'ticketNumber' => 'TKT-' . $t->getId(),
            'passengerName' => $t->getPassengerName(),
            'passengerPhone' => $t->getPassengerPhone(),
        ], $tickets);

        $response = [
            'id' => $reservation->getId(),
            'reservationId' => $reservation->getId(),
            'transactionReference' => $reservation->getTransactionReference(),
            'totalAmount' => $reservation->getTotalAmount(),
            'ticketNumber' => count($ticketsPayload) === 1 ? ('TKT-' . $reservation->getId()) : null,
            'tickets' => $ticketsPayload,
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

        // --- IDOR : seul le propriétaire de la réservation peut télécharger son reçu ---
        $user = $this->getUser();
        if (!$reservation->getUser() || !$user instanceof User || $reservation->getUser()->getId() !== $user->getId()) {
            return new Response('Accès refusé.', 403);
        }

        $trip = $reservation->getTrip();
        $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
        $agencyName = $trip?->getAgency()?->getName() ?? 'N/A';
        $busPlate = $trip?->getBus()?->getRegistrationNumber() ?? 'N/A';
        $departureTime = $trip?->getDepartureTime();

        // Reçu enrichi : agence, trajet, date/heure, bus, et un billet par passager.
        $content = "==== Reçu de réservation ====\n";
        $content .= "Agence : {$agencyName}\n";
        $content .= "Réservation N° : {$reservation->getId()}\n";
        $content .= "Référence transaction : " . ($reservation->getTransactionReference() ?? 'N/A') . "\n";
        $content .= "Trajet : " . ($trip?->getDepartureCity() ?? 'N/A') . ' → ' . ($trip?->getArrivalCity() ?? 'N/A') . "\n";
        $content .= "Date de départ : " . ($departureTime ? $departureTime->format('d/m/Y') : 'N/A') . "\n";
        $content .= "Heure de départ : " . ($departureTime ? $departureTime->format('H:i') : 'N/A') . "\n";
        $content .= "Bus : {$busPlate}\n";
        $content .= "Montant total : " . $reservation->getTotalAmount() . " FCFA\n";
        $content .= "\n-- Passagers --\n";
        foreach ($tickets as $t) {
            $content .= sprintf(
                "Billet %s | Siège %s | %s | %s\n",
                'TKT-' . $t->getId(),
                (string)$t->getSeatNumber(),
                $t->getPassengerName(),
                $t->getPassengerPhone(),
            );
        }

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

        // --- IDOR : un client ne doit voir que ses propres réservations ---
        $user = $this->getUser();
        if (!$reservation->getUser() || !$user instanceof User || $reservation->getUser()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => "Vous n'êtes pas autorisé à consulter cette réservation."], 403);
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
        $seatNumbers = $data['seat_numbers'] ?? $data['seatNumbers'] ?? [];

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
        if (!$reservation->getUser() || !$user instanceof User || $reservation->getUser()->getId() !== $user->getId()) {
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
        $refundAmount = (float)$reservation->getTotalAmount() - self::SERVICE_FEE;
        $refundLog = new PaymentLog();
        $refundLog->setReservation($reservation);
        $refundLog->setOperator($reservation->getPaymentMethod() ?? 'N/A');
        $refundLog->setReference(uniqid('refund_', true));
        $refundLog->setAmount((string)$refundAmount);
        $refundLog->setStatus('REFUND_PENDING');
        $refundLog->setRawResponse(json_encode([
            'type' => 'refund_request',
            'reason' => $reason,
            'requested_at' => $now->format('c'),
            // $user est garanti non-null ici grâce à la vérification d'autorisation ci-dessus.
            'requested_by_user_id' => $user->getId(),
            'original_transaction_reference' => $reservation->getTransactionReference(),
        ]));
        $this->em->persist($refundLog);

        // 4) Notification au client
        $notification = new Notification();
        $notification->setRecipientType('user')
            ->setRecipientId($user->getId())
            ->setTitle('Réservation annulée')
            ->setContent(sprintf(
                'Votre réservation pour le trajet %s → %s a été annulée. Le remboursement de %s FCFA est en cours de traitement par notre équipe.',
                $trip->getDepartureCity(),
                $trip->getArrivalCity(),
                $refundAmount,
            ))
            ->setCategory('BOOKING');
        $this->em->persist($notification);

        $this->em->flush();
        $this->notificationBroadcaster->broadcast($notification);

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
        $bus = $trip?->getBus();

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
                'busLicensePlate' => $bus?->getRegistrationNumber() ?? '',
            ] : null,
            'tickets' => array_map(fn($ticket) => [
                'id' => $ticket->getId(),
                'ticketNumber' => 'TKT-' . $ticket->getId(),
                'seatNumber' => $ticket->getSeatNumber(),
                'passengerName' => $ticket->getPassengerName(),
                'passengerPhone' => $ticket->getPassengerPhone(),
                'qrCodeToken' => $ticket->getStatus() === 'annule' ? null : $ticket->getQrCodeToken(),
                'status' => $finalTicketStatus,
            ], $tickets),
            'createdAt' => $reservation->getCreatedAt()?->format('c'),
            'updatedAt' => $reservation->getCreatedAt()?->format('c'),
        ];
    }
}