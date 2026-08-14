<?php

namespace App\Repository;

use App\Entity\WalletTransaction;
use App\Entity\Wallet;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WalletTransaction>
 */
class WalletTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WalletTransaction::class);
    }

    /**
     * Revenu économique de la plateforme : frais Transito + revenus de
     * no-show. Les crédits/débits administratifs et les transferts de wallet
     * ne sont pas des revenus commerciaux.
     */
    public function getPlatformEconomicRevenue(DateTimeInterface $startDate, DateTimeInterface $endDate): string
    {
        $result = $this->createQueryBuilder('wt')
            ->select('SUM(wt.amount) as total')
            ->join('wt.wallet', 'w')
            ->where('wt.source IN (:sources)')
            ->andWhere('w.type = :walletType')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('sources', [
                WalletTransaction::SOURCE_PLATFORM_FEE,
                WalletTransaction::SOURCE_NO_SHOW_REVENUE,
            ])
            ->setParameter('walletType', Wallet::TYPE_PLATFORM)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0.00';
    }

    /**
     * Total des commissions récoltées par la plateforme
     */
    public function getPlatformRevenue(DateTimeInterface $startDate, DateTimeInterface $endDate): string
    {
        $result = $this->createQueryBuilder('wt')
            ->select('SUM(wt.amount) as total')
            ->join('wt.wallet', 'w')
            ->where('wt.source = :source')
            ->andWhere('w.type = :walletType')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
            ->setParameter('walletType', Wallet::TYPE_PLATFORM)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0.00';
    }

    /**
     * Données du graphique basées sur l'extraction d'année/mois native DQL
     */
    public function getRevenueChartData(DateTimeInterface $startDate, DateTimeInterface $endDate, string $period = 'monthly'): array
    {
        $qb = $this->createQueryBuilder('wt')
            ->where('wt.source = :source')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($period === 'daily') {
            $qb->select("SUBSTRING(wt.createdAt, 1, 10) as period, SUM(wt.amount) as revenue");
        } else {
            $qb->select("SUBSTRING(wt.createdAt, 1, 7) as period, SUM(wt.amount) as revenue");
        }

        $results = $qb->groupBy('period')
            ->orderBy('period', 'ASC')
            ->getQuery()
            ->getResult();

        $labels = [];
        $revenueSeries = [];

        foreach ($results as $row) {
            $labels[] = (string) $row['period'];
            $revenueSeries[] = (float) ($row['revenue'] ?? 0);
        }

        return [
            'labels' => $labels,
            'revenueSeries' => $revenueSeries,
        ];
    }


    public function getGroupByExpression(string $period, string $alias = 'wt'): string
    {
        return match ($period) {
            'daily' => sprintf('SUBSTRING(%s.createdAt, 1, 10)', $alias),
            'weekly' => sprintf('SUBSTRING(%s.createdAt, 1, 7)', $alias),
            'monthly' => sprintf('SUBSTRING(%s.createdAt, 1, 7)', $alias),
            default => sprintf('SUBSTRING(%s.createdAt, 1, 7)', $alias),
        };
    }

    /**
     * Get the date format based on period.
     */
    private function getDateFormat(string $period): string
    {
        switch ($period) {
            case 'daily':
                return 'Y-m-d';
            case 'weekly':
                return 'Y-W';
            case 'monthly':
            default:
                return 'Y-m';
        }
    }
}
