<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationBroadcastService;
use Doctrine\ORM\EntityManagerInterface;
use Pusher\Pusher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    #[Route('', name: 'api_notifications_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, NotificationBroadcastService $broadcaster): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string)($data['title'] ?? ''));
        $content = trim((string)($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            return $this->json(['message' => 'Title and content are required.'], Response::HTTP_BAD_REQUEST);
        }

        $recipientType = $data['recipientType'] ?? 'user';
        $notification = new Notification();
        $notification->setRecipientType($recipientType);
        $notification->setRecipientId(
            $recipientType === 'user' ? (array_key_exists('recipientId', $data) ? (int)$data['recipientId'] : $user->getId()) : null
        );
        $notification->setTitle($title);
        $notification->setContent($content);
        $notification->setCategory(strtoupper($data['category'] ?? 'INFO'));
        $notification->setPayload($data['payload'] ?? null);

        $em->persist($notification);
        $em->flush();
        $broadcaster->broadcast($notification);

        return $this->json($this->normalizeNotification($notification), Response::HTTP_CREATED);
    }

    #[Route('/pusher/auth', name: 'api_pusher_auth', methods: ['POST'])]
    public function pusherAuth(Request $request, Pusher $pusher): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = $request->request->all();
        }

        $channelName = $data['channel_name'] ?? $request->request->get('channel_name', '');
        $socketId = $data['socket_id'] ?? $request->request->get('socket_id', '');

        if (!$channelName || !$socketId) {
            return $this->json(['message' => 'Données d authentification manquantes.'], Response::HTTP_BAD_REQUEST);
        }

        if (!str_starts_with($channelName, 'private-user-') && !str_starts_with($channelName, 'private-global')) {
            return $this->json(['message' => 'Canal Pusher non autorisé.'], Response::HTTP_FORBIDDEN);
        }

        $auth = $pusher->socket_auth($channelName, $socketId);
        return new Response($auth, Response::HTTP_OK, ['Content-Type' => 'application/json']);
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
        $category = strtoupper($notification->getCategory() ?? 'INFO');
        $content = strtolower($notification->getContent() ?? '');
        $title = strtolower($notification->getTitle() ?? '');
        $combined = $title . ' ' . $content;

        if ($category === 'INFO') {
            if (str_contains($combined, 'paiement') || str_contains($combined, 'reçu')) {
                $category = 'PAYMENT';
            } elseif (str_contains($combined, 'réservation') || str_contains($combined, 'confirmé') || str_contains($combined, 'ticket')) {
                $category = 'BOOKING';
            } elseif (str_contains($combined, 'voyage') || str_contains($combined, 'embarquement')) {
                $category = 'TRIP';
            } elseif (str_contains($combined, 'promotion') || str_contains($combined, 'offre')) {
                $category = 'PROMOTION';
            }
        }

        return [
            'id' => $notification->getId(),
            'recipientType' => $notification->getRecipientType(),
            'recipientId' => $notification->getRecipientId(),
            'title' => $notification->getTitle(),
            'message' => $notification->getContent(),
            'type' => $category,
            'category' => $category,
            'payload' => $notification->getPayload(),
            'isRead' => $notification->getIsRead() === 1,
            'createdAt' => $notification->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $notification->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
