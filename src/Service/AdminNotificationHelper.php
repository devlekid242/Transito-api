<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Notifie tous les comptes ROLE_ADMIN d'un évènement nécessitant leur
 * attention (nouveau ticket de support, relance client sur un ticket
 * existant, document d'agence à valider, nouveau staff créé, etc.).
 *
 * Réutilise le canal personnel de chaque admin (`private-user-{id}`, déjà
 * géré par NotificationBroadcastService/PusherAuthController) plutôt que
 * d'introduire un canal "admin" dédié — aucune modification front nécessaire.
 *
 * ⚠️ getAdminUsers() filtre sur la colonne JSON `roles` avec un LIKE texte
 * ('%"ROLE_ADMIN"%'), ce qui fonctionne quel que soit le nom de la relation
 * mais reste un hack. Si un UserRepository::findByRole() (ou équivalent)
 * existe déjà dans le projet, remplace le corps de cette méthode par un
 * appel à celui-ci — ce sera plus propre et plus rapide.
 */
class AdminNotificationHelper
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationBroadcastService $broadcaster,
    ) {}

    public function notifyAdmins(string $title, string $content, string $category, ?array $payload = null): void
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

    /**
     * @return User[]
     */
    private function getAdminUsers(): array
    {
        return $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->getQuery()
            ->getResult();
    }
}