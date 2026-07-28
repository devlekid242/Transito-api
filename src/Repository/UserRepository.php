<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Count new users created since a specific date.
     */
    public function countNewUsersSince(\DateTimeInterface $date): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count active clients for today.
     */
    public function countActiveClientsToday(\DateTimeInterface $startOfDay): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.roles LIKE :client')
            ->andWhere('u.lastLoginAt >= :date')
            ->setParameter('client', '%CLIENT%')
            ->setParameter('date', $startOfDay)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get new users count grouped by month for the last 8 months.
     */
    public function getNewUsersByMonth(): array
    {
        $startDate = new \DateTime('-8 months');
        
        return $this->createQueryBuilder('u')
            // ✅ Correction de la chaîne DATE_FORMAT et utilisation de l'alias 'month' pour groupBy
            ->select("DATE_FORMAT(u.createdAt, '%Y-%m') as month, COUNT(u.id) as count")
            ->where('u.createdAt >= :startDate')
            ->setParameter('startDate', $startDate)
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get new users count grouped by period (monthly, weekly, daily).
     */
    public function getNewUsersByPeriod(\DateTimeInterface $startDate, \DateTimeInterface $endDate, string $period = 'monthly'): array
    {
        $groupBy = $this->getGroupByExpression($period);
        
        return $this->createQueryBuilder('u')
            ->select($groupBy . ' as period, COUNT(u.id) as count')
            ->where('u.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->groupBy('period') // ✅ Correction : Utiliser l'alias 'period'
            ->orderBy('period', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the group by expression based on period.
     */
    private function getGroupByExpression(string $period): string
    {
        switch ($period) {
            case 'daily':
                return 'DATE(u.createdAt)';
            case 'weekly':
                return 'YEARWEEK(u.createdAt, 1)';
            case 'monthly':
            default:
                return "DATE_FORMAT(u.createdAt, '%Y-%m')";
        }
    }
}