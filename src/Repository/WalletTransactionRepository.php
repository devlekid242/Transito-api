<?php

namespace App\Repository;

use App\Entity\WalletTransaction;
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
     * Get platform revenue (sum of all platform fees) within a date range.
     */
    public function getPlatformRevenue(DateTimeInterface $startDate, DateTimeInterface $endDate): string
    {
        $result = $this->createQueryBuilder('wt')
            ->select('SUM(wt.amount) as total')
            ->where('wt.source = :source')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0.00';
    }

    /**
     * Get revenue chart data grouped by period.
     * Returns array with labels and series data.
     */
    public function getRevenueChartData(DateTimeInterface $startDate, DateTimeInterface $endDate, string $period = 'monthly'): array
    {
        $groupBy = $this->getGroupByExpression($period);

        $query = $this->createQueryBuilder('wt')
            ->select($groupBy . ' as period, SUM(wt.amount) as revenue')
            ->where('wt.source = :source')
            ->andWhere('wt.createdAt BETWEEN :start AND :end')
            ->setParameter('source', WalletTransaction::SOURCE_PLATFORM_FEE)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('period') // 👈 CORRECTION ICI : Utiliser l'alias 'period' au lieu de $groupBy
            ->orderBy('period', 'ASC');

        $results = $query->getQuery()->getResult();

        $labels = [];
        $revenueSeries = [];

        foreach ($results as $row) {
            $periodVal = $row['period'] ?? null;

            // Gestion propre string / DateTime pour les labels
            if ($periodVal instanceof \DateTimeInterface) {
                $labels[] = $periodVal->format('Y-m-d');
            } else {
                $labels[] = (string) $periodVal;
            }

            $revenueSeries[] = (float) ($row['revenue'] ?? 0);
        }

        return [
            'labels' => $labels,
            'revenueSeries' => $revenueSeries,
        ];
    }

    /**
     * Get the group by expression based on period.
     */
    private function getGroupByExpression(string $period): string
    {
        switch ($period) {
            case 'daily':
                return 'DATE(wt.createdAt)';
            case 'weekly':
                return 'YEARWEEK(wt.createdAt, 1)';
            case 'monthly':
            default:
                // Cotes simples strictes autour du format MySQL : '%Y-%m'
                return "DATE_FORMAT(wt.createdAt, '%Y-%m')";
        }
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
