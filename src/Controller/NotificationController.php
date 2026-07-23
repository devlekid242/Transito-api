<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\AgentRepository;
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
    public function __construct(
        private NotificationNormalizer $normalizer,
        private AgentRepository $agentRepository
        ) {}

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
     * 👈 CORRIGÉ : la diffusion "agency_all" reste réservée aux rôles
     * privilégiés, mais est maintenant scopée à une agence précise :
     * - Un AGENT ne peut diffuser que pour SA PROPRE agence (recipientId
     *   forcé côté serveur, on ignore toute valeur envoyée par le client
     *   pour éviter qu'un agent cible l'agence d'un autre).
     * - Un ADMIN peut cibler une agence précise (recipientId fourni) ou
     *   une diffusion vraiment globale (recipientId = null → private-global).
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

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isAgent = $this->isGranted('ROLE_AGENT');
        $isPrivileged = $isAdmin || $isAgent;

        $agencyRecipientId = null;

        if ($recipientType !== 'user') {
            if (!$isPrivileged) {
                return $this->json(['message' => "Vous n'êtes pas autorisé à diffuser ce type de notification."], Response::HTTP_FORBIDDEN);
            }

            if ($isAgent && !$isAdmin) {
                // Un agent ne diffuse JAMAIS pour une autre agence que la
                // sienne : on ignore recipientId venant du client.
                $agent = $this->agentRepository->findOneBy(['user' => $user]) ;
                $agencyRecipientId = $agent?->getAgency()?->getId();
                if ($agencyRecipientId === null) {
                    return $this->json(['message' => 'Agence introuvable pour cet agent.'], Response::HTTP_FORBIDDEN);
                }
            } else {
                $agencyRecipientId = $requestedRecipientId;
            }
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

        if ($recipientType === 'user') {
            $notification->setRecipientId($requestedRecipientId ?? $user->getId());
        } else {
            $notification->setRecipientId($agencyRecipientId); // agencyId, ou null = global
        }

        $notification->setTitle($title);
        $notification->setContent($content);
        $notification->setCategory(strtoupper($data['category'] ?? 'INFO'));
        $notification->setPayload($data['payload'] ?? null);

        $em->persist($notification);
        $em->flush();

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

    /**
     * 👈 NOUVEAU : route manquante — le front (notification.service.ts)
     * appelait déjà DELETE /api/user-notifications/{id} sans qu'aucune
     * route ne l'écoute côté API (404 garanti si le bouton "supprimer"
     * est utilisé quelque part dans l'UI).
     */
    #[Route('/{id}', name: 'api_notifications_delete', methods: ['DELETE'])]
    public function delete(int $id, NotificationRepository $notificationRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notification = $notificationRepository->find($id);
        if (!$notification || $notification->getRecipientType() !== 'user' || $notification->getRecipientId() !== $user->getId()) {
            return $this->json(['message' => 'Notification introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $em->remove($notification);
        $em->flush();

        return $this->json(['deleted' => true]);
    }
}