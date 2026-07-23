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
     *
     * 👈 CORRIGÉ : `agency_all` envoyait un push FCM à TOUS les devices
     * connus, tout utilisateur/agence confondus (`sendToAllDevices`).
     * Concrètement, une alerte interne pour l'agence A finissait aussi
     * dans la poche de chaque client et chaque agent de l'agence B.
     * Désormais on cible les devices de l'agence concernée quand un
     * agencyId (recipientId) est fourni, et on réserve la diffusion à
     * TOUS les devices au cas explicite où recipientId est null (annonce
     * vraiment globale, déjà restreinte aux admins côté NotificationController).
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

    public function sendToUserId(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = array_map(
            fn($d) => $d->getToken(),
            $this->deviceTokenRepository->findBy(['user' => $userId]),
        );

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * 👈 NOUVEAU : envoie uniquement aux devices des utilisateurs rattachés
     * à cette agence (agents/partenaires).
     *
     * ⚠️ Nécessite une méthode `findByAgencyId(int $agencyId): DeviceToken[]`
     * sur `DeviceTokenRepository` (jointure DeviceToken → User → Agent →
     * Agency). Elle n'était pas présente dans les fichiers fournis : adapte
     * le nom si ta relation Doctrine diffère. Exemple d'implémentation :
     *
     * public function findByAgencyId(int $agencyId): array
     * {
     *     return $this->createQueryBuilder('dt')
     *         ->innerJoin('dt.user', 'u')
     *         ->innerJoin('u.agent', 'a')
     *         ->andWhere('a.agency = :agencyId')
     *         ->setParameter('agencyId', $agencyId)
     *         ->getQuery()
     *         ->getResult();
     * }
     */
    private function sendToAgencyDevices(int $agencyId, string $title, string $body, array $data = []): void
    {
        if (!method_exists($this->deviceTokenRepository, 'findByAgencyId')) {
            $this->logger?->error(
                'DeviceTokenRepository::findByAgencyId manquante — impossible de cibler le push FCM par agence.',
                ['agencyId' => $agencyId],
            );
            return;
        }

        $tokens = array_map(
            fn($d) => $d->getToken(),
            $this->deviceTokenRepository->findByAgencyId($agencyId),
        );

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    private function sendToAllDevices(string $title, string $body, array $data = []): void
    {
        $tokens = array_map(fn($d) => $d->getToken(), $this->deviceTokenRepository->findAll());
        $this->sendToTokens($tokens, $title, $body, $data);
    }

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