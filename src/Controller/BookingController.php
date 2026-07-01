<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\Trip;
use App\Entity\Ticket;
use App\Entity\Baggage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class BookingController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/api/bookings', name: 'create_booking', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

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

        $reservation = new Reservation();
        $user = $this->getUser();
        if ($user) $reservation->setUser($user);
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

        $this->em->flush();

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

    #[Route('/api/bookings/{id}/cancel', name: 'cancel_booking', methods: ['POST'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $repo = $this->em->getRepository(Reservation::class);
        $res = $repo->find($id);
        if (!$res) return new JsonResponse(['error' => 'Not found'], 404);
        $res->setPaymentStatus('rembourse');
        $this->em->flush();
        return new JsonResponse(['ok' => true], 200);
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

        $status = 'En attente';
        switch ($reservation->getPaymentStatus()) {
            case 'paye':
                $status = 'Confirmé';
                break;
            case 'rembourse':
            case 'echoue':
                $status = 'Annulé';
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
            'bookingDate' => $departureTime ? $departureTime->format('c') : $reservation->getCreatedAt()?->format('c'),
            'trip' => $trip ? [
                'id' => $trip->getId(),
                'departureCity' => $trip->getDeparturePoint()?->getCity() ?? '',
                'arrivalCity' => $trip->getArrivalPoint()?->getCity() ?? '',
                'departureTime' => $departureTime ? $departureTime->format('c') : null,
                'arrivalTime' => ($trip->getEstimatedArrivalTime()?->format('c')) ?: null,
                'departureDate' => $departureDate,
                'agencyId' => $trip->getAgency()?->getId(),
                'agencyName' => $trip->getAgency()?->getName(),
                'pricePerSeat' => (float)$trip->getPrice(),
            ] : null,
            'createdAt' => $reservation->getCreatedAt()?->format('c'),
            'updatedAt' => $reservation->getCreatedAt()?->format('c'),
        ];
    }
}
