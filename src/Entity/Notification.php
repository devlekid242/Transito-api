<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Controller\NotificationController;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: '`notifications`')]
#[ApiResource(
    normalizationContext: ['groups' => ['notification:read']],
    denormalizationContext: ['groups' => ['notification:write']],
    operations: [
        new GetCollection(),
        new GetCollection(
            uriTemplate: '/notifications/unread',
            controller: NotificationController::class . '::getUnreadNotifications',
            name: 'api_notifications_unread'
        ),
        new Put(
            uriTemplate: '/notifications/{id}/read',
            controller: NotificationController::class . '::markAsRead',
            name: 'api_notifications_mark_read'
        )
    ]
)]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['notification:read'])]
    private ?int $id = null;

    #[ORM\Column(name: 'recipient_type', length: 50)]
    #[Assert\Choice(choices: ['user', 'agent', 'agency_all'], message: "Le type de destinataire est inconnu.")]
    #[Groups(['notification:read', 'notification:write'])]
    private ?string $recipientType = null;

    #[ORM\Column(name: 'recipient_id', nullable: true)]
    #[Groups(['notification:read', 'notification:write'])]
    private ?int $recipientId = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: "Le titre de la notification est obligatoire.")]
    #[Groups(['notification:read', 'notification:write'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: "Le contenu du message est obligatoire.")]
    #[Groups(['notification:read', 'notification:write'])]
    private ?string $content = null;

    #[ORM\Column(name: 'is_read', type: Types::SMALLINT, options: ['default' => 0])]
    #[Groups(['notification:read', 'notification:write'])]
    private int $isRead = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['notification:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipientType(): ?string
    {
        return $this->recipientType;
    }

    public function setRecipientType(string $recipientType): static
    {
        $this->recipientType = $recipientType;
        return $this;
    }

    public function getRecipientId(): ?int
    {
        return $this->recipientId;
    }

    public function setRecipientId(?int $recipientId): static
    {
        $this->recipientId = $recipientId;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getIsRead(): int
    {
        return $this->isRead;
    }

    public function setIsRead(int $isRead): static
    {
        $this->isRead = $isRead;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}