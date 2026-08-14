<?php

namespace App\Controller;

use App\Entity\Baggage;
use App\Entity\Notification;
use App\Entity\PaymentLog;
use App\Entity\RefundRequest;
use App\Entity\Reservation;
use App\Entity\ReservationReschedule;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Service\DomainStateTransitionService;
use App\Service\AdminNotificationService;
use App\Service\AuditLogger;
use App\Service\NotificationBroadcastService;
use App\Service\WalletService;
use App\Service\RescheduleQuoteService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Repository\ReservationRepository;

class BookingController extends AbstractController
{
    /**
     * Nombre d'heures minimum requises entre "maintenant" et le départ du voyage
     * pour qu'une annulation soit encore autorisée.
     */
    private const CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE = 24;

    /**
     * Nombre maximum de passagers autorisés sur une seule réservation.
     */

    /** Nombre maximum de passagers autorisés sur une seule réservation. */
    private const MAX_PASSENGERS_PER_BOOKING = 10;

    
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationBroadcastService $notificationBroadcaster,
        private WalletService $walletService,
        private DomainStateTransitionService $stateTransitions,
        private AuditLogger $auditLogger,
        private RescheduleQuoteService $rescheduleQuoteService,
        #[Autowire('%env(int:PAYMENT_RESERVATION_TTL_MINUTES)%')] private int $paymentReservationTtlMinutes,
    ) {}

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
        $requestedSeatNumbers = $data['seatNumbers'] ?? $data['seat_numbers'] ?? null;
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
            if ($trip->getStatus() !== 'planifie') {
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
            $seatsRequested = count($passengers);

            if ($capacity === null || $capacity < 1) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'La capacité du bus est invalide.'], 422);
            }

            // Source de vérité des places : les billets encore actifs.
            // seatsReserved reste un compteur de cache/lecture, mais ne décide
            // plus seul si une place est libre.
            $occupiedTickets = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
                ->join('t.reservation', 'r')
                ->andWhere('r.trip = :trip')
                // Une réservation impayée dont le TTL est dépassé ne doit plus
                // bloquer une place, même si le cron d'expiration n'a pas encore tourné.
                ->andWhere('(r.paymentStatus = :paidStatus OR (r.paymentStatus = :pendingStatus AND (r.paymentExpiresAt IS NULL OR r.paymentExpiresAt > :now)))')
                ->andWhere('t.status IN (:ticketStatuses)')
                ->setParameter('trip', $trip)
                ->setParameter('paidStatus', 'paye')
                ->setParameter('pendingStatus', 'en_attente')
                ->setParameter('now', new \DateTime())
                ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
                ->getQuery()->getResult();
            $occupiedSeats = [];
            foreach ($occupiedTickets as $occupiedTicket) {
                $seat = $occupiedTicket->getSeatNumber();
                if ($seat !== null) {
                    $occupiedSeats[(int) $seat] = true;
                }
            }

            $seatNumbers = [];
            if (is_array($requestedSeatNumbers) && count($requestedSeatNumbers) > 0) {
                if (count($requestedSeatNumbers) !== $seatsRequested) {
                    $connection->rollBack();
                    return new JsonResponse(['error' => 'Le nombre de sièges doit correspondre au nombre de passagers.'], 422);
                }
                foreach ($requestedSeatNumbers as $rawSeat) {
                    $seat = (int) $rawSeat;
                    if ($seat < 1 || $seat > $capacity) {
                        $connection->rollBack();
                        return new JsonResponse(['error' => sprintf('Le siège %d est invalide.', $seat)], 422);
                    }
                    if (isset($seatNumbers[$seat]) || isset($occupiedSeats[$seat])) {
                        $connection->rollBack();
                        return new JsonResponse(['error' => sprintf('Le siège %d n’est plus disponible.', $seat)], 409);
                    }
                    $seatNumbers[$seat] = $seat;
                }
                $seatNumbers = array_values($seatNumbers);
            } else {
                for ($seat = 1; $seat <= $capacity && count($seatNumbers) < $seatsRequested; ++$seat) {
                    if (!isset($occupiedSeats[$seat])) {
                        $seatNumbers[] = $seat;
                    }
                }
                if (count($seatNumbers) !== $seatsRequested) {
                    $connection->rollBack();
                    return new JsonResponse([
                        'error' => 'Ce trajet ne dispose pas de suffisamment de places.',
                        'availableSeats' => count($seatNumbers),
                    ], 409);
                }
            }
            $seatsReserved = count($occupiedSeats) + $seatsRequested;

            // --- Prix recalculé côté serveur : aucune valeur financière fournie
            // par le client n'est utilisée. Tous les calculs restent en BCMath. ---
            $pricePerSeat = (string) $trip->getPrice();
            $computedSubtotal = bcmul($pricePerSeat, (string) $seatsRequested, 2);
            $platformFee = number_format(WalletService::PLATFORM_FEE, 2, '.', '');
            $computedTotal = bcadd($computedSubtotal, $platformFee, 2);
            $netSettlement = $computedSubtotal;

            $reservation = new Reservation();
            $reservation->setUser($user);
            $reservation->setTrip($trip);
            $reservation->setTotalAmount($computedTotal);

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
            // Une réservation impayée réserve temporairement les sièges.
            // Sans expiration, un abandon du paiement bloquait définitivement
            // la capacité du voyage. 15 minutes est la durée de réservation
            // temporaire avant libération automatique.
            $reservation->setPaymentExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d minutes', max(1, $this->paymentReservationTtlMinutes))));

            $this->em->persist($reservation);

            $createdTickets = [];
            foreach ($passengers as $index => $p) {
                $ticket = new Ticket();
                $ticket->setReservation($reservation);
                $ticket->setPassengerName(trim((string)($p['fullName'] ?? ($p['name'] ?? 'Passager'))));
                $ticket->setPassengerPhone(trim((string)($p['phoneNumber'] ?? $paymentPhone ?? '')));
                $ticket->setPassengerCni($p['identityNumber'] ?? 'N/A');

                $ticket->setSeatNumber($seatNumbers[$index]);

                // Le règlement agence est déterminé dès la création du billet.
                // Cela évite toute nouvelle division lors du paiement/embarquement.
                $ticketCount = count($passengers);
                $baseSettlement = bcdiv($netSettlement, (string) $ticketCount, 2);
                $settlement = ($index === $ticketCount - 1)
                    ? bcsub($netSettlement, bcmul($baseSettlement, (string) max(0, $ticketCount - 1), 2), 2)
                    : $baseSettlement;
                $ticket->setSettlementAmount($settlement);

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

            // 👈 NOUVEAU : jusqu'ici, seul le client était notifié d'une
            // nouvelle réservation — l'agence n'avait aucun moyen de savoir
            // en temps réel qu'un siège venait d'être pris sur un de ses
            // trajets, en dehors d'un rafraîchissement manuel du dashboard.
            $agencyNotification = null;
            if ($trip->getAgency()) {
                $agencyNotification = new Notification();
                $agencyNotification->setRecipientType('agency_all')
                    ->setRecipientId($trip->getAgency()->getId())
                    ->setTitle('Nouvelle réservation')
                    ->setContent(sprintf(
                        '%d place(s) réservée(s) sur le trajet %s → %s du %s.',
                        $seatsRequested,
                        $trip->getDepartureCity(),
                        $trip->getArrivalCity(),
                        $trip->getDepartureTime()?->format('d/m/Y à H:i') ?? '',
                    ))
                    ->setCategory('BOOKING');
                $this->em->persist($agencyNotification);
            }

            $this->em->flush();
            $this->auditLogger->record('BOOKING_CREATED', 'Reservation', (string) $reservation->getId(), null, [
                'paymentStatus' => $reservation->getPaymentStatus(),
                'totalAmount' => $reservation->getTotalAmount(),
                'tripId' => $trip->getId(),
                'ticketCount' => $seatsRequested,
            ], ['source' => 'booking.create']);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->notificationBroadcaster->broadcast($notification);
        if ($agencyNotification) {
            $this->notificationBroadcaster->broadcast($agencyNotification);
        }

        $this->adminNotificationService->notifyEvent(
            'Nouvelle réservation',
            sprintf('Une réservation a été créée pour %s → %s.', $trip->getDepartureCity(), $trip->getArrivalCity()),
            'BOOKING',
            ['reservationId' => $reservation->getId(), 'tripId' => $trip->getId(), 'userId' => $user->getId()]
        );

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
            ->andWhere('r.paymentStatus IN (:activeStatuses)')
            ->setParameter('uid', $user->getId())
            ->setParameter('now', new \DateTime())
            ->setParameter('activeStatuses', ['en_attente', 'paye'])
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
        if ($trip->getStatus() !== 'planifie' || !$trip->getDepartureTime() || $trip->getDepartureTime() <= new \DateTime()) {
            return new JsonResponse(['error' => "Ce voyage n'est plus disponible à la réservation."], 422);
        }

        $bus = $trip->getBus();
        $capacity = $bus ? $bus->getCapacity() : null;

        // 👈 CORRIGÉ : cette requête ne filtrait ni les billets 'annule' ni les
        // réservations 'rembourse' — un siège libéré par annulation restait
        // donc marqué "already_taken" indéfiniment, avec exactement le même
        // symptôme que le bug de seatsReserved jamais décrémenté (voir cancel()).
        $qb = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->select('t.seatNumber')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr')
            ->where('tr.id = :trip')
            ->andWhere('t.status IN (:ticketStatuses)')
            ->andWhere('(r.paymentStatus = :paidStatus OR (r.paymentStatus = :pendingStatus AND (r.paymentExpiresAt IS NULL OR r.paymentExpiresAt > :now)))')
            ->setParameter('trip', $tripId)
            ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
            ->setParameter('paidStatus', 'paye')
            ->setParameter('pendingStatus', 'en_attente')
            ->setParameter('now', new \DateTime());
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
     * Annulation d'une réservation par le client (ou un admin, en support).
     *
     * Règles métier :
     *  - Le propriétaire de la réservation OU un ROLE_ADMIN peut l'annuler.
     *  - Impossible d'annuler une réservation déjà annulée/remboursée.
     *  - L'annulation n'est autorisée que jusqu'à 24h avant le départ du
     *    voyage — SAUF si le trajet lui-même a été annulé par l'agence, auquel
     *    cas le client doit toujours pouvoir se faire rembourser sans limite
     *    de délai (ce n'est plus de son fait).
     *
     * Effets, dans une seule transaction (verrou pessimiste sur le trajet,
     * comme dans create(), pour éviter toute race condition sur seatsReserved) :
     *  1) Reservation.paymentStatus -> 'rembourse'
     *  2) Tous les Ticket liés -> status 'annule' (invalides, non scannables)
     *  3) 👈 CORRIGÉ : trip.seatsReserved décrémenté du nombre de billets
     *     annulés — c'était l'écart symétrique à create() qui manquait
     *     totalement, et qui faisait qu'un trajet ne libérait jamais ses
     *     places après une annulation.
     *  4) 👈 CORRIGÉ : un PaymentLog de remboursement n'est créé QUE si la
     *     réservation avait réellement été payée (paymentStatus === 'paye').
     *     Avant, un remboursement était demandé même pour une réservation
     *     jamais payée (en_attente), ce qui n'a pas de sens et polluait la
     *     file d'attente de remboursements admin.
     *  5) Une notification est envoyée au client (et à l'agence).
     */
    #[Route('/api/bookings/{id}/reschedule/quote', name: 'reschedule_booking_quote', methods: ['POST'])]
    public function rescheduleQuote(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return new JsonResponse(['error' => 'Authentification requise.'], 401);
        $reservation = $this->em->getRepository(Reservation::class)->find($id);
        if (!$reservation || $reservation->getUser()?->getId() !== $user->getId()) return new JsonResponse(['error' => 'Réservation introuvable.'], 404);
        $data = json_decode($request->getContent(), true) ?? [];
        try {
            $quote = $this->rescheduleQuoteService->quote($reservation, (int) ($data['trip_id'] ?? 0), $data['seatNumbers'] ?? $data['seat_numbers'] ?? null);
            return new JsonResponse([
                'success' => true,
                'quote' => [
                    'reservationId' => $reservation->getId(),
                    'fromTripId' => $quote['fromTrip']->getId(),
                    'toTripId' => $quote['newTrip']->getId(),
                    'ticketCount' => count($quote['tickets']),
                    'oldTotal' => $quote['oldTotal'],
                    'newTotal' => $quote['newTotal'],
                    'difference' => $quote['difference'],
                    'direction' => $quote['direction'],
                    'seatNumbers' => $quote['seatNumbers'],
                    'expiresAt' => (new \DateTimeImmutable('+10 minutes'))->format('c'),
                ],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/bookings/{id}/reschedule/initiate', name: 'reschedule_booking_initiate', methods: ['POST'])]
    public function initiateReschedule(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return new JsonResponse(['error' => 'Authentification requise.'], 401);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $reservation = $this->em->getRepository(Reservation::class)->find($id, LockMode::PESSIMISTIC_WRITE);
            if (!$reservation || $reservation->getUser()?->getId() !== $user->getId()) { $connection->rollBack(); return new JsonResponse(['error' => 'Réservation introuvable.'], 404); }
            $data = json_decode($request->getContent(), true) ?? [];
            $quote = $this->rescheduleQuoteService->quote($reservation, (int) ($data['trip_id'] ?? 0), $data['seatNumbers'] ?? $data['seat_numbers'] ?? null);
            $existing = $this->em->getRepository(ReservationReschedule::class)->findOneBy(['reservation' => $reservation]);
            if ($existing && $existing->getStatus() !== ReservationReschedule::STATUS_FAILED) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Un report est déjà en cours ou a déjà été effectué.'], 409);
            }

            $adjustment = (new ReservationReschedule())
                ->setReservation($reservation)
                ->setFromTrip($quote['fromTrip'])
                ->setToTrip($quote['newTrip'])
                ->setOldTotal($quote['oldTotal'])
                ->setNewTotal($quote['newTotal'])
                ->setDifference($quote['difference'])
                ->setDirection($quote['direction'])
                ->setRequestedSeats($quote['seatNumbers'])
                ->setQuoteExpiresAt(new \DateTimeImmutable('+10 minutes'));

            if ($quote['direction'] === 'PAYMENT') {
                $adjustment->setStatus(ReservationReschedule::STATUS_PAYMENT_PENDING);
                $this->em->persist($adjustment);
                $this->em->flush();
                $intent = new \App\Entity\PaymentIntent();
                $intent->setUser($user)->setReservation($reservation)->setReschedule($adjustment)
                    ->setPurpose(\App\Entity\PaymentIntent::PURPOSE_RESCHEDULE)
                    ->setAmount($quote['difference'])
                    ->setOperator($reservation->getPaymentMethod() ?? 'MTN_MOMO');
                $this->em->persist($intent);
                $this->em->flush();
                $connection->commit();
                return new JsonResponse(['success' => true, 'status' => 'PAYMENT_REQUIRED', 'transactionId' => $intent->getReference(), 'amount' => $quote['difference'], 'rescheduleId' => $adjustment->getId()], 201);
            }

            if ($quote['direction'] === 'REFUND') {
                $adjustment->setStatus(ReservationReschedule::STATUS_REFUND_REQUIRED);
                $this->em->persist($adjustment);
                $this->em->flush();

                // A cheaper destination trip creates a refund request for the
                // DIFFERENCE only. It is explicitly linked to the reschedule
                // so AdminRefundController cannot accidentally refund the
                // complete original reservation amount.
                $refundAmount = bcsub('0.00', $quote['difference'], 2);
                $refundRequest = new RefundRequest();
                $refundRequest->setAgency($quote['fromTrip']->getAgency());
                $refundRequest->setReservation($reservation);
                $refundRequest->setReschedule($adjustment);
                $refundRequest->setRequestedBy($user);
                $refundRequest->setRequestedAmount($refundAmount);
                $refundRequest->setReason('Remboursement de la différence de prix suite à un report de voyage.');
                $this->em->persist($refundRequest);
                $this->em->flush();

                $connection->commit();
                return new JsonResponse([
                    'success' => true,
                    'status' => 'REFUND_REQUIRED',
                    'refundAmount' => $refundAmount,
                    'refundRequestId' => $refundRequest->getId(),
                    'rescheduleId' => $adjustment->getId(),
                    'message' => 'La différence doit être remboursée avant la finalisation du report.',
                ], 202);
            }

            $adjustment->setStatus(ReservationReschedule::STATUS_READY);
            $this->em->persist($adjustment);
            $this->em->flush();
            $this->rescheduleQuoteService; // keep service wiring explicit
            $connection->commit();
            return new JsonResponse(['success' => true, 'status' => 'READY', 'rescheduleId' => $adjustment->getId()], 201);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/bookings/{id}/reschedule', name: 'reschedule_booking', methods: ['POST'])]
    public function reschedule(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentification requise.'], 401);
        }

        $reservation = $this->em->getRepository(Reservation::class)->find($id);
        if (!$reservation || $reservation->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Réservation introuvable.'], 404);
        }

        if ($reservation->getPaymentStatus() !== 'paye') {
            return new JsonResponse(['error' => 'Seule une réservation payée peut être reportée.'], 422);
        }
        if ($reservation->getRescheduleCount() >= 1) {
            return new JsonResponse(['error' => 'Cette réservation a déjà été reportée une fois.'], 409);
        }

        $fromTrip = $reservation->getTrip();
        $fromDeparture = $fromTrip?->getDepartureTime();
        if (!$fromTrip || !$fromDeparture) {
            return new JsonResponse(['error' => 'Voyage actuel invalide.'], 422);
        }

        $hoursRemaining = ($fromDeparture->getTimestamp() - time()) / 3600;
        if ($hoursRemaining < self::CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE) {
            return new JsonResponse([
                'error' => 'Le report doit être demandé au moins 24h avant le départ.',
                'hoursRemaining' => max(0, round($hoursRemaining, 1)),
            ], 422);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $newTripId = (int) ($data['trip_id'] ?? 0);
        $requestedSeats = $data['seatNumbers'] ?? $data['seat_numbers'] ?? null;
        if ($requestedSeats !== null && !is_array($requestedSeats)) {
            return new JsonResponse(['error' => 'seatNumbers doit être un tableau.'], 422);
        }
        if ($requestedSeats !== null) {
            $requestedSeats = array_values(array_unique(array_map('intval', $requestedSeats)));
            if (count($requestedSeats) === 0) {
                return new JsonResponse(['error' => 'La liste des sièges demandés ne peut pas être vide.'], 422);
            }
        }
        if ($newTripId <= 0 || $newTripId === $fromTrip->getId()) {
            return new JsonResponse(['error' => 'Veuillez sélectionner un nouveau voyage.'], 422);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            /** @var Reservation|null $lockedReservation */
            $lockedReservation = $this->em->getRepository(Reservation::class)->find($reservation->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedReservation) {
                throw new \RuntimeException('Réservation introuvable.');
            }
            if ($lockedReservation->getRescheduleCount() >= 1) {
                throw new \RuntimeException('Cette réservation a déjà été reportée une fois.');
            }
            /** @var Trip|null $lockedFromTrip */
            $lockedFromTrip = $this->em->getRepository(Trip::class)->find($fromTrip->getId(), LockMode::PESSIMISTIC_WRITE);
            /** @var Trip|null $newTrip */
            $newTrip = $this->em->getRepository(Trip::class)->find($newTripId, LockMode::PESSIMISTIC_WRITE);

            if (!$newTrip || $newTrip->getStatus() !== 'planifie') {
                throw new \RuntimeException('Le nouveau voyage n’est plus disponible.');
            }
            if ($newTrip->getAgency()?->getStatus() !== 'active') {
                throw new \RuntimeException('L’agence du nouveau voyage n’est pas active.');
            }
            if (!$newTrip->getDepartureTime() || $newTrip->getDepartureTime()->getTimestamp() <= time() || $newTrip->getDepartureTime()->getTimestamp() < time() + (24 * 3600)) {
                throw new \RuntimeException('Le nouveau voyage doit également être prévu au moins 24h avant son départ.');
            }
            if ($newTrip->getAgency()?->getId() !== $fromTrip->getAgency()?->getId()) {
                throw new \RuntimeException('Le report doit rester auprès de la même agence.');
            }
            if (bccomp((string) $newTrip->getPrice(), (string) $fromTrip->getPrice(), 2) !== 0) {
                throw new \RuntimeException('Le tarif du nouveau voyage est différent. Le report automatique est impossible.');
            }

            $allTickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
            $tickets = array_values(array_filter(
                $allTickets,
                static fn (Ticket $ticket) => in_array($ticket->getStatus(), ['en_attente', 'embarque'], true)
            ));
            $ticketCount = count($tickets);
            if ($ticketCount < 1) {
                throw new \RuntimeException('Cette réservation ne contient plus de billet actif à reporter.');
            }
            if ($requestedSeats !== null && count($requestedSeats) !== $ticketCount) {
                throw new \RuntimeException(sprintf('Vous devez sélectionner exactement %d siège(s).', $ticketCount));
            }

            $capacity = $newTrip->getBus()?->getCapacity() ?? 0;

            // seatsReserved est un cache. Pour le report, la vérité métier est
            // la liste des billets encore actifs du voyage cible.
            $occupied = $this->em->getRepository(Ticket::class)
                ->createQueryBuilder('t')
                ->join('t.reservation', 'r')
                ->andWhere('r.trip = :trip')
                ->andWhere('r.paymentStatus IN (:statuses)')
                ->andWhere('t.status IN (:ticketStatuses)')
                ->setParameter('trip', $newTrip)
                ->setParameter('statuses', ['en_attente', 'paye'])
                ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
                ->getQuery()->getResult();
            $occupiedSeats = [];
            foreach ($occupied as $occupiedTicket) {
                if ($occupiedTicket->getSeatNumber() !== null) {
                    $occupiedSeats[(int) $occupiedTicket->getSeatNumber()] = true;
                }
            }
            if ($capacity < 1 || (count($occupiedSeats) + $ticketCount) > $capacity) {
                throw new \RuntimeException('Le nouveau voyage ne dispose pas de suffisamment de places.');
            }

            // Allocation : le client peut demander des sièges précis ; sinon le
            // moteur attribue les premiers sièges disponibles.
            if ($requestedSeats !== null) {
                foreach ($requestedSeats as $seat) {
                    if ($seat < 1 || $seat > $capacity) {
                        throw new \RuntimeException(sprintf('Le siège %d n’existe pas sur ce véhicule.', $seat));
                    }
                    if (isset($occupiedSeats[$seat])) {
                        throw new \RuntimeException(sprintf('Le siège %d vient déjà d’être pris.', $seat));
                    }
                }
                $freeSeats = $requestedSeats;
            } else {
                $freeSeats = [];
                for ($seat = 1; $seat <= $capacity && count($freeSeats) < $ticketCount; ++$seat) {
                    if (!isset($occupiedSeats[$seat])) {
                        $freeSeats[] = $seat;
                    }
                }
            }
            if (count($freeSeats) !== $ticketCount || count(array_unique($freeSeats)) !== $ticketCount) {
                throw new \RuntimeException('Impossible d’attribuer les places du nouveau voyage.');
            }

            foreach ($tickets as $index => $ticket) {
                $ticket->setSeatNumber($freeSeats[$index]);
                $this->em->persist($ticket);
            }

            if ($lockedFromTrip) {
                // Recalcule le cache source après avoir déplacé les billets,
                // au lieu de faire confiance à son ancienne valeur.
                $remainingSource = $this->em->getRepository(Ticket::class)
                    ->createQueryBuilder('t')
                    ->select('COUNT(t.id)')
                    ->join('t.reservation', 'r')
                    ->andWhere('r.trip = :trip')
                    ->andWhere('r.id != :reservation')
                    ->andWhere('r.paymentStatus IN (:statuses)')
                    ->andWhere('t.status IN (:ticketStatuses)')
                    ->setParameter('trip', $lockedFromTrip)
                    ->setParameter('reservation', $reservation->getId())
                    ->setParameter('statuses', ['en_attente', 'paye'])
                    ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
                    ->getQuery()->getSingleScalarResult();
                $lockedFromTrip->setSeatsReserved((int) $remainingSource);
                $this->em->persist($lockedFromTrip);
            }
            $newTrip->setSeatsReserved(count($occupiedSeats) + $ticketCount);
            $this->em->persist($newTrip);

            $history = (new ReservationReschedule())
                ->setReservation($reservation)
                ->setFromTrip($fromTrip)
                ->setToTrip($newTrip);

            $reservation->setTrip($newTrip)
                ->incrementRescheduleCount()
                ->setLastRescheduledAt(new \DateTimeImmutable());
            $this->em->persist($history);
            $this->em->persist($reservation);
            $this->em->flush();
            $this->auditLogger->record('BOOKING_RESCHEDULED', 'Reservation', (string) $reservation->getId(),
                ['tripId' => $fromTrip->getId(), 'rescheduleCount' => $reservation->getRescheduleCount() - 1],
                ['tripId' => $newTrip->getId(), 'rescheduleCount' => $reservation->getRescheduleCount()],
                ['source' => 'booking.reschedule', 'fromTripId' => $fromTrip->getId(), 'toTripId' => $newTrip->getId()]
            );
            $this->em->flush();

            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($user->getId())
                ->setTitle('Voyage reporté')
                ->setContent(sprintf(
                    'Votre réservation #%d a été reportée vers le voyage du %s.',
                    $reservation->getId(),
                    $newTrip->getDepartureTime()?->format('d/m/Y à H:i') ?? ''
                ))
                ->setCategory('BOOKING')
                ->setPayload([
                    'reservationId' => $reservation->getId(),
                    'fromTripId' => $fromTrip->getId(),
                    'toTripId' => $newTrip->getId(),
                    'seats' => $freeSeats,
                ]);
            $this->em->persist($notification);
            $this->em->flush();

            $connection->commit();

            $this->notificationBroadcaster->broadcast($notification);

            return new JsonResponse([
                'success' => true,
                'message' => 'Votre voyage a été reporté avec succès.',
                'reservationId' => $reservation->getId(),
                'tripId' => $newTrip->getId(),
                'rescheduleCount' => $reservation->getRescheduleCount(),
            ]);
        } catch (\Throwable $e) {
            $connection->rollBack();
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/api/bookings/{id}/cancel', name: 'cancel_booking', methods: ['POST'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);
        if (!$reservation) {
            return new JsonResponse(['error' => 'Réservation introuvable.'], 404);
        }

        // --- Autorisation : propriétaire de la réservation OU admin ---
        // 👈 CORRIGÉ : le docblock promettait un accès admin qui n'existait
        // pas dans le code — un admin ne pouvait pas annuler pour un client
        // en cas de litige/support.
        /** @var User|null $user */
        $user = $this->getUser();
        $isOwner = $reservation->getUser() && $user instanceof User && $reservation->getUser()->getId() === $user->getId();
        $isAdmin = $user instanceof User && $this->isGranted('ROLE_ADMIN');
        if (!$isOwner && !$isAdmin) {
            return new JsonResponse(['error' => "Vous n'êtes pas autorisé à annuler cette réservation."], 403);
        }

        // --- Réservation déjà annulée/remboursée : opération idempotente-safe ---
        if ($reservation->getPaymentStatus() === 'rembourse') {
            return new JsonResponse(['error' => 'Cette réservation a déjà été annulée.'], 409);
        }

        // --- 👈 NOUVEAU : blocage absolu si au moins un billet a déjà été
        // embarqué (scanné par un agent). Contrairement à la règle des 24h,
        // cette règle ne souffre AUCUNE exception (même si le trajet a été
        // annulé par l'agence après coup) : on ne peut pas rembourser un
        // passager qui est physiquement monté dans le bus. Sans ce contrôle,
        // un client pouvait embarquer puis annuler sa réservation après coup
        // pour se faire rembourser un voyage déjà effectué.
        $existingTickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
        $hasBoardedTicket = false;
        foreach ($existingTickets as $existingTicket) {
            if ($existingTicket->getStatus() === 'embarque') {
                $hasBoardedTicket = true;
                break;
            }
        }
        if ($hasBoardedTicket) {
            return new JsonResponse([
                'error' => "Cette réservation ne peut plus être annulée : au moins un billet a déjà été validé à l'embarquement.",
            ], 409);
        }

        $trip = $reservation->getTrip();
        $departureTime = $trip?->getDepartureTime();
        if (!$departureTime) {
            return new JsonResponse(['error' => "Impossible de déterminer l'heure de départ de ce voyage."], 422);
        }

        // --- Règle des 24h avant le départ ---
        // 👈 CORRIGÉ : si le trajet a été annulé par l'agence (voir
        // TripController::update()/delete()), le client doit TOUJOURS
        // pouvoir se faire rembourser, quel que soit le délai restant —
        // avant, un client se faisait bloquer par cette règle pour une
        // annulation qui n'était pas de son fait.
        $tripCancelledByAgency = $trip->getStatus() === 'annule';

        $now = new \DateTime();
        $hoursBeforeDeparture = ($departureTime->getTimestamp() - $now->getTimestamp()) / 3600;

        if (!$tripCancelledByAgency && $hoursBeforeDeparture < self::CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE) {
            return new JsonResponse([
                'error' => sprintf(
                    "L'annulation n'est possible que jusqu'à %dh avant l'embarquement.",
                    self::CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE,
                ),
                'hoursRemaining' => max(0, round($hoursBeforeDeparture, 1)),
            ], 422);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reason = trim((string)($data['reason'] ?? '')) ?: ($tripCancelledByAgency
            ? "Trajet annulé par l'agence"
            : "Annulation à l'initiative du client");

        // 👈 NOUVEAU : transaction + verrou pessimiste sur le trajet, symétrique
        // à create(). Nécessaire maintenant que cancel() modifie lui aussi
        // trip.seatsReserved — sans verrou, une annulation et une nouvelle
        // réservation concurrentes sur le même trajet pourraient se marcher
        // dessus (race condition classique lecture-modification-écriture).
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $lockedReservation = $this->em->getRepository(Reservation::class)->find($reservation->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedReservation) {
                throw new \RuntimeException('Réservation introuvable.');
            }
            if (in_array($lockedReservation->getPaymentStatus(), ['annule', 'rembourse'], true)) {
                $connection->rollBack();
                return new JsonResponse(['error' => 'Cette réservation a déjà été annulée.'], 409);
            }
            $reservation = $lockedReservation;
            $lockedTrip = $this->em->getRepository(Trip::class)->find($trip->getId(), LockMode::PESSIMISTIC_WRITE);

            // 👈 IMPORTANT : capturé AVANT toute mutation — sert à décider plus
            // bas si un remboursement réel est dû (voir point 4 du docblock).
            $wasPaid = $reservation->getPaymentStatus() === 'paye';

            // 1) Réservation -> remboursée / annulée
            $this->stateTransitions->transitionReservationPayment($reservation, 'annule');
            $this->em->persist($reservation);

            // 2) Tous les billets liés -> annulés (invalides, plus scannables/validables)
            $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
            foreach ($tickets as $ticket) {
                $this->stateTransitions->transitionTicket($ticket, 'annule');
                // 👈 SÉCURITÉ : invalider le jeton QR pour empêcher toute réutilisation malveillante
                $ticket->setQrCodeToken(null);
                $this->em->persist($ticket);
            }

            // 3) 👈 NOUVEAU : libération effective des places sur le trajet.
            if ($lockedTrip) {
                $freedSeats = count($tickets);
                $lockedTrip->setSeatsReserved(max(0, $lockedTrip->getSeatsReserved() - $freedSeats));
                $this->em->persist($lockedTrip);
            }

            // 4) 👈 CORRIGÉ : remboursement demandé UNIQUEMENT si un paiement
            // avait réellement été effectué avant l'annulation.
            $refundLog = null;
            $refundAmount = null;

            if ($wasPaid) {
                $refundAmount = bcsub((string) $reservation->getTotalAmount(), number_format(WalletService::PLATFORM_FEE, 2, '.', ''), 2);
                if (bccomp($refundAmount, '0.00', 2) < 0) {
                    $refundAmount = '0.00';
                }
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
                    'requested_by_user_id' => $user->getId(),
                    'original_transaction_reference' => $reservation->getTransactionReference(),
                ]));
                $this->em->persist($refundLog);

                // 👈 NOUVEAU : création de la demande de remboursement associée
                // dans la table dédiée refund_requests, pour suivi et traitement
                // côté admin (AdminRefundController).
                $refundRequest = new RefundRequest();
                $refundRequest->setAgency($trip->getAgency());
                $refundRequest->setReservation($reservation);
                $refundRequest->setRequestedBy($user);
                $refundRequest->setRequestedAmount((string)$refundAmount);
                $refundRequest->setReason($reason);
                $this->em->persist($refundRequest);
            }

            // 5) Notification au client
            // 👈 Garde de sécurité : reservation->getUser() est vérifié non-null
            // seulement dans le cas $isOwner ; si un admin annule une réservation
            // dont l'utilisateur serait null (cas limite), on ne plante pas.
            $notification = null;
            if ($reservation->getUser()) {
                $notification = new Notification();
                $notification->setRecipientType('user')
                    ->setRecipientId($reservation->getUser()->getId())
                    ->setTitle('Réservation annulée')
                    ->setContent($refundAmount !== null
                        ? sprintf(
                            'Votre réservation pour le trajet %s → %s a été annulée. Le remboursement de %s FCFA est en cours de traitement par notre équipe.',
                            $trip->getDepartureCity(),
                            $trip->getArrivalCity(),
                            $refundAmount,
                        )
                        : sprintf(
                            'Votre réservation pour le trajet %s → %s a été annulée.',
                            $trip->getDepartureCity(),
                            $trip->getArrivalCity(),
                        ))
                    ->setCategory('BOOKING');
                $this->em->persist($notification);
            }

            $agencyNotification = null;
            if ($trip->getAgency()) {
                $agencyNotification = new Notification();
                $agencyNotification->setRecipientType('agency_all')
                    ->setRecipientId($trip->getAgency()->getId())
                    ->setTitle('Réservation annulée par un client')
                    ->setContent(sprintf(
                        'Une réservation a été annulée sur le trajet %s → %s du %s (%d siège(s) libéré(s)).',
                        $trip->getDepartureCity(),
                        $trip->getArrivalCity(),
                        $departureTime->format('d/m/Y à H:i'),
                        count($tickets),
                    ))
                    ->setCategory('BOOKING');
                $this->em->persist($agencyNotification);
            }

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        if ($notification) {
            $this->notificationBroadcaster->broadcast($notification);
        }
        if ($agencyNotification) {
            $this->notificationBroadcaster->broadcast($agencyNotification);
        }

        $this->adminNotificationService->notifyEvent(
            'Réservation annulée',
            sprintf('La réservation #%d a été annulée.', $reservation->getId()),
            'BOOKING',
            ['reservationId' => $reservation->getId(), 'tripId' => $trip->getId(), 'userId' => $reservation->getUser()?->getId()]
        );

        return new JsonResponse([
            'ok' => true,
            'reservationId' => $reservation->getId(),
            'paymentStatus' => $reservation->getPaymentStatus(),
            'ticketsCancelled' => count($tickets),
            'refund' => $refundLog ? [
                'reference' => $refundLog->getReference(),
                'status' => $refundLog->getStatus(),
                'amount' => $refundLog->getAmount(),
            ] : null,
            'message' => $refundLog
                ? 'Réservation annulée. Le remboursement est en cours de traitement par notre équipe.'
                : 'Réservation annulée. Aucun paiement n\'avait été effectué, aucun remboursement n\'est nécessaire.',
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
            $status = 'Remboursé';
        } elseif ($reservation->getPaymentStatus() === 'annule') {

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

        // 👈 NOUVEAU : un billet déjà embarqué (scanné par un agent) rend la
        // réservation définitivement non-annulable, quel que soit le délai
        // restant avant le départ. Sans ce contrôle, le front affichait un
        // bouton "Annuler" actif pour un voyage déjà effectué.
        $hasBoardedTicket = !empty(array_filter($tickets, fn($t) => $t->getStatus() === 'embarque'));

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
            'paymentExpiresAt' => $reservation->getPaymentExpiresAt()?->format(\DateTimeInterface::ATOM),
            'canCancel' => !$hasBoardedTicket && $status !== 'Annulé' && $status !== 'Expiré' && $trip && $trip->getDepartureTime() && (($trip->getDepartureTime()->getTimestamp() - (new \DateTime())->getTimestamp()) / 3600) > self::CANCELLATION_MIN_HOURS_BEFORE_DEPARTURE,
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
