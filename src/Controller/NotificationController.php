<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/user-notifications')]
class NotificationController extends AbstractController
{
    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function index(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $notifications = $notificationRepository->findBy([
            'recipientType' => 'user',
            'recipientId' => $user->getId(),
        ], ['createdAt' => 'DESC']);

        return $this->json(array_map([$this, 'normalizeNotification'], $notifications));
    }

    #[Route('/unread', name: 'api_notifications_unread', methods: ['GET'])]
    public function unread(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $notifications = $notificationRepository->findBy([
            'recipientType' => 'user',
            'recipientId' => $user->getId(),
            'isRead' => 0,
        ], ['createdAt' => 'DESC']);

        return $this->json(array_map([$this, 'normalizeNotification'], $notifications));
    }

    #[Route('/unread/count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $count = count($notificationRepository->findBy([
            'recipientType' => 'user',
            'recipientId' => $user->getId(),
            'isRead' => 0,
        ]));

        return $this->json(['count' => $count]);
    }

    #[Route('/{id}/read', name: 'api_notifications_mark_read', methods: ['PATCH'])]
    public function markRead(int $id, NotificationRepository $notificationRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $notification = $notificationRepository->find($id);
        if (!$notification || $notification->getRecipientType() !== 'user' || $notification->getRecipientId() !== $user->getId()) {
            return $this->json(['message' => 'Notification introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $notification->setIsRead(1);
        $em->persist($notification);
        $em->flush();

        return $this->json($this->normalizeNotification($notification));
    }

    #[Route('/mark-all-read', name: 'api_notifications_mark_all_read', methods: ['PATCH'])]
    public function markAllRead(NotificationRepository $notificationRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $notifications = $notificationRepository->findBy([
            'recipientType' => 'user',
            'recipientId' => $user->getId(),
            'isRead' => 0,
        ]);

        foreach ($notifications as $notification) {
            $notification->setIsRead(1);
            $em->persist($notification);
        }

        $em->flush();

        return $this->json(['updated' => count($notifications)]);
    }

    private function normalizeNotification(Notification $notification): array
    {
        $type = 'Système';
        $content = strtolower($notification->getContent() ?? '');
        $title = strtolower($notification->getTitle() ?? '');
        $combined = $title . ' ' . $content;

        if (str_contains($combined, 'paiement') || str_contains($combined, 'reçu')) {
            $type = 'Paiement';
        } elseif (str_contains($combined, 'réservation') || str_contains($combined, 'confirmé') || str_contains($combined, 'ticket')) {
            $type = 'Réservation';
        } elseif (str_contains($combined, 'voyage') || str_contains($combined, 'embarquement')) {
            $type = 'Voyage';
        } elseif (str_contains($combined, 'promotion') || str_contains($combined, 'offre')) {
            $type = 'Promotion';
        }

        return [
            'id' => $notification->getId(),
            'recipientType' => $notification->getRecipientType(),
            'recipientId' => $notification->getRecipientId(),
            'title' => $notification->getTitle(),
            'message' => $notification->getContent(),
            'type' => $type,
            'isRead' => $notification->getIsRead() === 1,
            'createdAt' => $notification->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $notification->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
