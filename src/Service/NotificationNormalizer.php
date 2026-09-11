<?php

namespace App\Service;

use App\Entity\Notification;

class NotificationNormalizer
{
    /**
     * 👈 NOUVEAU : fallback UNIQUEMENT pour les notifications créées avant
     * l'introduction du champ `section`, ou créées sans le préciser.
     * Dès que l'appelant (contrôleur métier, formulaire admin, etc.) fournit
     * `section` explicitement, on l'utilise telle quelle — ce mapping n'est
     * jamais consulté dans ce cas.
     */
    private const CATEGORY_TO_SECTION_FALLBACK = [
        'PAYMENT' => 'gestion-finance',
        'BOOKING' => 'reservations',
        'TRIP' => 'trip-schedule',
        // INFO / PROMOTION : pas de section évidente → badge générique
        // "Notifications" uniquement (voir resolveSection()).
    ];

    private function resolveSection(Notification $notification, string $category): ?string
    {
        if ($notification->getSection() !== null) {
            return $notification->getSection();
        }

        return self::CATEGORY_TO_SECTION_FALLBACK[$category] ?? null;
    }

    public function normalize(Notification $notification): array
    {
        $category = strtoupper($notification->getCategory() ?? 'INFO');
        $content = strtolower($notification->getContent() ?? '');
        $title = strtolower($notification->getTitle() ?? '');
        $combined = $title . ' ' . $content;

        // Logique de déduction intelligente centralisée
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
            'section' => $this->resolveSection($notification, $category),
            'payload' => $notification->getPayload(),
            'isRead' => $notification->getIsRead() === 1,
            'createdAt' => $notification->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
    public function normalizeForUser(Notification $notification, bool $isRead): array
    {
        $data = $this->normalize($notification);
        $data['isRead'] = $isRead;
        return $data;
    }

}