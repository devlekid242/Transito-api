<?php

namespace App\Service;

use App\Entity\Notification;
use App\Repository\DeviceTokenRepository;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
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
            'payload' => json_encode($notification->getPayload() ?? []),
        ];

        if ($notification->getRecipientType() === 'user' && $notification->getRecipientId() !== null) {
            $this->sendToUserId((int)$notification->getRecipientId(), $title, $body, $data);
            return;
        }

        if ($notification->getRecipientType() === 'agency_all') {
            if ($notification->getRecipientId() !== null) {
                $this->sendToAgencyDevices((int)$notification->getRecipientId(), $title, $body, $data);
            } else {
                $this->sendToAllDevices($title, $body, $data);
            }
        }
    }

    public function sendToUserId(int $userId, string $title, string $body, array $data = []): array
    {
        $tokens = array_map(
            fn($d) => $d->getToken(),
            $this->deviceTokenRepository->findBy(['user' => $userId]),
        );

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    private function sendToAgencyDevices(int $agencyId, string $title, string $body, array $data = []): array
    {
        if (!method_exists($this->deviceTokenRepository, 'findByAgencyId')) {
            $this->logger?->error(
                'DeviceTokenRepository::findByAgencyId manquante — impossible de cibler le push FCM par agence.',
                ['agencyId' => $agencyId],
            );
            return ['success' => 0, 'failure' => 0, 'stale' => 0];
        }

        $tokens = array_map(
            fn($d) => $d->getToken(),
            $this->deviceTokenRepository->findByAgencyId($agencyId),
        );

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    private function sendToAllDevices(string $title, string $body, array $data = []): array
    {
        $tokens = array_map(fn($d) => $d->getToken(), $this->deviceTokenRepository->findAll());
        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return ['success' => 0, 'failure' => 0, 'stale' => 0];
        }

        $androidConfig = AndroidConfig::fromArray([
            'priority' => 'high',
            'notification' => [
                'channel_id' => 'transito_notifications',
                'sound' => 'default',
                'default_sound' => true,
                'notification_priority' => 'PRIORITY_HIGH',
                'visibility' => 'PUBLIC',
            ],
        ]);

        $apnsConfig = ApnsConfig::fromArray([
            'headers' => [
                'apns-priority' => '10',
            ],
            'payload' => [
                'aps' => [
                    'alert' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'sound' => 'default',
                    'badge' => 1,
                    'content-available' => 1,
                ],
            ],
        ]);

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $body))
            ->withData($data)
            ->withAndroidConfig($androidConfig)
            ->withApnsConfig($apnsConfig);

        try {
            $report = $this->messaging->sendMulticast($message, $tokens);
        } catch (\Throwable $e) {
            $this->logger?->error('Envoi FCM multicast échoué', [
                'error' => $e->getMessage(),
                'tokens_count' => count($tokens),
            ]);
            return ['success' => 0, 'failure' => count($tokens), 'error' => $e->getMessage()];
        }

        $staleTokens = [];
        $failuresCount = 0;
        $errors = [];
        $successCount = $report->successes()->count();

        foreach ($report->failures()->getItems() as $failure) {
            $failuresCount++;
            $target = $failure->target();
            $errorMessage = $failure->error()?->getMessage() ?? 'Erreur inconnue';
            $tokenVal = $target?->value();
            $errors[] = [
                'token' => $tokenVal,
                'error' => $errorMessage,
            ];

            // Ne supprimer QUE les tokens réellement désenregistrés / introuvables côté Firebase.
            // Ne JAMAIS supprimer les tokens en cas d'erreur de clé, mismatch de projet ou réseau !
            $isTrulyStale = stripos($errorMessage, 'UNREGISTERED') !== false
                || stripos($errorMessage, 'NOT_FOUND') !== false
                || stripos($errorMessage, 'registration-token-not-registered') !== false;

            if ($target?->type() === MessageTarget::TOKEN && $isTrulyStale) {
                $staleTokens[] = $tokenVal;
            }

            $this->logger?->warning('Échec envoi push vers un token FCM', [
                'token' => $tokenVal,
                'error' => $errorMessage,
                'isStale' => $isTrulyStale,
            ]);
        }

        if (!empty($staleTokens)) {
            $this->deviceTokenRepository->deleteByTokens($staleTokens);
            $this->logger?->info('Tokens FCM périmés nettoyés', ['count' => count($staleTokens)]);
        }

        return [
            'success' => $successCount,
            'failure' => $failuresCount,
            'stale' => count($staleTokens),
            'errors' => $errors,
        ];
    }
}