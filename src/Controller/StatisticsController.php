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
        // KPI personnel affiché sur la carte "Billets validés" : uniquement
        // ce que CET agent a validé.
        $ticketsValidated = $this->ticketRepository->countValidatedByAgent($agent, $start, $end);
        $ticketsPending = $this->ticketRepository->countPendingByTrip($agent->getAgency(), $start, $end);

        // 👈 CORRIGÉ : le "taux de validation" comparait auparavant les
        // billets validés PAR CET AGENT SEUL aux billets en attente de TOUTE
        // L'AGENCE — deux échelles différentes, ratio sans signification. Le
        // taux porte maintenant sur l'agence entière des deux côtés (combien
        // de billets de l'agence, tous agents confondus, sont déjà validés
        // sur ceux attendus), ce qui est une mesure opérationnelle cohérente.
        $ticketsValidatedByAgency = $this->ticketRepository->countValidatedByAgency($agent->getAgency(), $start, $end);

        // 👈 CORRIGÉ : `->modify('-1 period')` n'est pas une syntaxe valide
        // pour DateTime (voir shiftToPreviousPeriod()) — la comparaison ne
        // décalait jamais réellement la période précédente.
        [$previousStart, $previousEnd] = $this->shiftToPreviousPeriod($start, $end);

        // Calcul du changement en pourcentage
        $ticketsChangePct = $this->calculatePercentageChange(
            $ticketsValidated,
            $this->ticketRepository->countValidatedByAgent($agent, $previousStart, $previousEnd)
        );

        // Statistiques de trajets
        $tripsInProgress = $this->tripRepository->countActiveTrips($agent->getAgency(), $start, $end);
        $tripsCompleted = $this->tripRepository->countCompletedTrips($agent->getAgency(), $start, $end);
        $tripsCancelled = $this->tripRepository->countCancelledTrips($agent->getAgency(), $start, $end);

        // Revenus
        $revenue = $this->calculateRevenueByAgent($agent, $start, $end);
        $previousRevenue = $this->calculateRevenueByAgent($agent, $previousStart, $previousEnd);
        $revenueChange = $this->calculatePercentageChange($revenue, $previousRevenue);

        // Performance: taux de validation (échelle agence, cf. plus haut)
        $totalTickets = $ticketsValidatedByAgency + $ticketsPending;
        $validationRate = $totalTickets > 0 ? round(($ticketsValidatedByAgency / $totalTickets) * 100, 2) : 0;

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
            // 👈 CORRIGÉ : utilisait findBy(['reservation' => $trip->getId()]),
            // qui compare l'id de la réservation d'un ticket à l'id d'un Trip
            // — deux séquences d'ID indépendantes, donc des tickets au hasard.
            $tickets = $this->ticketRepository->findTicketsByTrip($trip);
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

    /**
     * Calcule la période "précédente", de même durée que [$start, $end], et
     * qui se termine juste avant $start.
     *
     * 👈 CORRIGÉ : le code appelait auparavant `->modify('-1 period')`, qui
     * n'est PAS une syntaxe reconnue par DateTime::modify() ("period" n'est
     * pas un mot-clé relatif valide). Cet appel ne décalait donc jamais
     * réellement la date (au mieux un no-op, au pire une exception selon la
     * version de PHP) : on comparait en réalité la période courante à
     * elle-même, ce qui faussait tous les "+X%" affichés au dashboard.
     *
     * @return array{0: \DateTime, 1: \DateTime} [$previousStart, $previousEnd]
     */
    private function shiftToPreviousPeriod(\DateTime $start, \DateTime $end): array
    {
        $durationInSeconds = $end->getTimestamp() - $start->getTimestamp();
        if ($durationInSeconds <= 0) {
            // Garde-fou : une période nulle/négative ne doit jamais produire
            // une division par zéro ou une plage absurde en aval.
            $durationInSeconds = 86400;
        }

        $previousEnd = (clone $start)->modify('-1 second');
        $previousStart = (clone $previousEnd)->modify("-{$durationInSeconds} seconds");

        return [$previousStart, $previousEnd];
    }

    /**
     * 👈 CORRIGÉ EN PROFONDEUR : cette méthode calculait le revenu de TOUTE
     * L'AGENCE sur la période (la requête filtrait par `a.id = agence`, pas
     * par agent) — deux agents différents de la même agence voyaient donc
     * exactement le même montant sur "leur" dashboard, ce qui n'a aucun sens
     * pour une page de performance individuelle.
     *
     * On calcule maintenant le revenu réellement attribuable à CET agent :
     * la somme des réservations payées dont il a personnellement validé au
     * moins un billet sur la période. Chaque réservation n'est comptée
     * qu'UNE SEULE FOIS même si l'agent a validé plusieurs billets de cette
     * même réservation (sinon une réservation à 3 passagers, tous validés
     * par le même agent, verrait son montant triplé).
     */
    private function calculateRevenueByAgent(Agent $agent, \DateTime $start, \DateTime $end): float
    {
        $tickets = $this->ticketRepository->findValidatedByAgentWithinPeriod($agent, $start, $end);

        $countedReservationIds = [];
        $sum = 0.0;
        foreach ($tickets as $ticket) {
            $reservation = $ticket->getReservation();
            if (!$reservation || $reservation->getPaymentStatus() !== 'paye') {
                continue;
            }
            $reservationId = $reservation->getId();
            if (isset($countedReservationIds[$reservationId])) {
                continue;
            }
            $countedReservationIds[$reservationId] = true;
            $sum += (float) $reservation->getTotalAmount();
        }

        return $sum;
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
            // Même correctif que getAgentTripsDetails() / calculateTripRevenue().
            $tickets = $this->ticketRepository->findTicketsByTrip($trip);
            $boardedCount = count(array_filter($tickets, fn($t) => $t->getStatus() === 'embarque'));
            $bus = $trip->getBus();
            $capacity = $bus?->getCapacity() ?? 1;
            return ($boardedCount / $capacity) * 100;
        }, $trips);

        return array_sum($fillRates) / count($fillRates);
    }

    /**
     * 👈 CORRIGÉ (double bug) :
     *  1) Utilisait findBy(['reservation' => $trip->getId()]), qui compare
     *     l'id de réservation d'un ticket à l'id d'un Trip — mauvaise
     *     jointure, tickets récupérés au hasard.
     *  2) Sommait `reservation->getTotalAmount()` UNE FOIS PAR TICKET : une
     *     réservation à 3 passagers a 3 tickets liés au même montant, donc
     *     son totalAmount était compté 3 fois → revenu du trajet triplé pour
     *     toute réservation multi-passagers.
     * On ne compte maintenant chaque réservation payée qu'une seule fois.
     */
    private function calculateTripRevenue(Trip $trip): float
    {
        $tickets = $this->ticketRepository->findTicketsByTrip($trip);

        $countedReservationIds = [];
        $sum = 0.0;
        foreach ($tickets as $ticket) {
            $reservation = $ticket->getReservation();
            if (!$reservation || $reservation->getPaymentStatus() !== 'paye') {
                continue;
            }
            $reservationId = $reservation->getId();
            if (isset($countedReservationIds[$reservationId])) {
                continue;
            }
            $countedReservationIds[$reservationId] = true;
            $sum += (float) $reservation->getTotalAmount();
        }

        return $sum;
    }
}