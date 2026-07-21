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

        // On utilise le normalizer pour avoir la même data partout !
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

    private function resolveChannel(Notification $notification): ?string
    {
        if ($notification->getRecipientType() === 'user' && $notification->getRecipientId() !== null) {
            return 'private-user-' . $notification->getRecipientId();
        }

        if ($notification->getRecipientType() === 'agency_all') {
            return 'private-global';
        }

        return null;
    }
}