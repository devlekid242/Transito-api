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
        private TicketRepository $ticketRepository,
        private \App\Service\AdminNotificationService $adminNotificationService
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
    public function getPartnerStats(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $idempotencyKey)) {
            return new JsonResponse(['error' => 'Idempotency-Key invalide.'], 400);
        }

        if ($idempotencyKey !== '') {
            $existingWithdrawal = $this->em->getRepository(WithdrawalRequest::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
            if ($existingWithdrawal) {
                if (
                    $existingWithdrawal->getAgency()?->getId() !== $agency->getId()
                    || $existingWithdrawal->getRequestedBy()?->getId() !== $user->getId()
                ) {
                    return new JsonResponse(['message' => 'Cette Idempotency-Key est déjà utilisée pour une autre opération.'], Response::HTTP_CONFLICT);
                }
                return new JsonResponse([
                    'success' => true,
                    'withdrawalId' => $existingWithdrawal->getId(),
                    'status' => $existingWithdrawal->getStatus(),
                    'idempotent' => true,
                ], Response::HTTP_OK);
            }
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
        $reservationIds = array_map(fn(Reservation $r) => $r->getId(), $reservations);

        // ------------------------------------------------------------------
        // Classification des réservations
        // ------------------------------------------------------------------
        // r.paymentStatus bascule sur 'rembourse' DÈS l'annulation côté client
        // (BookingController::cancel()), AVANT que l'admin ait réellement
        // traité le remboursement (PaymentController::refund(), qui seul
        // débite le wallet via WalletService::debitForRefund()). Entre les
        // deux, un PaymentLog de remboursement existe avec le statut
        // 'REFUND_PENDING' : l'argent est toujours dans available_balance
        // mais en sursis. Sans distinguer ce cas, les stats donnaient
        // l'impression qu'un solde était "propre" alors qu'une partie est
        // en réalité déjà promise à un remboursement.
        //
        // On récupère donc, en une seule requête, le dernier PaymentLog de
        // type remboursement (REFUND_PENDING ou REFUNDED) pour chaque
        // réservation annulée de cette agence.
        $refundStatusByReservation = [];
        if (!empty($reservationIds)) {
            $refundLogs = $this->em->getRepository(PaymentLog::class)->createQueryBuilder('pl')
                ->select('IDENTITY(pl.reservation) as reservationId, pl.status as status')
                ->where('pl.reservation IN (:ids)')
                ->andWhere('pl.status IN (:statuses)')
                ->setParameter('ids', $reservationIds)
                ->setParameter('statuses', ['REFUND_PENDING', 'REFUNDED', 'REFUNDED_COMPLETED'])
                ->getQuery()
                ->getArrayResult();
            foreach ($refundLogs as $row) {
                // REFUND_PENDING doit l'emporter si les deux existent pour une
                // même réservation (ne devrait pas arriver en pratique, mais
                // on privilégie l'état le plus "prudent").
                $reservationId = (int) $row['reservationId'];
                if (!isset($refundStatusByReservation[$reservationId]) || $row['status'] === 'REFUND_PENDING') {
                    $refundStatusByReservation[$reservationId] = $row['status'];
                }
            }
        }

        $grossRevenue = '0.00';      // Montant des réservations actuellement confirmées (payées, non annulées)
        $activeReservationIds = []; // Sert de périmètre cohérent pour le calcul de la commission plateforme

        $reservationsByStatus = [
            'enAttentePaiement' => 0,             // paiement pas encore confirmé
            'confirmees' => 0,                    // payées et toujours actives
            'echouees' => 0,                      // paiement en échec
            'annuleesRemboursementEnAttente' => 0, // annulées, remboursement pas encore traité par l'admin
            'annuleesRembourseesConfirmees' => 0,  // annulées, remboursement déjà versé par l'admin
            'annuleesSansPaiementPrealable' => 0,  // annulées mais aucun paiement n'avait été effectué
        ];
        
        foreach ($reservations as $reservation) {
            $status = $reservation->getPaymentStatus();
            $id = $reservation->getId();

            switch ($status) {
                case 'paye':
                    $reservationsByStatus['confirmees']++;
                    $grossRevenue = bcadd($grossRevenue, (string) $reservation->getTotalAmount(), 2);
                    $activeReservationIds[] = $id;
                    break;

                case 'en_attente':
                    $reservationsByStatus['enAttentePaiement']++;
                    break;

                case 'echoue':
                    $reservationsByStatus['echouees']++;
                    break;

                case 'rembourse':
                    $refundStatus = $refundStatusByReservation[$id] ?? null;
                    
                    if ($refundStatus === 'REFUND_PENDING') {
                        $reservationsByStatus['annuleesRemboursementEnAttente']++;
                    } elseif ($refundStatus === 'REFUNDED' ) {
                        $reservationsByStatus['annuleesRembourseesConfirmees']++;
                    } elseif ($refundStatus === 'REFUNDED_COMPLETED') {
                        $reservationsByStatus['annuleesRembourseesConfirmees']++;
                    } else {
                        // Annulée sans qu'aucun paiement n'ait été confirmé au préalable
                        // (voir BookingController::cancel() : $wasPaid === false) : rien à
                        // rembourser, donc pas de PaymentLog de remboursement.
                        $reservationsByStatus['annuleesSansPaiementPrealable']++;
                    }
                    break;
            }
        }



        // Montant actuellement dans available_balance mais qui sera débité dès
        // que l'admin traitera les remboursements en attente : à afficher comme
        // un solde "à risque", distinct du solde bloqué pour retrait (reserved).
        $pendingRefundsAmount = (string) ($this->em->getRepository(PaymentLog::class)->createQueryBuilder('pl')
            ->select('COALESCE(SUM(pl.amount), 0)')
            ->join('pl.reservation', 'r2')
            ->join('r2.trip', 't2')
            ->where('t2.agency = :agency')
            ->andWhere('pl.status = :pendingStatus')
            ->setParameter('agency', $agency)
            ->setParameter('pendingStatus', 'REFUND_PENDING')
            ->getQuery()
            ->getSingleScalarResult());
        $pendingRefundsAmount = bcadd((string) $pendingRefundsAmount, '0.00', 2);

        // La commission plateforme est calculée UNIQUEMENT sur le périmètre des
        // réservations actuellement actives (confirmées et non annulées) : c'est
        // exactement le même périmètre que $grossRevenue, ce qui garantit que
        // netRevenue = grossRevenue - platformFees reste cohérent. Inclure les
        // commissions de réservations depuis annulées gonflerait artificiellement
        // les frais déduits par rapport au chiffre d'affaires réellement actif.
        if (empty($activeReservationIds)) {
            $platformFees = '0.00';
        } else {
            $platformFees = (string) $this->em->getRepository(WalletTransaction::class)->createQueryBuilder('wt')
                ->select('COALESCE(SUM(wt.amount), 0) as total')
                ->join('wt.wallet', 'w')
                ->where('wt.source = :source')
                ->andWhere('wt.reservation IN (:reservationIds)')
                ->andWhere('w.type = :platformType')
                ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
                ->setParameter('reservationIds', $activeReservationIds)
                ->setParameter('platformType', Wallet::TYPE_PLATFORM)
                ->getQuery()
                ->getSingleScalarResult();
            $platformFees = bcadd((string) $platformFees, '0.00', 2);
        }

        $netRevenue = bcsub($grossRevenue, $platformFees, 2);
        if (bccomp($netRevenue, '0.00', 2) < 0) {
            $netRevenue = '0.00';
        }

        // Les billets annulés ('annule', produits par BookingController::cancel())
        // ne doivent compter ni dans le nombre de passagers "actifs" ni dans le
        // dénominateur du taux d'embarquement : ce sont des sièges libérés, pas
        // des passagers en attente d'embarquement. On les isole explicitement au
        // lieu de les mélanger avec les billets 'en_attente' réellement à venir.
        $ticketStats = $this->em->getRepository(Ticket::class)->createQueryBuilder('tk')
            ->select(
                'SUM(CASE WHEN tk.status = :boarded THEN 1 ELSE 0 END) as boardedCount, ' .
                    'SUM(CASE WHEN tk.status = :cancelled THEN 1 ELSE 0 END) as cancelledCount, ' .
                    'COUNT(tk.id) as totalCount'
            )
            ->join('tk.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->setParameter('boarded', 'embarque')
            ->setParameter('cancelled', 'annule')
            ->getQuery()
            ->getOneOrNullResult();

        $boardedCount = (int) ($ticketStats['boardedCount'] ?? 0);
        $cancelledTicketCount = (int) ($ticketStats['cancelledCount'] ?? 0);
        $ticketCount = (int) ($ticketStats['totalCount'] ?? 0);
        // Univers pertinent pour le taux d'embarquement : billets encore valides
        // (en_attente + embarque), les annulés étant hors-jeu par définition.
        $validTicketCount = $ticketCount - $cancelledTicketCount;
        $boardingRate = $validTicketCount > 0 ? round(($boardedCount / $validTicketCount) * 100, 2) : 0.0;

        $totalPassengers = $ticketCount;       // Historique complet (tous billets jamais émis)
        $activePassengers = $validTicketCount; // Billets encore valides (hors annulations)

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
                ? (string) $tx->getAmount()
                : bcsub('0.00', (string) $tx->getAmount(), 2);

            $status = $tx->getSource() === WalletTransaction::SOURCE_WITHDRAWAL_HOLD
                ? 'En cours'
                : 'Terminé';

            return [
                'id' => $tx->getId(),
                'description' => $tx->getDescription(),
                'amount' => $signedAmount,
                'type' => $tx->getType(),
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
            $dailyTotals[$date->format('Y-m-d')] = '0.00';
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
                $dailyTotals[$row['day']] = bcadd((string) $row['total'], '0.00', 2);
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
            'activePassengers' => $activePassengers,
            'boardingRate' => $boardingRate,
            // Détail des réservations par statut réel, en distinguant explicitement
            // les annulations dont le remboursement est encore en attente de
            // traitement par l'admin de celles déjà soldées (voir classification
            // plus haut). Permet au partenaire de comprendre pourquoi son solde
            // disponible peut encore inclure de l'argent "en sursis".
            'reservationsByStatus' => $reservationsByStatus,
            'balance' => [
                'available' => $wallet->getAvailableBalance(),
                'blocked' => $wallet->getBlockedBalance(),
                'reserved' => $wallet->getReservedBalance(),
                'pending' => $wallet->getReservedBalance(),
                'total' => $wallet->getTotalBalance(),
                'totalEarned' => $wallet->getTotalEarned(),
                'totalWithdrawn' => $wallet->getTotalWithdrawn(),
                'atRisk' => $pendingRefundsAmount,
                'pendingTransactions' => $pendingWithdrawals,
            ],
            'recentTransactions' => $recentActivity,
            'withdrawals' => array_map(fn(WithdrawalRequest $w) => [
                'id' => $w->getId(),
                'amount' => round((float) $w->getAmount(), 2),
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

    // #[Route('/api/partner/revenue-chart', name: 'api_partner_revenue_chart', methods: ['GET'])]
    // public function getRevenueChart(Request $request): JsonResponse
    // {
    //     $user = $this->getUser();
    //     if (!$user instanceof User) {
    //         return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
    //     }

    //     $agency = $this->getAgencyForUser($user);
    //     if (!$agency) {
    //         return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
    //     }

    //     $period = $request->query->get('period', '7j');
    //     $validPeriods = ['7j', '30j', '90j'];
    //     if (!in_array($period, $validPeriods)) {
    //         return new JsonResponse(['message' => 'Période invalide.'], Response::HTTP_BAD_REQUEST);
    //     }

    //     // formater la période en nombre de jours pour la requête
    //     $days = (int) substr($period, 0, -1);

    //     $today = new \DateTimeImmutable();
    //     $startDate = $today->sub(new \DateInterval("P{$days}D"))->setTime(0, 0, 0);

    // }


    #[Route('/api/payment-methods', name: 'api_payment_methods', methods: ['GET'])]
    public function listPaymentMethods(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $paymentMethods = $this->getPaymentMethods($user);

        return new JsonResponse($paymentMethods, Response::HTTP_OK);
    }

    private function getPaymentMethods(User $user): array
    {
        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return [];
        }

        // Récupérer les méthodes de paiement disponibles pour l'agence
        // (exemple statique ici, à adapter selon votre logique métier)
        return [
            ['id' => 'bank_transfer', 'name' => 'Virement bancaire'],
            ['id' => 'MTN_MOMO', 'name' => 'MTN Mobile Money'],
            ['id' => 'AIRTEL_MOMO', 'name' => 'Airtel Money'],
        ];
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

        $amount = isset($data['amount']) && is_numeric($data['amount']) ? number_format((float) $data['amount'], 2, '.', '') : null;
        $method = $data['paymentMethod'] ?? $data['method'] ?? null;
        $notes = $data['notes'] ?? null;
        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $idempotencyKey)) {
            return new JsonResponse(['message' => 'Idempotency-Key invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if ($amount === null || bccomp($amount, '0.00', 2) <= 0) {
            return new JsonResponse(['message' => 'Montant invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$method) {
            return new JsonResponse(['message' => 'Méthode de retrait requise.'], Response::HTTP_BAD_REQUEST);
        }

        $wallet = $this->walletService->getOrCreateWallet($agency);
        $available = (float) $wallet->getAvailableBalance();
        $pendingRefundAmount = $this->getPendingRefundAmount($agency);
        $effectiveAvailable = max(0.0, $available - $pendingRefundAmount);

        if (bccomp($amount, number_format($effectiveAvailable, 2, '.', ''), 2) === 1) {
            return new JsonResponse([
                'message' => 'Retrait bloqué : votre solde disponible est déjà engagé par des remboursements en attente de réservation.',
                'available' => $available,
                'pendingRefunds' => round($pendingRefundAmount, 2),
                'effectiveAvailable' => round($effectiveAvailable, 2),
            ], Response::HTTP_BAD_REQUEST);
        }

        $withdrawal = new WithdrawalRequest();
        $withdrawal->setAgency($agency);
        $withdrawal->setRequestedBy($user);
        $withdrawal->setIdempotencyKey($idempotencyKey !== '' ? $idempotencyKey : null);
        $withdrawal->setAmount((string) $amount);
        // NB : $method était auparavant tronqué à son premier caractère ($method[0]) — corrigé ici.
        $withdrawal->setMethod((string) $method);
        $withdrawal->setNotes($notes);
        $withdrawal->setStatus('pending');

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            // Verrouille le wallet pendant la vérification + réservation afin que
            // deux demandes concurrentes ne puissent consommer le même disponible.
            $lockedWallet = $this->em->getRepository(Wallet::class)
                ->find($wallet->getId(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);

            if (!$lockedWallet) {
                throw new \RuntimeException('Portefeuille agence introuvable.');
            }

            $wallet = $lockedWallet;
            $available = $wallet->getAvailableBalance();
            $effectiveAvailable = bcsub($available, number_format($pendingRefundAmount, 2, '.', ''), 2);
            if (bccomp($amount, $effectiveAvailable, 2) === 1) {
                throw new \RuntimeException('Solde disponible insuffisant après prise en compte des remboursements en attente.');
            }

            $this->em->persist($withdrawal);
            $this->em->flush();
            $this->walletService->reserveForWithdrawal($withdrawal);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            return new JsonResponse([
                'message' => $e->getMessage(),
                'available' => (float) $wallet->getAvailableBalance(),
            ], Response::HTTP_CONFLICT);
        }

        $this->adminNotificationService->notifyEvent(
            'Nouvelle demande de retrait',
            sprintf('L’agence %s demande un retrait de %s FCFA.', $agency->getName(), $amount),
            'FINANCE',
            ['type' => 'withdrawal', 'withdrawalId' => $withdrawal->getId(), 'status' => 'pending', 'agencyId' => $agency->getId()]
        );

        return new JsonResponse([
            'success' => true,
            'withdrawalId' => $withdrawal->getId(),
            'status' => $withdrawal->getStatus(),
            'available' => (float) $wallet->getAvailableBalance(),
            'pending' => (float) $wallet->getReservedBalance(),
        ], Response::HTTP_CREATED);
    }

    private function getPendingRefundAmount(Agency $agency): float
    {
        $pendingRefundAmount = $this->em->getRepository(PaymentLog::class)->createQueryBuilder('pl')
            ->select('COALESCE(SUM(pl.amount), 0)')
            ->join('pl.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('pl.status = :pendingStatus')
            ->setParameter('agency', $agency)
            ->setParameter('pendingStatus', 'REFUND_PENDING')
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $pendingRefundAmount, 2);
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

    #[Route('/api/transaction-types', name: 'api_transaction_types', methods: ['GET'])]
    public function getTransactionTypes(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        // $transactionTypes = [
        //     ['id' => 'CREDIT', 'name' => 'Crédit'],
        //     ['id' => 'DEBIT', 'name' => 'Débit'],
        // ];

        $transactionTypes = [
            ['id' => 'CREDIT', 'name' => 'Crédit'],
            ['id' => 'DEBIT', 'name' => 'Débit'],

            // Crédit suite au paiement confirmé d'une réservation (montant brut)
            ['id' => 'RESERVATION_PAYMENT', 'name' => 'Paiement de réservation'],

            // Crédit correspondant à la commission plateforme encaissée sur un paiement
            ['id' => 'PLATFORM_FEE', 'name' => 'Commission plateforme'],

            // Débit suite au remboursement d'une réservation déjà créditée
            ['id' => 'REFUND', 'name' => 'Remboursement'],

            // Déblocage des fonds suite à la validation du billet à l'embarquement (Solde Bloqué -> Solde Disponible)
            ['id' => 'TICKET_BOARDED', 'name' => 'Validation du billet (Embarquement)'],

            // Débit définitif du solde bloqué lorsqu'un passager ne se présente pas
            ['id' => 'TICKET_NO_SHOW', 'name' => 'Passager non présent (No-show)'],

            // Crédit correspondant au montant net conservé par la plateforme après no-show
            ['id' => 'NO_SHOW_REVENUE', 'name' => 'Revenu issu d\'un no-show'],

            // Débit : fonds gelés le temps qu'une demande de retrait soit traitée
            ['id' => 'WITHDRAWAL_HOLD', 'name' => 'Retrait en attente (Fonds gelés)'],

            // Débit définitif : le retrait a été approuvé et versé
            ['id' => 'WITHDRAWAL_COMPLETED', 'name' => 'Retrait effectué'],

            // Crédit : la demande de retrait a été rejetée, les fonds reviennent au solde disponible
            ['id' => 'WITHDRAWAL_RELEASED', 'name' => 'Retrait rejeté (Fonds libérés)'],

            // Ajustement manuel (litige, correction...)
            ['id' => 'ADJUSTMENT', 'name' => 'Ajustement manuel'],

            // Ajustement manuel par admin (crédit ou débit manuel)
            ['id' => 'ADMIN_CREDIT', 'name' => 'Crédit administratif'],
            ['id' => 'ADMIN_DEBIT', 'name' => 'Débit administratif'],
            ['id' => 'RESCHEDULE_ADJUSTMENT', 'name' => 'Ajustement suite au report'],

            // Gel/dégel de portefeuille
            ['id' => 'WALLET_FREEZE', 'name' => 'Gel du portefeuille'],
            ['id' => 'WALLET_UNFREEZE', 'name' => 'Dégel du portefeuille'],
        ];



        return new JsonResponse($transactionTypes, 200);
    }
}
