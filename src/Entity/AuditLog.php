<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: '`audit_logs`')]
#[ORM\Index(name: 'idx_audit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
#[ORM\Index(name: 'idx_audit_target', columns: ['target_type', 'target_id'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_type', 'actor_id'])]
class AuditLog
{
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_USER = 'user';
    public const ACTOR_AGENT = 'agent';
    public const ACTOR_PARTNER = 'partner';
    public const ACTOR_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $actorType = self::ACTOR_SYSTEM;

    #[ORM\Column(nullable: true)]
    private ?int $actorId = null;

    #[ORM\Column(length: 80)]
    private string $action;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $targetType = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $targetId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $beforeState = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $afterState = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getActorType(): string { return $this->actorType; }
    public function setActorType(string $value): static { $this->actorType = $value; return $this; }
    public function getActorId(): ?int { return $this->actorId; }
    public function setActorId(?int $value): static { $this->actorId = $value; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $value): static { $this->action = $value; return $this; }
    public function getTargetType(): ?string { return $this->targetType; }
    public function setTargetType(?string $value): static { $this->targetType = $value; return $this; }
    public function getTargetId(): ?string { return $this->targetId; }
    public function setTargetId(?string $value): static { $this->targetId = $value; return $this; }
    public function getBeforeState(): ?array { return $this->beforeState; }
    public function setBeforeState(?array $value): static { $this->beforeState = $value; return $this; }
    public function getAfterState(): ?array { return $this->afterState; }
    public function setAfterState(?array $value): static { $this->afterState = $value; return $this; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $value): static { $this->metadata = $value; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $value): static { $this->ipAddress = $value; return $this; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $value): static { $this->userAgent = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
