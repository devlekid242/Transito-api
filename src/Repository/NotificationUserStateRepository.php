<?php
namespace App\Repository;

use App\Entity\Notification;
use App\Entity\NotificationUserState;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificationUserStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationUserState::class);
    }

    public function findForUser(Notification $notification, User $user): ?NotificationUserState
    {
        return $this->findOneBy(['notification' => $notification, 'user' => $user]);
    }

    public function getState(Notification $notification, User $user): NotificationUserState
    {
        return $this->findForUser($notification, $user) ?? (new NotificationUserState())
            ->setNotification($notification)
            ->setUser($user);
    }
}
