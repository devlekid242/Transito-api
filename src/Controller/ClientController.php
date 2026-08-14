<?php

namespace App\Controller;

use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Repository\AgencyPointRepository;
use App\Repository\PaymentLogRepository;
use App\Repository\ReservationRepository;
use App\Repository\TicketRepository;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/client')]
class ClientController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/dashboard', name: 'api_client_dashboard', methods: ['GET'])]
    public function dashboard(ReservationRepository $reservations): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) return $this->json(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);

        $upcoming = $reservations->createQueryBuilder('r')
            ->join('r.trip', 't')
            ->where('r.user = :user')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->andWhere('t.departureTime >= :now')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['en_attente', 'paye'])
            ->setParameter('now', new \DateTime())
            ->orderBy('t.departureTime', 'ASC')
            ->setMaxResults(5)
            ->getQuery()->getResult();

        $activeCount = (int) $reservations->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.trip', 't')
            ->where('r.user = :user')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->andWhere('t.departureTime >= :now')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['en_attente', 'paye'])
            ->setParameter('now', new \DateTime())
            ->getQuery()->getSingleScalarResult();

        $completedCount = (int) $reservations->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.trip', 't')
            ->where('r.user = :user')
            ->andWhere('t.departureTime < :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTime())
            ->getQuery()->getSingleScalarResult();

        $spent = '0.00';
        $rows = $reservations->createQueryBuilder('r')
            ->select('r.totalAmount')
            ->where('r.user = :user')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', ['paye', 'rembourse'])
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $amount = (string) ($row['totalAmount'] ?? '0.00');
            $spent = bcadd($spent, $amount, 2);
        }

        return $this->json([
            'user' => $this->serializeUser($user),
            'stats' => [
                'activeReservations' => $activeCount,
                'completedReservations' => $completedCount,
                'totalBookedAmount' => $spent,
            ],
            'upcomingReservations' => array_map([$this, 'serializeReservation'], $upcoming),
        ]);
    }

    #[Route('/payments/history', name: 'api_client_payment_history', methods: ['GET'])]
    public function paymentHistory(PaymentLogRepository $payments): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) return $this->json(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);

        $logs = $payments->createQueryBuilder('p')
            ->join('p.reservation', 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()->getResult();

        return $this->json(array_map(static fn (PaymentLog $p) => [
            'id' => $p->getId(),
            'reservationId' => $p->getReservation()?->getId(),
            'amount' => (string) $p->getAmount(),
            'paymentMethod' => $p->getOperator(),
            'reference' => $p->getReference(),
            'providerReference' => $p->getProviderReference(),
            'status' => $p->getStatus(),
            'createdAt' => $p->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ], $logs));
    }

    #[Route('/trips/{id}/availability', name: 'api_client_trip_availability', methods: ['GET'])]
    public function availability(int $id, TripRepository $trips, TicketRepository $tickets): JsonResponse
    {
        $trip = $trips->find($id);
        if (!$trip) return $this->json(['message' => 'Voyage introuvable.'], Response::HTTP_NOT_FOUND);

        $capacity = (int) ($trip->getBus()?->getCapacity() ?? 0);
        if ($trip->getStatus() !== 'planifie' || !$trip->getDepartureTime() || $trip->getDepartureTime() <= new \DateTime()) {
            return $this->json([
                'tripId' => $trip->getId(), 'capacity' => $capacity,
                'availableSeats' => 0, 'seats' => [], 'bookable' => false,
            ]);
        }

        $occupied = $tickets->createQueryBuilder('t')
            ->join('t.reservation', 'r')
            ->where('r.trip = :trip')
            ->andWhere('t.status IN (:ticketStatuses)')
            ->andWhere('(r.paymentStatus = :paid OR (r.paymentStatus = :pending AND (r.paymentExpiresAt IS NULL OR r.paymentExpiresAt > :now)))')
            ->setParameter('trip', $trip)
            ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
            ->setParameter('paid', 'paye')
            ->setParameter('pending', 'en_attente')
            ->setParameter('now', new \DateTime())
            ->getQuery()->getResult();

        $occupiedMap = [];
        foreach ($occupied as $ticket) {
            if ($ticket->getSeatNumber() !== null) $occupiedMap[(int) $ticket->getSeatNumber()] = true;
        }

        $seats = [];
        for ($i = 1; $i <= $capacity; ++$i) {
            $seats[] = ['number' => $i, 'available' => !isset($occupiedMap[$i])];
        }

        return $this->json([
            'tripId' => $trip->getId(),
            'capacity' => $capacity,
            'occupiedSeats' => array_keys($occupiedMap),
            'availableSeats' => $capacity - count($occupiedMap),
            'seats' => $seats,
            'bookable' => true,
        ]);
    }

    #[Route('/profile', name: 'api_client_profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) return $this->json(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        return $this->json($this->serializeUser($user));
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->getUser();
        return $user instanceof User ? $user : null;
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'phoneNumber' => $user->getPhoneNumber(),
            'email' => $user->getEmail(),
            'profilePhotoUrl' => $user->getProfilePhotoUrl(),
            'villeResidence' => $user->getVilleResidence(),
            'quartier' => $user->getQuartier(),
            'prefNotifications' => $user->getPrefNotifications(),
            'prefLanguage' => $user->getPrefLanguage(),
            'prefDarkMode' => $user->getPrefDarkMode(),
        ];
    }

    private function serializeReservation(Reservation $reservation): array
    {
        $trip = $reservation->getTrip();
        $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
        return [
            'id' => $reservation->getId(),
            'paymentStatus' => $reservation->getPaymentStatus(),
            'totalAmount' => (string) $reservation->getTotalAmount(),
            'paymentExpiresAt' => $reservation->getPaymentExpiresAt()?->format(\DateTimeInterface::ATOM),
            'trip' => $trip ? [
                'id' => $trip->getId(),
                'departureCity' => $trip->getDepartureCity(),
                'arrivalCity' => $trip->getArrivalCity(),
                'departureTime' => $trip->getDepartureTime()?->format(\DateTimeInterface::ATOM),
                'arrivalTime' => $trip->getEstimatedArrivalTime()?->format(\DateTimeInterface::ATOM),
                'agency' => ['id' => $trip->getAgency()?->getId(), 'name' => $trip->getAgency()?->getName(), 'logoUrl' => $trip->getAgency()?->getLogoUrl()],
            ] : null,
            'tickets' => array_map(static fn (Ticket $ticket) => [
                'id' => $ticket->getId(),
                'ticketNumber' => 'TKT-' . $ticket->getId(),
                'seatNumber' => $ticket->getSeatNumber(),
                'passengerName' => $ticket->getPassengerName(),
                'status' => $ticket->getStatus(),
                'qrCodeToken' => $ticket->getStatus() === 'annule' ? null : $ticket->getQrCodeToken(),
                'settlementAmount' => (string) $ticket->getSettlementAmount(),
            ], $tickets),
        ];
    }
}
