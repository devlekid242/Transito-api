<?php

namespace App\Repository;

use App\Entity\Wallet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wallet>
 */
class WalletRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wallet::class);
    }

    /**
     * Get total reserved balance across all wallets.
     */
    public function getTotalReservedBalance(): string
    {
        $result = $this->createQueryBuilder('w')
            ->select('SUM(w.reservedBalance) as total')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0.00';
    }

    /**
     * Get total available balance across all agency wallets.
     */
    public function getTotalAvailableBalance(): string
    {
        $result = $this->createQueryBuilder('w')
            ->select('SUM(w.availableBalance) as total')
            ->where('w.type = :type')
            ->setParameter('type', Wallet::TYPE_AGENCY)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0.00';
    }

    /**
     * Find agencies with low available balance.
     */
    public function findAgenciesWithLowBalance(float $threshold): array
    {
        return $this->createQueryBuilder('w')
            ->select('w', 'a')
            ->join('w.agency', 'a')
            ->where('w.type = :type')
            ->andWhere('w.availableBalance < :threshold')
            ->setParameter('type', Wallet::TYPE_AGENCY)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total blocked balance across all agency wallets.
     * This requires calculation using RefundRequest and Ticket repositories.
     */
    public function getTotalBlockedBalance(
        RefundRequestRepository $refundRequestRepository,
        TicketRepository $ticketRepository
    ): float {
        // On sélectionne l'alias racine (w) ainsi que l'agence jointe (a)
        $wallets = $this->createQueryBuilder('w')
            ->select('w', 'a')
            ->join('w.agency', 'a')
            ->where('w.type = :type')
            ->setParameter('type', Wallet::TYPE_AGENCY)
            ->getQuery()
            ->getResult();

        $totalBlocked = 0.0;
        $processedAgencyIds = [];

        foreach ($wallets as $wallet) {
            $agency = $wallet->getAgency();

            // On s'assure de ne traiter chaque agence qu'une seule fois
            if ($agency && !in_array($agency->getId(), $processedAgencyIds, true)) {
                $processedAgencyIds[] = $agency->getId();

                // Sum pending refunds for this agency
                $pendingRefunds = $refundRequestRepository->getPendingRefundsAmountForAgency($agency);
                $totalBlocked += $pendingRefunds;

                // Sum unvalidated tickets for this agency  
                $unvalidatedTickets = $ticketRepository->getUnvalidatedTicketsAmountForAgency($agency);
                $totalBlocked += $unvalidatedTickets;
            }
        }

        return round($totalBlocked, 2);
    }

    /**
     * Get all agency wallets with their balance summaries
     */
    public function findAllAgencyWalletsWithSummary(
        RefundRequestRepository $refundRequestRepository,
        TicketRepository $ticketRepository,
        WithdrawalRequestRepository $withdrawalRequestRepository
    ): array {
        $wallets = $this->createQueryBuilder('w')
            ->select('w', 'a')
            ->join('w.agency', 'a')
            ->where('w.type = :type')
            ->setParameter('type', Wallet::TYPE_AGENCY)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($wallets as $walletItem) {
            $wallet = $walletItem;
            $agency = $wallet->getAgency();

            if ($agency) {
                // Calculate blocked balance
                $pendingRefunds = $refundRequestRepository->getPendingRefundsAmountForAgency($agency);
                $unvalidatedTickets = $ticketRepository->getUnvalidatedTicketsAmountForAgency($agency);
                $blockedBalance = $pendingRefunds + $unvalidatedTickets;

                // Get reserved balance (from pending withdrawals)
                $pendingWithdrawalsAmount = $withdrawalRequestRepository->getPendingWithdrawalsAmountForAgency($agency);

                $result[] = [
                    'wallet' => $wallet,
                    'agency' => $agency,
                    'available' => (float) $wallet->getAvailableBalance(),
                    'reserved' => (float) $wallet->getReservedBalance(),
                    'blocked' => $blockedBalance,
                    'total' => (float) bcadd($wallet->getAvailableBalance(), $wallet->getReservedBalance(), 2),
                    'frozen' => $wallet->isFrozen(),
                    'pendingWithdrawals' => (float) $pendingWithdrawalsAmount,
                    'pendingRefunds' => $pendingRefunds,
                    'unvalidatedTickets' => $unvalidatedTickets,
                ];
            }
        }

        return $result;
    }
}
