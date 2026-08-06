<?php

namespace App\Entity;

use App\Repository\AdminActivityLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminActivityLogRepository::class)]
#[ORM\Table(name: '`admin_activity_logs`')]
#[ORM\Index(name: 'idx_admin_id', columns: ['admin_id'])]
#[ORM\Index(name: 'idx_action_type', columns: ['action_type'])]
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
class AdminActivityLog
{
    public const ACTION_TYPE_AUTH = 'AUTH';
    public const ACTION_TYPE_FINANCE = 'FINANCE';
    public const ACTION_TYPE_MODERATION = 'MODERATION';
    public const ACTION_TYPE_SETTINGS = 'SETTINGS';
    public const ACTION_TYPE_PROFILE = 'PROFILE';
    public const ACTION_TYPE_SYSTEM = 'SYSTEM';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class, inversedBy: 'activityLogs')]
    #[ORM\JoinColumn(name: 'admin_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Admin $admin = null;

    #[ORM\Column(length: 50)]
    private string $actionType = self::ACTION_TYPE_SYSTEM;

    #[ORM\Column(length: 100)]
    private string $action = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetEntity = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdmin(): ?Admin
    {
        return $this->admin;
    }

    public function setAdmin(?Admin $admin): static
    {
        $this->admin = $admin;
        return $this;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): static
    {
        $this->actionType = $actionType;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getTargetEntity(): ?string
    {
        return $this->targetEntity;
    }

    public function setTargetEntity(?string $targetEntity): static
    {
        $this->targetEntity = $targetEntity;
        return $this;
    }

    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    public function setTargetId(?string $targetId): static
    {
        $this->targetId = $targetId;
        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Get action type badge color class (for frontend consistency)
     */
    public function getTypeColorClass(): string
    {
        $colors = [
            self::ACTION_TYPE_FINANCE => 'text-green-600',
            self::ACTION_TYPE_MODERATION => 'text-emerald-600',
            self::ACTION_TYPE_SETTINGS => 'text-amber-600',
            self::ACTION_TYPE_AUTH => 'text-cyan-600',
            self::ACTION_TYPE_PROFILE => 'text-violet-600',
            self::ACTION_TYPE_SYSTEM => 'text-gray-600',
        ];

        return $colors[$this->actionType] ?? 'text-gray-600';
    }

    /**
     * Get action type background color class (for frontend consistency)
     */
    public function getTypeBgColorClass(): string
    {
        $colors = [
            self::ACTION_TYPE_FINANCE => 'bg-green-100',
            self::ACTION_TYPE_MODERATION => 'bg-emerald-100',
            self::ACTION_TYPE_SETTINGS => 'bg-amber-100',
            self::ACTION_TYPE_AUTH => 'bg-cyan-100',
            self::ACTION_TYPE_PROFILE => 'bg-violet-100',
            self::ACTION_TYPE_SYSTEM => 'bg-gray-100',
        ];

        return $colors[$this->actionType] ?? 'bg-gray-100';
    }

    /**
     * Create a log entry for profile update
     */
    public static function createProfileUpdate(
        Admin $admin,
        string $details,
        string $ipAddress = null,
        string $userAgent = null
    ): static {
        $log = new self();
        $log->setAdmin($admin);
        $log->setActionType(self::ACTION_TYPE_PROFILE);
        $log->setAction('Profile updated');
        $log->setDetails($details);
        $log->setIpAddress($ipAddress);
        $log->setUserAgent($userAgent);
        
        return $log;
    }

    /**
     * Create a log entry for password change
     */
    public static function createPasswordChange(
        Admin $admin,
        string $ipAddress = null,
        string $userAgent = null
    ): static {
        $log = new self();
        $log->setAdmin($admin);
        $log->setActionType(self::ACTION_TYPE_AUTH);
        $log->setAction('Password changed');
        $log->setIpAddress($ipAddress);
        $log->setUserAgent($userAgent);
        
        return $log;
    }

    /**
     * Create a log entry for login
     */
    public static function createLogin(
        Admin $admin,
        string $ipAddress = null,
        string $userAgent = null
    ): static {
        $log = new self();
        $log->setAdmin($admin);
        $log->setActionType(self::ACTION_TYPE_AUTH);
        $log->setAction('Logged in');
        $log->setIpAddress($ipAddress);
        $log->setUserAgent($userAgent);
        
        return $log;
    }

    /**
     * Create a generic admin action log
     */
    public static function createAdminAction(
        Admin $admin,
        string $actionType,
        string $action,
        string $targetEntity = null,
        string $targetId = null,
        string $details = null,
        string $ipAddress = null,
        string $userAgent = null
    ): static {
        $log = new self();
        $log->setAdmin($admin);
        $log->setActionType($actionType);
        $log->setAction($action);
        $log->setTargetEntity($targetEntity);
        $log->setTargetId($targetId);
        $log->setDetails($details);
        $log->setIpAddress($ipAddress);
        $log->setUserAgent($userAgent);
        
        return $log;
    }
}