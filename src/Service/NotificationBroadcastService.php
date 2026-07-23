<?php

namespace App\Service;

use App\Entity\Notification;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;

class NotificationBroadcastService
{
    public function __construct(
        private Pusher $pusher,
        private FcmPushService $fcmPushService,
        private NotificationNormalizer $normalizer,
        private ?LoggerInterface $logger = null,
    ) {}

    public function broadcast(Notification $notification): void
    {
        $channel = $this->resolveChannel($notification);
        if ($channel === null) {
            $this->logger?->warning('Notification non diffusée : destinataire introuvable.', [
                'notificationId' => $notification->getId(),
            ]);
            return;
        }

        $payload = $this->normalizer->normalize($notification);

        // 1) Temps réel in-app via Pusher
        try {
            $this->pusher->trigger($channel, 'new-notification', $payload);
        } catch (\Throwable $e) {
            $this->logger?->error('Échec de la diffusion Pusher', ['error' => $e->getMessage()]);
        }

        // 2) Push natif FCM
        try {
            $this->fcmPushService->sendForNotification($notification);
        } catch (\Throwable $e) {
            $this->logger?->error('Échec de l\'envoi push FCM', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 👈 CORRIGÉ : `agency_all` était toujours routé vers `private-global`,
     * un canal unique partagé par TOUT LE MONDE (clients + partenaires de
     * TOUTES les agences confondues). Concrètement une annonce destinée aux
     * agents de l'agence A finissait aussi chez les clients et chez les
     * agents de l'agence B.
     *
     * Désormais :
     * - `agency_all` + recipientId renseigné (= l'agencyId) → canal scopé
     *   `private-agency-{agencyId}`, réservé aux agents de cette agence
     *   (voir PusherAuthController::isChannelAllowed).
     * - `agency_all` SANS recipientId → conservé comme diffusion "vraiment
     *   globale" (`private-global`), réservée aux admins côté
     *   NotificationController::create().
     */
    private function resolveChannel(Notification $notification): ?string
    {
        if ($notification->getRecipientType() === 'user' && $notification->getRecipientId() !== null) {
            return 'private-user-' . $notification->getRecipientId();
        }

        if ($notification->getRecipientType() === 'agency_all') {
            return $notification->getRecipientId() !== null
                ? 'private-agency-' . $notification->getRecipientId()
                : 'private-global';
        }

        return null;
    }
}