<?php

namespace App\Controller\Partner;

use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\WithdrawalRequest;
use App\Entity\User;
use App\Entity\Agent;
use App\Entity\Trip;
use App\Repository\PaymentLogRepository;
use App\Repository\WithdrawalRequestRepository;
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
        private WithdrawalRequestRepository $withdrawalRepository
    ) {}

    /**
     * Récupère l'agence associée à l'utilisateur courant (Agent)
     */
    private function getAgencyForUser(User $user)
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

        // Récupérer l'agence de l'utilisateur (Agent)
        $agency = $this->getAgencyForUser($user);
        if (!$agency) {
            return new JsonResponse(['message' => 'Aucune agence associée.'], Response::HTTP_FORBIDDEN);
        }

        // Récupérer tous les trajets de cette agence
        $trips = $this->em->getRepository(Trip::class)->findBy(['agency' => $agency]);
        $tripIds = array_map(fn(Trip $t) => $t->getId(), $trips);

        // Récupérer toutes les réservations pour les trajets de cette agence
        if (empty($tripIds)) {
            // Si pas de trajets, retourner des stats vides
            $reservations = [];
        } else {
            $reservations = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->where('r.trip IN (:tripIds)')
                ->setParameter('tripIds', $tripIds)
                ->getQuery()
                ->getResult();
        }

        $totalRevenue = 0.0;
        $totalTrips = count($trips);
        $totalPassengers = 0;
        $balanceAvailable = 0.0;
        $balancePending = 0.0;
        $pendingWithdrawals = 0;

        foreach ($reservations as $reservation) {
            $totalRevenue += (float) $reservation->getTotalAmount();
            $totalPassengers += count($reservation->getTickets() ?? []);
            if ($reservation->getPaymentStatus() === 'paye') {
                $balanceAvailable += (float) $reservation->getTotalAmount();
            } else {
                $balancePending += (float) $reservation->getTotalAmount();
            }
        }

        $withdrawals = $this->withdrawalRepository->findByUser($user);
        foreach ($withdrawals as $withdrawal) {
            if ($withdrawal->getStatus() === 'pending') {
                $pendingWithdrawals++;
            }
            if ($withdrawal->getStatus() === 'approved') {
                $balanceAvailable -= (float) $withdrawal->getAmount();
            }
        }

        // Transactions récentes pour les réservations de cette agence
        $recentTransactions = $this->paymentLogRepository->createQueryBuilder('p')
            ->join('p.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $recentActivity = array_map(function (PaymentLog $log) {
            return [
                'id' => $log->getId(),
                'description' => sprintf('Paiement %s via %s', $log->getStatus(), $log->getOperator()),
                'amount' => $log->getAmount(),
                'status' => $log->getStatus(),
                'reference' => $log->getReference(),
                'createdAt' => $log->getCreatedAt()?->format('c'),
            ];
        }, $recentTransactions);

        $dailyTotals = [];
        $today = new \DateTimeImmutable();
        for ($i = 5; $i >= 0; $i--) {
            $date = $today->sub(new \DateInterval("P{$i}D"));
            $dailyTotals[$date->format('Y-m-d')] = 0.0;
        }

        // Transactions par jour pour cette agence
        $transactionsByDay = $this->paymentLogRepository->createQueryBuilder('p')
            ->select('DATE(p.createdAt) as day, SUM(p.amount) as total')
            ->join('p.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('p.createdAt >= :start')
            ->setParameter('agency', $agency)
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
            'revenue' => $totalRevenue,
            'revenueChange' => '0%',
            'activeTrips' => $totalTrips,
            'totalPassengers' => $totalPassengers,
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

        // Récupérer l'agence
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

        // Calculer le solde disponible basé sur les trajets et réservations de l'agence
        $trips = $this->em->getRepository(Trip::class)->findBy(['agency' => $agency]);
        $tripIds = array_map(fn(Trip $t) => $t->getId(), $trips);

        $balanceAvailable = 0.0;
        if (!empty($tripIds)) {
            $reservations = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->where('r.trip IN (:tripIds)')
                ->andWhere('r.paymentStatus = :paid')
                ->setParameter('tripIds', $tripIds)
                ->setParameter('paid', 'paye')
                ->getQuery()
                ->getResult();

            foreach ($reservations as $reservation) {
                $balanceAvailable += (float) $reservation->getTotalAmount();
            }
        }

        // Déduire les retraits approuvés
        $withdrawals = $this->withdrawalRepository->findByUser($user);
        foreach ($withdrawals as $w) {
            if ($w->getStatus() === 'approved') {
                $balanceAvailable -= (float) $w->getAmount();
            }
        }

        if ($amount > $balanceAvailable) {
            return new JsonResponse([
                'message' => 'Solde insuffisant.',
                'available' => $balanceAvailable,
            ], Response::HTTP_BAD_REQUEST);
        }

        // return $this->json($method[0]);

        $withdrawal = new WithdrawalRequest();
        $withdrawal->setUser($user);
        $withdrawal->setAmount((string)$amount);
        $withdrawal->setMethod($method[0]);
        $withdrawal->setNotes($notes);
        $withdrawal->setStatus('pending');

        $this->em->persist($withdrawal);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'withdrawalId' => $withdrawal->getId(),
            'status' => $withdrawal->getStatus(),
            'available' => $balanceAvailable - (float)$withdrawal->getAmount(),
        ], Response::HTTP_CREATED);
    }

    public function listWithdrawals(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $withdrawals = $this->withdrawalRepository->findByUser($user);
        return new JsonResponse(array_map(function (WithdrawalRequest $w) {
            return [
                'id' => $w->getId(),
                'amount' => $w->getAmount(),
                'method' => $w->getMethod(),
                'status' => $w->getStatus(),
                'notes' => $w->getNotes(),
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

        $withdrawal = $this->withdrawalRepository->find($id);
        if (!$withdrawal || $withdrawal->getUser()->getId() !== $user->getId()) {
            return new JsonResponse(['message' => 'Non trouvé.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $withdrawal->getId(),
            'amount' => $withdrawal->getAmount(),
            'method' => $withdrawal->getMethod(),
            'status' => $withdrawal->getStatus(),
            'notes' => $withdrawal->getNotes(),
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

        // Récupérer l'agence
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

        // Récupérer l'agence
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
}
