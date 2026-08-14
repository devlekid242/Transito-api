<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;

class AdminNotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationBroadcastService $broadcaster,
    ) {}

    public function notifyAdmins(string $title, string $content, string $category = 'INFO', ?array $payload = null): void
    {
        $admins = $this->getAdminUsers();
        if (empty($admins)) {
            return;
        }

        $notifications = [];
        foreach ($admins as $admin) {
            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($admin->getId())
                ->setTitle($title)
                ->setContent($content)
                ->setCategory($category);

            if ($payload !== null) {
                $notification->setPayload($payload);
            }

            $this->em->persist($notification);
            $notifications[] = $notification;
        }

        $this->em->flush();

        foreach ($notifications as $notification) {
            $this->broadcaster->broadcast($notification);
        }
    }

    public function notifyEvent(string $eventLabel, string $details, string $category = 'INFO', ?array $payload = null): void
    {
        $this->notifyAdmins(
            $eventLabel,
            $details,
            $category,
            $payload,
        );
    }

    /**
     * @return User[]
     */
    private function getAdminUsers(): array
    {
        // La source de vérité du rôle administrateur est l'entité Admin :
        // User::getRoles() dérive ROLE_ADMIN à partir de Admin et ce rôle n'est
        // pas nécessairement présent dans le JSON users.roles.
        return $this->em->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->leftJoin(Admin::class, 'a', 'WITH', 'a.user = u')
            ->where('(a.id IS NOT NULL AND a.status = :active) OR u.roles LIKE :role')
            ->setParameter('active', 'active')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->getQuery()
            ->getResult();
    }
}
