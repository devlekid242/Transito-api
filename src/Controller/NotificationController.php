<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationBroadcastService;
use App\Service\NotificationNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/user-notifications')]
class NotificationController extends AbstractController
{
    public function __construct(private NotificationNormalizer $normalizer) {}

    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function index(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $notifications = $notificationRepository->findBy(
            ['recipientType' => 'user', 'recipientId' => $user->getId()],
            ['createdAt' => 'DESC']
        );

        $data = array_map(fn($notif) => $this->normalizer->normalize($notif), $notifications);
        return $this->json($data);
    }

    /**
     * Création d'une notification.
     *
     * Sécurité : ce endpoint est accessible à tout utilisateur authentifié,
     * il ne doit donc JAMAIS permettre de notifier quelqu'un d'autre que
     * soi-même, ni de diffuser à toute l'agence — sinon n'importe quel compte
     * peut spammer/harceler d'autres utilisateurs (y compris via le push FCM
     * réellement envoyé sur leur téléphone).
     *
     * - recipientType = 'user' : autorisé uniquement pour soi-même, sauf rôle
     *   privilégié (ex: agent/admin qui notifie un client d'une réservation).
     * - recipientType = 'agency_all' (broadcast) : réservé aux rôles privilégiés.
     *
     * Adapter 'ROLE_ADMIN' / 'ROLE_AGENT' ci-dessous à votre hiérarchie de rôles réelle.
     */
    #[Route('', name: 'api_notifications_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, NotificationBroadcastService $broadcaster): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $title = trim((string)($data['title'] ?? ''));
        $content = trim((string)($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            return $this->json(['message' => 'Title and content are required.'], Response::HTTP_BAD_REQUEST);
        }

        $recipientType = $data['recipientType'] ?? 'user';
        $requestedRecipientId = isset($data['recipientId']) ? (int)$data['recipientId'] : null;

        $isPrivileged = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_AGENT');

        // Diffusion à toute l'agence : réservée aux comptes privilégiés.
        if ($recipientType !== 'user' && !$isPrivileged) {
            return $this->json(['message' => "Vous n'êtes pas autorisé à diffuser ce type de notification."], Response::HTTP_FORBIDDEN);
        }

        // Notifier un autre utilisateur que soi-même : réservé aux comptes privilégiés.
        if (
            $recipientType === 'user'
            && $requestedRecipientId !== null
            && $requestedRecipientId !== $user->getId()
            && !$isPrivileged
        ) {
            return $this->json(['message' => 'Vous ne pouvez créer une notification que pour vous-même.'], Response::HTTP_FORBIDDEN);
        }

        $notification = new Notification();
        $notification->setRecipientType($recipientType);
        $notification->setRecipientId(
            $recipientType === 'user' ? ($requestedRecipientId ?? $user->getId()) : null
        );
        $notification->setTitle($title);
        $notification->setContent($content);
        $notification->setCategory(strtoupper($data['category'] ?? 'INFO'));
        $notification->setPayload($data['payload'] ?? null);

        $em->persist($notification);
        $em->flush();

        // Diffusion temps réel + push
        $broadcaster->broadcast($notification);

        return $this->json($this->normalizer->normalize($notification), Response::HTTP_CREATED);
    }

    #[Route('/unread', name: 'api_notifications_unread', methods: ['GET'])]
    public function unread(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notifications = $notificationRepository->findBy(
            ['recipientType' => 'user', 'recipientId' => $user->getId(), 'isRead' => 0],
            ['createdAt' => 'DESC']
        );

        $data = array_map(fn($notif) => $this->normalizer->normalize($notif), $notifications);
        return $this->json($data);
    }

    #[Route('/unread/count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $count = $notificationRepository->count([
            'recipientType' => 'user',
            'recipientId' => $user->getId(),
            'isRead' => 0,
        ]);

        return $this->json(['count' => $count]);
    }

    #[Route('/{id}/read', name: 'api_notifications_mark_read', methods: ['PATCH'])]
    public function markRead(int $id, NotificationRepository $notificationRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notification = $notificationRepository->find($id);
        if (!$notification || $notification->getRecipientType() !== 'user' || $notification->getRecipientId() !== $user->getId()) {
            return $this->json(['message' => 'Notification introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $notification->setIsRead(1);
        $em->flush();

        return $this->json($this->normalizer->normalize($notification));
    }

    #[Route('/mark-all-read', name: 'api_notifications_mark_all_read', methods: ['PATCH'])]
    public function markAllRead(NotificationRepository $notificationRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notifications = $notificationRepository->findBy([
            'recipientType' => 'user',
            'recipientId' => $user->getId(),
            'isRead' => 0,
        ]);

        foreach ($notifications as $notification) {
            $notification->setIsRead(1);
        }
        $em->flush();

        return $this->json(['updated' => count($notifications)]);
    }
}