<?php

namespace App\Service;

use App\Entity\Notification;
use App\Repository\DeviceTokenRepository;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Psr\Log\LoggerInterface;

/**
 * Envoie les notifications "réellement" push : celles qui apparaissent dans
 * la barre de notification du téléphone, même app fermée ou en arrière-plan.
 *
 * Fonctionne en parallèle de Pusher (temps réel "app ouverte") : les deux
 * sont déclenchés depuis le même point d'entrée, `NotificationBroadcastService`.
 *
 * Nécessite : `composer require kreait/firebase-php` + un fichier de compte
 * de service Firebase référencé par la variable d'env `FIREBASE_CREDENTIALS`.
 */
class FcmPushService
{
    public function __construct(
        private Messaging $messaging,
        private DeviceTokenRepository $deviceTokenRepository,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Point d'entrée unique : construit le message à partir de l'entité
     * Notification et le route vers le ou les bons appareils.
     */
    public function sendForNotification(Notification $notification): void
    {
        $title = $notification->getTitle() ?? 'Transito';
        $body = $notification->getContent() ?? '';
        $data = [
            'notificationId' => (string)$notification->getId(),
            'category' => (string)($notification->getCategory() ?? 'INFO'),
            // Les données FCM doivent être des chaînes ; on sérialise le payload complémentaire.
            'payload' => json_encode($notification->getPayload() ?? []),
        ];

        if ($notification->getRecipientType() === 'user' && $notification->getRecipientId() !== null) {
            $this->sendToUserId((int)$notification->getRecipientId(), $title, $body, $data);
            return;
        }

        if ($notification->getRecipientType() === 'agency_all') {
            // À ce stade on diffuse à tous les tokens connus. Pour un vrai
            // passage à l'échelle, migrer vers les topics FCM
            // (subscribeToTopic côté app + sendToTopic ici) plutôt que
            // d'itérer un par un sur chaque device token.
            $this->sendToAllDevices($title, $body, $data);
        }
    }

    public function sendToUserId(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = array_map(
            fn($d) => $d->getToken(),
            $this->deviceTokenRepository->findBy(['user' => $userId]),
        );

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    private function sendToAllDevices(string $title, string $body, array $data = []): void
    {
        $tokens = array_map(fn($d) => $d->getToken(), $this->deviceTokenRepository->findAll());
        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Envoie à une liste de tokens et nettoie ceux que Firebase signale
     * comme invalides/désinstallés, pour ne pas continuer à taper dans le
     * vide à chaque notification future.
     */
    private function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $body))
            ->withData($data);

        try {
            $report = $this->messaging->sendMulticast($message, $tokens);
        } catch (\Throwable $e) {
            $this->logger?->error('Envoi FCM multicast échoué', ['error' => $e->getMessage()]);
            return;
        }

        $staleTokens = [];
        foreach ($report->failures()->getItems() as $failure) {
            $target = $failure->target();
            if ($target?->type() === MessageTarget::TOKEN) {
                $staleTokens[] = $target->value();
            }
            $this->logger?->warning('Échec envoi push vers un token', [
                'token' => $target?->value(),
                'error' => $failure->error()->getMessage(),
            ]);
        }

        if (!empty($staleTokens)) {
            $this->deviceTokenRepository->deleteByTokens($staleTokens);
        }
    }
}