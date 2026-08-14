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
     * Get total balance across all wallets (available + reserved + blocked).
     */
    public function getTotalBalance(): string
    {
        $result = $this->createQueryBuilder('w')
            ->select('SUM(w.reservedBalance + w.availableBalance + w.blockedBalance) as total')
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


    /** Return the platform wallet balance pockets without mixing agency funds. */
    public function getPlatformWalletSummary(): array
    {
        $row = $this->createQueryBuilder('w')
            ->select('w.id AS id, w.availableBalance AS available, w.blockedBalance AS blocked, w.reservedBalance AS reserved, w.totalEarned AS earned, w.totalWithdrawn AS withdrawn')
            ->where('w.type = :type')
            ->setParameter('type', Wallet::TYPE_PLATFORM)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$row) {
            return [
                'id' => null, 'available' => '0.00', 'blocked' => '0.00',
                'reserved' => '0.00', 'earned' => '0.00', 'withdrawn' => '0.00',
            ];
        }

        return [
            'id' => $row['id'],
            'available' => (string) ($row['available'] ?? '0.00'),
            'blocked' => (string) ($row['blocked'] ?? '0.00'),
            'reserved' => (string) ($row['reserved'] ?? '0.00'),
            'earned' => (string) ($row['earned'] ?? '0.00'),
            'withdrawn' => (string) ($row['withdrawn'] ?? '0.00'),
        ];
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
     */
    public function getTotalBlockedBalance(): string
    {
        $result = $this->createQueryBuilder('w')
            ->select('SUM(w.blockedBalance) as total')
            ->where('w.type = :type')
            ->setParameter('type', Wallet::TYPE_AGENCY)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0.00';
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
        foreach ($wallets as $wallet) {
            $agency = $wallet->getAgency();

            if ($agency) {
                $pendingRefunds = $refundRequestRepository->getPendingRefundsAmountForAgency($agency);
                $unvalidatedTickets = $ticketRepository->getUnvalidatedTicketsAmountForAgency($agency);
                $pendingWithdrawalsAmount = $withdrawalRequestRepository->getPendingWithdrawalsAmountForAgency($agency);

                $result[] = [
                    'wallet' => $wallet,
                    'agency' => $agency,
                    'available' => (float) $wallet->getAvailableBalance(),
                    'reserved' => (float) $wallet->getReservedBalance(),
                    'blocked' => (float) $wallet->getBlockedBalance(),
                    'total' => (float) $wallet->getTotalBalance(),
                    'totalEarned' => (float) $wallet->getTotalEarned(),
                    'totalWithdrawn' => (float) $wallet->getTotalWithdrawn(),
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
