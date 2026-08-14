<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\AgentRepository;
use App\Repository\NotificationUserStateRepository;
use App\Service\NotificationBroadcastService;
use App\Service\NotificationNormalizer;
use App\Security\AdminRoleVoter;
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

    /**
     * 👈 NOUVEAU : résout l'agencyId pour N'IMPORTE QUEL agent (admin_agence
     * OU agent_quai), plus seulement admin_agence comme avant. C'est
     * cohérent avec PusherAuthController::isChannelAllowed(), qui autorise
     * déjà tous les agents d'une agence à s'abonner au canal
     * `private-agency-{id}` — restreindre la lecture REST au seul
     * admin_agence créait une incohérence : un agent_quai recevait les
     * notifications d'agence en temps réel (tant que son onglet restait
     * ouvert) mais ne les revoyait plus jamais après un rafraîchissement de
     * page.
     */
    private function resolveAgencyId(User $user): ?int
    {
        if (!$this->isGranted('ROLE_PARTNER') && !$this->isGranted('ROLE_AGENT')) {
            return null;
        }

        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        return $agent?->getAgency()?->getId();
    }

    /**
     * 👈 NOUVEAU : construit la requête combinant TOUJOURS les notifications
     * personnelles de l'utilisateur ET, s'il est agent, les notifications
     * `agency_all` de son agence — au lieu du OU exclusif précédent qui
     * faisait disparaître les notifications personnelles d'un admin_agence.
     */
    private function buildUserAndAgencyQuery(
        NotificationRepository $notificationRepository,
        User $user,
        bool $unreadOnly,
    ): array
    {
        return $notificationRepository->findVisibleForUser(
            $user,
            $this->resolveAgencyId($user),
            $unreadOnly,
        );
    }

    private function canAccessNotification(Notification $notification, User $user): bool
    {
        if ($notification->getRecipientType() === 'user') {
            return $notification->getRecipientId() === $user->getId();
        }

        if ($notification->getRecipientType() === 'agency_all') {
            $recipientId = $notification->getRecipientId();
            if ($recipientId === null) {
                return true;
            }
            $agencyId = $this->resolveAgencyId($user);
            return $agencyId !== null && $recipientId === $agencyId;
        }

        return false;
    }

    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function index(NotificationRepository $notificationRepository, NotificationUserStateRepository $stateRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        // 👈 CORRIGÉ : avant, un admin_agence perdait toutes ses notifications
        // personnelles (remplacées par les seules notifications d'agence).
        $notifications = $this->buildUserAndAgencyQuery($notificationRepository, $user, false);

        $data = array_map(function (Notification $notif) use ($stateRepository, $user) {
            $state = $stateRepository->findForUser($notif, $user);
            return $this->normalizer->normalizeForUser($notif, $state?->isRead() ?? ($notif->getRecipientType() === 'user' && $notif->getIsRead() === 1));
        }, $notifications);
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
                if ($agencyRecipientId === null && !$this->isGranted(AdminRoleVoter::SUPER)) {
                    return $this->json(['message' => 'Seul le Super Admin peut diffuser une notification globale.'], Response::HTTP_FORBIDDEN);
                }
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
    public function unread(NotificationRepository $notificationRepository, NotificationUserStateRepository $stateRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notifications = $this->buildUserAndAgencyQuery($notificationRepository, $user, true);

        $data = array_map(fn(Notification $notif) => $this->normalizer->normalizeForUser($notif, true), $notifications);
        return $this->json($data);
    }

    #[Route('/unread/count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notifications = $this->buildUserAndAgencyQuery($notificationRepository, $user, true);

        return $this->json(['count' => count($notifications)]);
    }

    #[Route('/{id}/read', name: 'api_notifications_mark_read', methods: ['PATCH'])]
    public function markRead(int $id, NotificationRepository $notificationRepository, NotificationUserStateRepository $stateRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notification = $notificationRepository->find($id);
        // 👈 CORRIGÉ : avant, seule une notification 'user' pouvait être
        // marquée lue — une notification 'agency_all' de sa propre agence
        // renvoyait un 404, alors qu'elle pouvait désormais apparaître dans
        // index()/unread() depuis le correctif ci-dessus.
        if (!$notification || !$this->canAccessNotification($notification, $user)) {
            return $this->json(['message' => 'Notification introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $state = $stateRepository->getState($notification, $user);
        $state->markRead();
        $em->persist($state);
        $em->flush();

        return $this->json($this->normalizer->normalizeForUser($notification, true));
    }

    #[Route('/mark-all-read', name: 'api_notifications_mark_all_read', methods: ['PATCH'])]
    public function markAllRead(NotificationRepository $notificationRepository, NotificationUserStateRepository $stateRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notifications = $this->buildUserAndAgencyQuery($notificationRepository, $user, true);

        foreach ($notifications as $notification) {
            $state = $stateRepository->getState($notification, $user);
            $state->markRead();
            $em->persist($state);
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
    public function delete(int $id, NotificationRepository $notificationRepository, NotificationUserStateRepository $stateRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);

        $notification = $notificationRepository->find($id);
        if (!$notification || !$this->canAccessNotification($notification, $user)) {
            return $this->json(['message' => 'Notification introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Une notification partagée ne doit jamais être supprimée pour
        // tous les destinataires parce qu'un seul utilisateur la masque.
        // On crée donc une tombstone personnelle.
        $state = $stateRepository->getState($notification, $user);
        $state->markDeleted();
        $em->persist($state);
        $em->flush();

        return $this->json(['deleted' => true]);
    }
}