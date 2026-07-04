<?php

namespace App\Service;

use App\Entity\Notification;
use Pusher\Pusher;

class NotificationBroadcastService
{
    public function __construct(private Pusher $pusher) {}

    public function broadcast(Notification $notification): void
    {
        $channel = $this->resolveChannel($notification);
        $payload = [
            'id' => $notification->getId(),
            'recipientType' => $notification->getRecipientType(),
            'recipientId' => $notification->getRecipientId(),
            'title' => $notification->getTitle(),
            'message' => $notification->getContent(),
            'type' => strtoupper($notification->getCategory() ?? 'INFO'),
            'category' => strtoupper($notification->getCategory() ?? 'INFO'),
            'payload' => $notification->getPayload(),
            'isRead' => $notification->getIsRead() === 1,
            'createdAt' => $notification->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];

        $this->pusher->trigger($channel, 'new-notification', $payload);
    }

    private function resolveChannel(Notification $notification): string
    {
        if ($notification->getRecipientType() === 'user' && $notification->getRecipientId() !== null) {
            return 'private-user-' . $notification->getRecipientId();
        }

        if ($notification->getRecipientType() === 'agency_all') {
            return 'private-global';
        }

        return 'private-global';
    }
}
