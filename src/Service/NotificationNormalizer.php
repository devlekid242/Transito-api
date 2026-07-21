<?php

namespace App\Service;

use App\Entity\Notification;

class NotificationNormalizer
{
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
            'payload' => $notification->getPayload(),
            'isRead' => $notification->getIsRead() === 1,
            'createdAt' => $notification->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}