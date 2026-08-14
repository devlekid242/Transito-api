<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

//    /**
//     * @return Notification[] Returns an array of Notification objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('n')
//            ->andWhere('n.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('n.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Notification
//    {
//        return $this->createQueryBuilder('n')
//            ->andWhere('n.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
    /** @return Notification[] */
    public function findVisibleForUser(User $user, ?int $agencyId, bool $unreadOnly = false): array
    {
        $qb = $this->createQueryBuilder('n');

        $visibility = $qb->expr()->orX(
            $qb->expr()->andX(
                'n.recipientType = :userType',
                'n.recipientId = :userId'
            ),
            $qb->expr()->andX(
                'n.recipientType = :agencyType',
                $agencyId !== null
                    ? $qb->expr()->orX('n.recipientId = :agencyId', 'n.recipientId IS NULL')
                    : 'n.recipientId IS NULL'
            )
        );

        $qb->where($visibility)
            ->setParameter('userType', 'user')
            ->setParameter('userId', $user->getId())
            ->setParameter('agencyType', 'agency_all');

        if ($agencyId !== null) {
            $qb->setParameter('agencyId', $agencyId);
        }

        // A state row is a per-user read/delete receipt. Legacy is_read is
        // retained only for old personal notifications.
        $stateSubquery = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from('App\Entity\NotificationUserState', 'nus')
            ->where('nus.notification = n')
            ->andWhere('nus.user = :stateUser');
        $qb->setParameter('stateUser', $user->getId());

        $qb->andWhere('NOT EXISTS (' . $stateSubquery->getDQL() . ' AND nus.deletedAt IS NOT NULL)');

        if ($unreadOnly) {
            $readSubquery = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from('App\Entity\NotificationUserState', 'nur')
                ->where('nur.notification = n')
                ->andWhere('nur.user = :readUser')
                ->andWhere('nur.isRead = true');
            $qb->setParameter('readUser', $user->getId());

            $unread = $qb->expr()->orX(
                $qb->expr()->andX('n.recipientType = :userType', 'n.isRead = 0'),
                $qb->expr()->andX('n.recipientType = :agencyType')
            );
            $qb->andWhere($unread)
                ->andWhere('NOT EXISTS (' . $readSubquery->getDQL() . ')');
        }

        return $qb->orderBy('n.createdAt', 'DESC')->getQuery()->getResult();
    }

}
