<?php

namespace App\Controller;

use App\Entity\Agent;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Reservation;
use App\Repository\AgentRepository;
use App\Repository\TicketRepository;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/statistics')]
class StatisticsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agentRepository,
        private TicketRepository $ticketRepository,
        private TripRepository $tripRepository,
    ) {}

    /**
     * Récupère les statistiques de l'agent connecté (validation de tickets, etc.)
     * Paramètres query: start (ISO date), end (ISO date)
     */
    #[Route('/agent', name: 'agent_statistics', methods: ['GET'])]
    public function getAgentStatistics(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $startDate = $request->query->get('start');
        $endDate = $request->query->get('end');

        try {
            $start = $startDate ? new \DateTime($startDate) : new \DateTime('today');
            $end = $endDate ? new \DateTime($endDate) : new \DateTime('tomorrow');
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid date format'], 400);
        }

        // Récupérer l'agent associé à l'utilisateur
        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        if (!$agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        // Statistiques de validation de tickets
        $ticketsValidated = $this->ticketRepository->countValidatedByAgent($agent, $start, $end);
        $ticketsPending = $this->ticketRepository->countPendingByTrip($agent->getAgency(), $start, $end);

        // Calcul du changement en pourcentage
        $ticketsChangePct = $this->calculatePercentageChange(
            $ticketsValidated,
            $this->ticketRepository->countValidatedByAgent(
                $agent,
                (clone $start)->modify('-1 period'),
                (clone $end)->modify('-1 period')
            )
        );

        // Statistiques de trajets
        $tripsInProgress = $this->tripRepository->countActiveTrips($agent->getAgency(), $start, $end);
        $tripsCompleted = $this->tripRepository->countCompletedTrips($agent->getAgency(), $start, $end);
        $tripsCancelled = $this->tripRepository->countCancelledTrips($agent->getAgency(), $start, $end);

        // Revenus
        $revenue = $this->calculateRevenueByAgent($agent, $start, $end);
        $previousRevenue = $this->calculateRevenueByAgent(
            $agent,
            (clone $start)->modify('-1 period'),
            (clone $end)->modify('-1 period')
        );
        $revenueChange = $this->calculatePercentageChange($revenue, $previousRevenue);

        // Performance: taux de validation
        $totalTickets = $ticketsValidated + $ticketsPending;
        $validationRate = $totalTickets > 0 ? round(($ticketsValidated / $totalTickets) * 100, 2) : 0;

        // Passagers embarqués
        $passengersBoarded = $this->ticketRepository->countBoardedPassengers($agent, $start, $end);
        $passengersNoShow = $this->ticketRepository->countNoShowPassengers($agent, $start, $end);

        return new JsonResponse([
            'period' => [
                'start' => $start->format('c'),
                'end' => $end->format('c'),
            ],
            'agent' => [
                'id' => $agent->getId(),
                'name' => $agent->getUser()?->getFullName(),
                'email' => $agent->getUser()?->getEmail(),
                'role' => $agent->getAgentRole(),
            ],
            'tickets' => [
                'validated' => $ticketsValidated,
                'pending' => $ticketsPending,
                'total' => $totalTickets,
                'validationRate' => $validationRate . '%',
                'change' => $ticketsChangePct,
                'boarded' => $passengersBoarded,
                'noShow' => $passengersNoShow,
            ],
            'trips' => [
                'inProgress' => $tripsInProgress,
                'completed' => $tripsCompleted,
                'cancelled' => $tripsCancelled,
                'total' => $tripsInProgress + $tripsCompleted + $tripsCancelled,
            ],
            'revenue' => [
                'amount' => round($revenue, 2),
                'currency' => 'FCFA',
                'change' => $revenueChange,
            ],
            'kpis' => [
                'ticketsValidated' => $ticketsValidated,
                'ticketsChange' => $ticketsChangePct,
                'tripsInProgress' => $tripsInProgress,
                'tripsCompleted' => $tripsCompleted,
                'revenue' => round($revenue, 2),
                'revenueChange' => $revenueChange,
            ],
        ], 200);
    }

    /**
     * Récupère les statistiques détaillées de l'agence (admin uniquement)
     */
    #[Route('/agency', name: 'agency_statistics', methods: ['GET'])]
    public function getAgencyStatistics(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Vérifier que l'utilisateur est admin d'une agence
        $agent = $this->agentRepository->findOneBy(['user' => $user]);

        $agency = $agent->getAgency();
        if (!$agency) {
            return new JsonResponse(['error' => 'No agency associated'], 403);
        }

        $startDate = $request->query->get('start');
        $endDate = $request->query->get('end');

        try {
            $start = $startDate ? new \DateTime($startDate) : new \DateTime('today');
            $end = $endDate ? new \DateTime($endDate) : new \DateTime('tomorrow');
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid date format'], 400);
        }

        // Statistiques globales d'agence
        $totalTrips = $this->tripRepository->countTripsByAgency($agency, $start, $end);
        $totalTickets = $this->ticketRepository->countTicketsByAgency($agency, $start, $end);
        $totalRevenue = $this->calculateRevenueByAgency($agency, $start, $end);
        $totalAgents = count($this->agentRepository->findBy(['agency' => $agency]));

        // Taux de remplissage des bus
        $averageFillRate = $this->calculateAverageBusFillRate($agency, $start, $end);

        // Nombre de trajets par statut
        $tripsByStatus = $this->tripRepository->countTripsByStatus($agency, $start, $end);

        return new JsonResponse([
            'period' => [
                'start' => $start->format('c'),
                'end' => $end->format('c'),
            ],
            'agency' => [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
                'licenseNumber' => $agency->getRegistrationNumber(),
            ],
            'overview' => [
                'totalTrips' => $totalTrips,
                'totalTickets' => $totalTickets,
                'totalRevenue' => round($totalRevenue, 2),
                'totalAgents' => $totalAgents,
                'averageFillRate' => round($averageFillRate, 2) . '%',
            ],
            'tripsByStatus' => $tripsByStatus,
        ], 200);
    }

    /**
     * Récupère les statistiques comparatives (jour vs semaine vs mois, etc.)
     */
    #[Route('/agent/comparison', name: 'agent_statistics_comparison', methods: ['GET'])]
    public function getAgentStatisticsComparison(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        if (!$agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $period = $request->query->get('period', 'month'); // day, week, month, year

        $now = new \DateTime();
        $stats = [];

        for ($i = 0; $i < 6; $i++) {
            $periodStart = clone $now;
            $periodEnd = clone $now;

            match ($period) {
                'day' => [
                    $periodStart->modify("-{$i} days")->setTime(0, 0, 0),
                    $periodEnd->modify("-{$i} days")->setTime(23, 59, 59),
                ],
                'week' => [
                    $periodStart->modify("-{$i} weeks")->setTime(0, 0, 0),
                    $periodEnd->modify("-{$i} weeks")->modify('+6 days')->setTime(23, 59, 59),
                ],
                'month' => [
                    $periodStart->modify("-{$i} months")->setTime(0, 0, 0),
                    $periodEnd->modify("-{$i} months")->modify('last day of')->setTime(23, 59, 59),
                ],
                'year' => [
                    $periodStart->modify("-{$i} years")->setTime(0, 0, 0),
                    $periodEnd->modify("-{$i} years")->modify('last day of December')->setTime(23, 59, 59),
                ],
                default => null,
            };

            if ($periodStart && $periodEnd) {
                $ticketsValidated = $this->ticketRepository->countValidatedByAgent($agent, $periodStart, $periodEnd);
                $revenue = $this->calculateRevenueByAgent($agent, $periodStart, $periodEnd);

                $stats[] = [
                    'period' => $periodStart->format('Y-m-d'),
                    'ticketsValidated' => $ticketsValidated,
                    'revenue' => round($revenue, 2),
                ];
            }
        }

        return new JsonResponse([
            'period' => $period,
            'data' => $stats,
        ], 200);
    }

    /**
     * Récupère les détails des trajets pour l'agent avec statistiques
     */
    #[Route('/agent/trips', name: 'agent_trips_statistics', methods: ['GET'])]
    public function getAgentTripsDetails(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        if (!$agent) {
            return new JsonResponse(['error' => 'Agent not found'], 404);
        }

        $startDate = $request->query->get('start');
        $endDate = $request->query->get('end');

        try {
            $start = $startDate ? new \DateTime($startDate) : new \DateTime('today');
            $end = $endDate ? new \DateTime($endDate) : new \DateTime('tomorrow');
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid date format'], 400);
        }

        // Récupérer les trajets de l'agence de l'agent
        $trips = $this->tripRepository->findTripsWithinPeriod($agent->getAgency(), $start, $end);

        $tripsData = array_map(function (Trip $trip) {
            $tickets = $this->ticketRepository->findBy(['reservation' => $trip->getId()]);
            $boardedCount = count(array_filter($tickets, fn($t) => $t->getStatus() === 'embarque'));
            $totalCount = count($tickets);

            return [
                'id' => $trip->getId(),
                'route' => $trip->getDepartureCity() . ' → ' . $trip->getArrivalCity(),
                'departureTime' => $trip->getDepartureTime()?->format('c'),
                'status' => $trip->getStatus(),
                'passengerCount' => $totalCount,
                'boardedCount' => $boardedCount,
                'fillRate' => $totalCount > 0 ? round(($boardedCount / $totalCount) * 100, 2) : 0,
                'revenue' => round($this->calculateTripRevenue($trip), 2),
            ];
        }, $trips);

        return new JsonResponse($tripsData, 200);
    }

    /**
     * Récupère les réservations récentes de l'agent (pour le dashboard)
     * Routes: /api/statistics/agent/recent-bookings ou /api/bookings/recent
     */
    #[Route('/agent/recent-bookings', name: 'agent_recent_bookings', methods: ['GET'])]
    public function getRecentBookings(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        if (!$agent || !$agent->getAgency()) {
            return new JsonResponse(['error' => 'Agent or Agency not found'], 404);
        }

        $limit = $request->query->getInt('limit', 10);

        // CORRECTION ICI : On sélectionne UNIQUEMENT 'r' pour garantir un tableau d'objets Reservation
        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(Reservation::class, 'r')
            ->join('r.trip', 't')
            ->leftJoin(Ticket::class, 'tk', 'WITH', 'tk.reservation = r')
            ->where('t.agency = :agency')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('agency', $agent->getAgency());

        $reservations = $qb->getQuery()->getResult();

        $bookingsData = array_map(function (Reservation $reservation) {
            $trip = $reservation->getTrip();

            // Recherche du ticket lié
            $ticket = $this->ticketRepository->findOneBy(['reservation' => $reservation]);

            return [
                'id' => $reservation->getId(),
                'passengerName' => $ticket?->getPassengerName() ?? 'N/A',
                'passengerPhone' => $ticket?->getPassengerPhone() ?? 'N/A',
                'route' => $trip->getDepartureCity() . ' → ' . $trip->getArrivalCity(),
                'departureCity' => $trip->getDepartureCity(),
                'arrivalCity' => $trip->getArrivalCity(),
                'departureTime' => $trip->getDepartureTime()?->format('c'),
                'estimatedArrivalTime' => $trip->getEstimatedArrivalTime()?->format('c'),
                'seatNumber' => $ticket?->getSeatNumber() ?? 'N/A',
                'ticketCode' => $ticket?->getQrCodeToken() ?? 'N/A',
                'price' => round($reservation->getTotalAmount(), 2),
                'currency' => 'FCFA',
                'paymentStatus' => $reservation->getPaymentStatus(),
                'paymentMethod' => $reservation->getPaymentMethod() ?? 'N/A',
                'ticketStatus' => $ticket?->getStatus() ?? 'pending',
                'bookingDate' => $reservation->getCreatedAt()?->format('c'),
                'createdAt' => $reservation->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }, $reservations);

        return new JsonResponse($bookingsData, 200);
    }

    // ============= HELPER METHODS =============

    private function calculatePercentageChange(float $current, float $previous): string
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : '0%';
        }
        $change = (($current - $previous) / $previous) * 100;
        $sign = $change >= 0 ? '+' : '';
        return $sign . round($change, 1) . '%';
    }

    private function calculateRevenueByAgent(Agent $agent, \DateTime $start, \DateTime $end): float
    {
        $qb = $this->em->createQueryBuilder()
            ->select('SUM(r.totalAmount)')
            ->from('App\Entity\Reservation', 'r')
            ->join('r.trip', 't')
            ->join('t.agency', 'a')
            ->where('a.id = :agency')
            ->andWhere('r.paymentStatus = :status')
            ->andWhere('r.createdAt >= :start')
            ->andWhere('r.createdAt <= :end')
            ->setParameter('agency', $agent->getAgency()->getId())
            ->setParameter('status', 'paye')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        return (float)$qb->getQuery()->getSingleScalarResult() ?? 0;
    }

    private function calculateRevenueByAgency(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): float
    {
        $qb = $this->em->createQueryBuilder()
            ->select('SUM(r.totalAmount)')
            ->from('App\Entity\Reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('r.paymentStatus = :status')
            ->andWhere('r.createdAt >= :start')
            ->andWhere('r.createdAt <= :end')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'paye')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        return (float)$qb->getQuery()->getSingleScalarResult() ?? 0;
    }

    private function calculateAverageBusFillRate(\App\Entity\Agency $agency, \DateTime $start, \DateTime $end): float
    {
        $trips = $this->tripRepository->findTripsWithinPeriod($agency, $start, $end);
        if (empty($trips)) {
            return 0;
        }

        $fillRates = array_map(function (Trip $trip) {
            $tickets = $this->ticketRepository->findBy(['reservation' => $trip->getId()]);
            $boardedCount = count(array_filter($tickets, fn($t) => $t->getStatus() === 'embarque'));
            $bus = $trip->getBus();
            $capacity = $bus?->getCapacity() ?? 1;
            return ($boardedCount / $capacity) * 100;
        }, $trips);

        return array_sum($fillRates) / count($fillRates);
    }

    private function calculateTripRevenue(Trip $trip): float
    {
        $tickets = $this->ticketRepository->findBy(['reservation' => $trip->getId()]);
        return array_reduce($tickets, function ($sum, $ticket) {
            return $sum + ($ticket->getReservation()?->getTotalAmount() ?? 0);
        }, 0);
    }
}
