<?php

namespace App\Controller\Partner;

use App\Entity\Agency;
use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Entity\WithdrawalRequest;
use App\Entity\User;
use App\Entity\Agent;
use App\Entity\Trip;
use App\Repository\PaymentLogRepository;
use App\Repository\AgentRepository;
use App\Repository\TicketRepository;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PartnerFinanceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentLogRepository $paymentLogRepository,
        private AgentRepository $agentRepository,
        private WalletService $walletService,
        private TicketRepository $ticketRepository
    ) {}

    /**
     * Récupère l'agence associée à l'utilisateur courant (Agent)
     */
    private function getAgencyForUser(User $user): ?Agency
    {
        $agent = $this->em->getRepository(Agent::class)->findOneBy(['user' => $user]);
        if (!$agent) {
            return null;
        }
        return $agent->getAgency();
    }

    #[Route('/api/statistics', name: 'api_statistics', methods: ['GET'])]
    public function getPartnerStats(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        $wallet = $this->walletService->getOrCreateWallet($agency);

        // Récupérer tous les trajets de cette agence
        $trips = $this->em->getRepository(Trip::class)->findBy(['agency' => $agency]);
        $tripIds = array_map(fn(Trip $t) => $t->getId(), $trips);

        if (empty($tripIds)) {
            $reservations = [];
        } else {
            $reservations = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->where('r.trip IN (:tripIds)')
                ->setParameter('tripIds', $tripIds)
                ->getQuery()
                ->getResult();
        }

        $totalTrips = count($trips);
        $totalPassengers = 0;
        $grossRevenue = 0.0;

        foreach ($reservations as $reservation) {
            $totalPassengers += count($reservation->getTickets() ?? []);
            if ($reservation->getPaymentStatus() === 'paye') {
                $grossRevenue += (float) $reservation->getTotalAmount();
            }
        }

        $balanceAvailable = (float) $wallet->getAvailableBalance();
        $balancePending = (float) $wallet->getReservedBalance();

        $reservationIds = array_map(fn(Reservation $r) => $r->getId(), $reservations);
        if (empty($reservationIds)) {
            $platformFees = 0.0;
        } else {
            $platformFees = (float) $this->em->getRepository(WalletTransaction::class)->createQueryBuilder('wt')
                ->select('COALESCE(SUM(wt.amount), 0) as total')
                ->join('wt.wallet', 'w')
                ->where('wt.source = :source')
                ->andWhere('wt.reservation IN (:reservationIds)')
                ->andWhere('w.type = :platformType')
                ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
                ->setParameter('reservationIds', $reservationIds)
                ->setParameter('platformType', Wallet::TYPE_PLATFORM)
                ->getQuery()
                ->getSingleScalarResult();
            $platformFees = round($platformFees, 2);
        }

        $netRevenue = max(0.0, round($grossRevenue - $platformFees, 2));

        $ticketStats = $this->em->getRepository(Ticket::class)->createQueryBuilder('tk')
            ->select('SUM(CASE WHEN tk.status = :boarded THEN 1 ELSE 0 END) as boardedCount, COUNT(tk.id) as totalCount')
            ->join('tk.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->setParameter('boarded', 'embarque')
            ->getQuery()
            ->getOneOrNullResult();

        $boardedCount = (int) ($ticketStats['boardedCount'] ?? 0);
        $ticketCount = (int) ($ticketStats['totalCount'] ?? 0);
        $boardingRate = $ticketCount > 0 ? round(($boardedCount / $ticketCount) * 100, 2) : 0.0;

        $pendingWithdrawals = $this->em->getRepository(WithdrawalRequest::class)->count([
            'agency' => $agency,
            'status' => 'pending',
        ]);

        // Historique des mouvements du portefeuille (remplace l'ancien historique basé
        // uniquement sur les PaymentLog, qui ne reflétait ni les remboursements, ni les
        // retraits, ni la commission plateforme).
        $ledgerEntries = $this->em->getRepository(WalletTransaction::class)->createQueryBuilder('wt')
            ->where('wt.wallet = :wallet')
            ->setParameter('wallet', $wallet)
            ->orderBy('wt.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $recentActivity = array_map(function (WalletTransaction $tx) {
            $signedAmount = $tx->getType() === WalletTransaction::TYPE_CREDIT
                ? (float) $tx->getAmount()
                : -1 * (float) $tx->getAmount();

            $status = $tx->getSource() === WalletTransaction::SOURCE_WITHDRAWAL_HOLD
                ? 'En cours'
                : 'Terminé';

            return [
                'id' => $tx->getId(),
                'description' => $tx->getDescription(),
                'amount' => $signedAmount,
                'status' => $status,
                'reservationId' => $tx->getReservation()?->getId(),
                'withdrawalId' => $tx->getWithdrawalRequest()?->getId(),
                'createdAt' => $tx->getCreatedAt()?->format('c'),
            ];
        }, $ledgerEntries);

        $withdrawals = $this->em->getRepository(WithdrawalRequest::class)->findBy(
            ['agency' => $agency],
            ['createdAt' => 'DESC']
        );

        $dailyTotals = [];
        $today = new \DateTimeImmutable();
        for ($i = 5; $i >= 0; $i--) {
            $date = $today->sub(new \DateInterval("P{$i}D"));
            $dailyTotals[$date->format('Y-m-d')] = 0.0;
        }

        // Transactions par jour pour cette agence (uniquement les paiements confirmés)
        $transactionsByDay = $this->paymentLogRepository->createQueryBuilder('p')
            ->select('DATE(p.createdAt) as day, SUM(p.amount) as total')
            ->join('p.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('p.status = :status')
            ->andWhere('p.createdAt >= :start')
            ->setParameter('agency', $agency)
            ->setParameter('status', 'SUCCESS')
            ->setParameter('start', $today->sub(new \DateInterval('P5D'))->setTime(0, 0, 0))
            ->groupBy('day')
            ->getQuery()
            ->getArrayResult();

        foreach ($transactionsByDay as $row) {
            if (isset($dailyTotals[$row['day']])) {
                $dailyTotals[$row['day']] = (float) $row['total'];
            }
        }

        // Répartition par statut pour cette agence
        $breakdownStats = $this->paymentLogRepository->createQueryBuilder('p')
            ->select('p.status as status, COUNT(p.id) as count')
            ->join('p.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->groupBy('p.status')
            ->setParameter('agency', $agency)
            ->getQuery()
            ->getArrayResult();

        $breakdownLabels = [];
        $breakdownData = [];
        foreach ($breakdownStats as $row) {
            $breakdownLabels[] = ucfirst($row['status']);
            $breakdownData[] = (int) $row['count'];
        }

        $savedReports = $this->buildSavedReports($user);

        return new JsonResponse([
            'revenue' => $grossRevenue,
            'netRevenue' => $netRevenue,
            'platformFees' => $platformFees,
            'revenueChange' => '0%',
            'activeTrips' => $totalTrips,
            'totalPassengers' => $totalPassengers,
            'boardingRate' => $boardingRate,
            'balance' => [
                'available' => $balanceAvailable,
                'pending' => $balancePending,
                'pendingTransactions' => $pendingWithdrawals,
            ],
            'recentTransactions' => $recentActivity,
            'withdrawals' => array_map(fn(WithdrawalRequest $w) => [
                'id' => $w->getId(),
                'amount' => $w->getAmount(),
                'status' => $w->getStatus(),
                'method' => $w->getMethod(),
                'createdAt' => $w->getCreatedAt()?->format('c'),
            ], $withdrawals),
            'chartLabels' => array_map(fn(string $date) => (new \DateTimeImmutable($date))->format('d M'), array_keys($dailyTotals)),
            'chartData' => array_values($dailyTotals),
            'breakdownLabels' => $breakdownLabels,
            'breakdownData' => $breakdownData,
            'savedReports' => $savedReports,
        ], Response::HTTP_OK);
    }

    #[Route('/api/reports', name: 'api_reports', methods: ['GET'])]
    public function listReports(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->buildSavedReports($user), Response::HTTP_OK);
    }

    #[Route('/api/reports/{id}/download', name: 'api_report_download', methods: ['GET'])]
    public function downloadReport(int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $reports = $this->buildSavedReports($user);
        $report = null;
        foreach ($reports as $item) {
            if ($item['id'] === $id) {
                $report = $item;
                break;
            }
        }

        if (!$report) {
            return new JsonResponse(['message' => 'Rapport non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        $content = sprintf(
            "Rapport: %s\nCatégorie: %s\nDate: %s\nStatut: %s\n\nContenu généré pour l'utilisateur %s\n",
            $report['title'],
            $report['type'],
            $report['date'],
            $report['status'],
            $user->getEmail(),
        );

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $report['fileName']),
        ]);
    }

    #[Route('/api/reports/generate', name: 'api_report_generate', methods: ['POST'])]
    public function generateReport(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $category = $data['category'] ?? 'all';
        $dateRange = $data['dateRange'] ?? '30';

        $reportTitle = sprintf('Rapport %s - %s', ucfirst($category), $dateRange);
        $fileName = sprintf('rapport_%s_%s.pdf', $category, $dateRange);
        $content = sprintf(
            "Rapport généré dynamiquement:\nCatégorie: %s\nPériode: %s\nUtilisateur: %s\nDate: %s\n\nContenu simulé pour génération dynamique.\n",
            ucfirst($category),
            $dateRange,
            $user->getEmail(),
            (new \DateTimeImmutable())->format('c')
        );

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName),
        ]);
    }

    private function buildSavedReports(User $user): array
    {
        $today = new \DateTimeImmutable();
        return [
            [
                'id' => 1,
                'title' => 'Rapport Financier Mensuel',
                'type' => 'financial',
                'date' => $today->format('d M Y'),
                'status' => 'Généré',
                'fileName' => 'rapport_financier_mensuel.pdf',
            ],
            [
                'id' => 2,
                'title' => 'Rapport Opérationnel',
                'type' => 'operational',
                'date' => $today->sub(new \DateInterval('P7D'))->format('d M Y'),
                'status' => 'Généré',
                'fileName' => 'rapport_operationnel.pdf',
            ],
            [
                'id' => 3,
                'title' => 'Rapport Passagers',
                'type' => 'passenger',
                'date' => $today->sub(new \DateInterval('P15D'))->format('d M Y'),
                'status' => 'Généré',
                'fileName' => 'rapport_passagers.pdf',
            ],
        ];
    }

    public function createWithdrawal(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $method = $data['paymentMethod'] ?? $data['method'] ?? null;
        $notes = $data['notes'] ?? null;

        if (!$amount || $amount <= 0) {
            return new JsonResponse(['message' => 'Montant invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$method) {
            return new JsonResponse(['message' => 'Méthode de retrait requise.'], Response::HTTP_BAD_REQUEST);
        }

        $wallet = $this->walletService->getOrCreateWallet($agency);
        $available = (float) $wallet->getAvailableBalance();

        if ($amount > $available) {
            return new JsonResponse([
                'message' => 'Solde insuffisant.',
                'available' => $available,
            ], Response::HTTP_BAD_REQUEST);
        }

        $withdrawal = new WithdrawalRequest();
        $withdrawal->setAgency($agency);
        $withdrawal->setRequestedBy($user);
        $withdrawal->setAmount((string) $amount);
        // NB : $method était auparavant tronqué à son premier caractère ($method[0]) — corrigé ici.
        $withdrawal->setMethod((string) $method);
        $withdrawal->setNotes($notes);
        $withdrawal->setStatus('pending');

        $this->em->persist($withdrawal);
        $this->em->flush();

        try {
            // Bloque immédiatement les fonds : tant que l'admin n'a pas statué, ce montant
            // n'est plus disponible pour une autre demande de retrait de la même agence.
            $this->walletService->reserveForWithdrawal($withdrawal);
        } catch (\RuntimeException $e) {
            // Cas rare de concurrence (deux demandes envoyées au même instant) :
            // on annule la demande plutôt que de laisser un retrait non couvert par le solde.
            $this->em->remove($withdrawal);
            $this->em->flush();
            return new JsonResponse([
                'message' => $e->getMessage(),
                'available' => (float) $wallet->getAvailableBalance(),
            ], Response::HTTP_CONFLICT);
        }

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'withdrawalId' => $withdrawal->getId(),
            'status' => $withdrawal->getStatus(),
            'available' => (float) $wallet->getAvailableBalance(),
            'pending' => (float) $wallet->getReservedBalance(),
        ], Response::HTTP_CREATED);
    }

    public function listWithdrawals(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        $withdrawals = $this->em->getRepository(WithdrawalRequest::class)->findBy(
            ['agency' => $agency],
            ['createdAt' => 'DESC']
        );

        return new JsonResponse(array_map(function (WithdrawalRequest $w) {
            return [
                'id' => $w->getId(),
                'amount' => $w->getAmount(),
                'method' => $w->getMethod(),
                'status' => $w->getStatus(),
                'notes' => $w->getNotes(),
                'adminNote' => $w->getAdminNote(),
                'processedAt' => $w->getProcessedAt()?->format('c'),
                'createdAt' => $w->getCreatedAt()?->format('c'),
            ];
        }, $withdrawals), Response::HTTP_OK);
    }

    public function getWithdrawal(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        $withdrawal = $this->em->getRepository(WithdrawalRequest::class)->find($id);
        if (!$withdrawal || $withdrawal->getAgency()?->getId() !== $agency->getId()) {
            return new JsonResponse(['message' => 'Non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $withdrawal->getId(),
            'amount' => $withdrawal->getAmount(),
            'method' => $withdrawal->getMethod(),
            'status' => $withdrawal->getStatus(),
            'notes' => $withdrawal->getNotes(),
            'adminNote' => $withdrawal->getAdminNote(),
            'processedAt' => $withdrawal->getProcessedAt()?->format('c'),
            'createdAt' => $withdrawal->getCreatedAt()?->format('c'),
        ], Response::HTTP_OK);
    }

    #[Route('/api/partner/transactions/stats', name: 'api_partner_transaction_stats', methods: ['GET'])]
    public function getTransactionStats(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        $query = $this->paymentLogRepository->createQueryBuilder('p')
            ->select('p.status as status, COUNT(p.id) as count, SUM(p.amount) as total')
            ->join('p.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->groupBy('p.status')
            ->setParameter('agency', $agency)
            ->getQuery();

        $stats = $query->getArrayResult();
        $grouped = [];
        foreach ($stats as $row) {
            $grouped[$row['status']] = [
                'count' => (int)$row['count'],
                'total' => (float)$row['total'],
            ];
        }

        return new JsonResponse(['transactionStats' => $grouped], Response::HTTP_OK);
    }

    #[Route('/api/revenue', name: 'api_revenue', methods: ['GET'])]
    public function getRevenue(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        $start = $request->query->get('start');
        $end = $request->query->get('end');

        $qb = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('r.transactionReference AS reference, r.totalAmount AS amount, r.paymentStatus AS status, r.createdAt AS createdAt')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->andWhere('r.paymentStatus = :paid')
            ->setParameter('paid', 'paye');

        if ($start) {
            $qb->andWhere('r.createdAt >= :start')->setParameter('start', new \DateTime($start));
        }
        if ($end) {
            $qb->andWhere('r.createdAt <= :end')->setParameter('end', new \DateTime($end));
        }

        $results = $qb->orderBy('r.createdAt', 'ASC')->getQuery()->getArrayResult();
        $series = [];
        foreach ($results as $row) {
            $dateKey = $row['createdAt']->format('Y-m-d');
            if (!isset($series[$dateKey])) {
                $series[$dateKey] = 0.0;
            }
            $series[$dateKey] += (float)$row['amount'];
        }

        return new JsonResponse([
            'labels' => array_keys($series),
            'data' => array_values($series),
            'totalRevenue' => array_sum($series),
        ], Response::HTTP_OK);
    }

       /**
     * Récupère les réservations récentes de l'agent (pour le dashboard)
     * Routes: /api/statistics/agent/recent-bookings ou /api/bookings/recent
     */
    #[Route('/api/agency/recent-bookings', name: 'agency_recent_bookings', methods: ['GET'])]
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
}
