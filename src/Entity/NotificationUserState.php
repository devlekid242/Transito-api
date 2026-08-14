<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\NotificationUserStateRepository;

#[ORM\Entity(repositoryClass: NotificationUserStateRepository::class)]
#[ORM\Table(name: 'notification_user_states')]
#[ORM\UniqueConstraint(name: 'uniq_notification_user_state', columns: ['notification_id', 'user_id'])]
class NotificationUserState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Notification::class, inversedBy: 'userStates')]
    #[ORM\JoinColumn(name: 'notification_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Notification $notification;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'is_read', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deletedAt = null;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $readAt = null;

    public function getId(): ?int { return $this->id; }
    public function getNotification(): Notification { return $this->notification; }
    public function setNotification(Notification $notification): static { $this->notification = $notification; return $this; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function isRead(): bool { return $this->isRead; }
    public function markRead(): static { $this->isRead = true; $this->readAt = new \DateTime(); return $this; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function markDeleted(): static { $this->deletedAt = new \DateTime(); return $this; }
    public function getDeletedAt(): ?\DateTimeInterface { return $this->deletedAt; }
}
