<?php

namespace App\Controller\Partner;

use App\Entity\Agent;
use App\Entity\Agency;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\WalletTransaction;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/partner/analytics')]
class PartnerAnalyticsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
    ) {}

    #[Route('', name: 'api_partner_analytics', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Aucune agence associée au compte.'], Response::HTTP_FORBIDDEN);
        }

        [$from, $to] = $this->dateRange($request);
        $wallet = $this->walletService->getOrCreateWallet($agency);

        $transactions = $this->em->getRepository(WalletTransaction::class)->createQueryBuilder('wt')
            ->where('wt.wallet = :wallet')
            ->andWhere('wt.createdAt >= :from')
            ->andWhere('wt.createdAt < :to')
            ->setParameter('wallet', $wallet)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('wt.createdAt', 'ASC')
            ->getQuery()->getResult();

        $sales = '0.00';
        $refunds = '0.00';
        $noShows = '0.00';
        $withdrawn = '0.00';
        $credits = '0.00';
        $debits = '0.00';
        $daily = [];

        foreach ($transactions as $tx) {
            $amount = $tx->getAmount() ?? '0.00';
            $source = $tx->getSource();
            $type = $tx->getType();

            if ($type === WalletTransaction::TYPE_CREDIT) {
                $credits = bcadd($credits, $amount, 2);
            } else {
                $debits = bcadd($debits, $amount, 2);
            }

            if ($source === WalletTransaction::SOURCE_RESERVATION_PAYMENT) {
                $sales = bcadd($sales, $amount, 2);
            } elseif ($source === WalletTransaction::SOURCE_REFUND) {
                $refunds = bcadd($refunds, $amount, 2);
            } elseif ($source === WalletTransaction::SOURCE_TICKET_NO_SHOW) {
                $noShows = bcadd($noShows, $amount, 2);
            } elseif ($source === WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED) {
                $withdrawn = bcadd($withdrawn, $amount, 2);
            }

            $day = $tx->getCreatedAt()?->format('Y-m-d');
            if ($day) {
                if (!isset($daily[$day])) {
                    $daily[$day] = '0.00';
                }
                if ($source === WalletTransaction::SOURCE_RESERVATION_PAYMENT) {
                    $daily[$day] = bcadd($daily[$day], $amount, 2);
                } elseif ($source === WalletTransaction::SOURCE_REFUND || $source === WalletTransaction::SOURCE_TICKET_NO_SHOW) {
                    $daily[$day] = bcsub($daily[$day], $amount, 2);
                }
            }
        }

        // "earned" est la vente nette réellement conservée par l'agence.
        // Il est dérivé du ledger agence : frais plateforme déjà retirés à l'entrée,
        // remboursements et no-show ensuite débités du portefeuille.
        $netEarned = bcsub(bcsub($sales, $refunds, 2), $noShows, 2);
        if (bccomp($netEarned, '0.00', 2) < 0) {
            $netEarned = '0.00';
        }

        $tripStats = $this->tripStats($agency, $from, $to);
        $ticketStats = $this->ticketStats($agency, $from, $to);

        $fillRate = $ticketStats['capacity'] > 0
            ? round(($ticketStats['occupied'] / $ticketStats['capacity']) * 100, 2)
            : 0.0;

        ksort($daily);

        return $this->json([
            'period' => [
                'from' => $from->format('c'),
                'to' => $to->format('c'),
            ],
            'finance' => [
                'salesNetOfPlatformFee' => $sales,
                'refunds' => $refunds,
                'noShowLoss' => $noShows,
                'netEarned' => $netEarned,
                'withdrawn' => $withdrawn,
                'ledgerCredits' => $credits,
                'ledgerDebits' => $debits,
                'wallet' => [
                    'available' => $wallet->getAvailableBalance(),
                    'blocked' => $wallet->getBlockedBalance(),
                    'reserved' => $wallet->getReservedBalance(),
                    'total' => $wallet->getTotalBalance(),
                ],
            ],
            'operations' => [
                'trips' => $tripStats,
                'tickets' => $ticketStats,
                'fillRate' => $fillRate,
            ],
            'dailyNetSales' => $daily,
        ]);
    }

    #[Route('/routes', name: 'api_partner_analytics_routes', methods: ['GET'])]
    public function routes(Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Aucune agence associée au compte.'], Response::HTTP_FORBIDDEN);
        }
        [$from, $to] = $this->dateRange($request);

        $rows = $this->em->getRepository(Ticket::class)->createQueryBuilder('tk')
            ->select('IDENTITY(t.agency) as agencyId, t.departureCity as departure, t.arrivalCity as arrival, COUNT(tk.id) as tickets, COALESCE(SUM(tk.settlementAmount), 0) as revenue')
            ->join('tk.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('t.departureTime >= :from')
            ->andWhere('t.departureTime < :to')
            ->andWhere('tk.status != :cancelled')
            ->setParameter('agency', $agency)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('cancelled', 'annule')
            ->groupBy('t.departureCity, t.arrivalCity')
            ->orderBy('tickets', 'DESC')
            ->getQuery()->getArrayResult();

        return $this->json(array_map(static fn(array $row) => [
            'departure' => $row['departure'],
            'arrival' => $row['arrival'],
            'tickets' => (int) $row['tickets'],
            // Montant net revenant à l'agence, issu du settlement du billet.
            // Le frais plateforme de 500 FCFA n'est donc pas inclus.
            'revenue' => (string) ($row['revenue'] ?? '0.00'),
        ], $rows));
    }

    private function tripStats(Agency $agency, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->em->getRepository(Trip::class)->createQueryBuilder('t')
            ->select('t.status as status, COUNT(t.id) as count')
            ->where('t.agency = :agency')
            ->andWhere('t.departureTime >= :from')
            ->andWhere('t.departureTime < :to')
            ->setParameter('agency', $agency)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('t.status')
            ->getQuery()->getArrayResult();

        $result = [
            'total' => 0,
            'planifie' => 0,
            'embarquement' => 0,
            'en_route' => 0,
            'termine' => 0,
            'annule' => 0,
        ];
        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $result['total'] += $count;
            if (array_key_exists($row['status'], $result)) {
                $result[$row['status']] = $count;
            }
        }
        return $result;
    }

    private function ticketStats(Agency $agency, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->em->getRepository(Ticket::class)->createQueryBuilder('tk')
            ->select('tk.status as status, COUNT(tk.id) as count')
            ->join('tk.reservation', 'r')
            ->join('r.trip', 't')
            ->where('t.agency = :agency')
            ->andWhere('t.departureTime >= :from')
            ->andWhere('t.departureTime < :to')
            ->setParameter('agency', $agency)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('tk.status')
            ->getQuery()->getArrayResult();

        $stats = ['total' => 0, 'occupied' => 0, 'boarded' => 0, 'noShow' => 0, 'cancelled' => 0, 'capacity' => 0];
        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $stats['total'] += $count;
            if ($row['status'] === 'embarque') $stats['boarded'] = $count;
            if ($row['status'] === 'no_show') $stats['noShow'] = $count;
            if ($row['status'] === 'annule') $stats['cancelled'] = $count;
        }
        $stats['occupied'] = $stats['total'] - $stats['cancelled'];

        $trips = $this->em->getRepository(Trip::class)->createQueryBuilder('t')
            ->select('b.capacity AS capacity')
            ->join('t.bus', 'b')
            ->where('t.agency = :agency')
            ->andWhere('t.departureTime >= :from')
            ->andWhere('t.departureTime < :to')
            ->setParameter('agency', $agency)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        foreach ($trips as $trip) {
            $stats['capacity'] += (int) ($trip['capacity'] ?? 0);
        }
        return $stats;
    }

    private function dateRange(Request $request): array
    {
        $to = new \DateTimeImmutable('now');
        $from = $to->sub(new \DateInterval('P30D'));
        if ($request->query->has('from')) {
            try {
                $from = new \DateTimeImmutable($request->query->get('from'));
            } catch (\Throwable) {
            }
        }
        if ($request->query->has('to')) {
            try {
                $to = new \DateTimeImmutable($request->query->get('to'));
            } catch (\Throwable) {
            }
        }
        if ($to <= $from) {
            $to = $from->add(new \DateInterval('P30D'));
        }
        return [$from, $to];
    }

    private function getAuthenticatedAgency(): ?Agency
    {
        $user = $this->getUser();
        if (!$user instanceof User) return null;
        return $this->em->getRepository(Agent::class)->findOneBy(['user' => $user])?->getAgency();
    }
}
