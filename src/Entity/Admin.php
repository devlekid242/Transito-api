<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\AdminRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AdminRepository::class)]
#[ORM\Table(name: '`admins`')]
#[ApiResource(
    normalizationContext: ['groups' => ['admin:read']],
    denormalizationContext: ['groups' => ['admin:write']]
)]
class Admin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['admin:read', 'admin:write'])]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', unique: true)]
    #[Assert\NotNull(message: "Un utilisateur est obligatoire.")]
    #[Groups(['admin:read', 'admin:write'])]
    private ?User $user = null;

    /**
     * Admin roles: SUPER_ADMIN, FINANCE_ADMIN, MODERATION_ADMIN, SUPPORT_ADMIN
     */
    #[ORM\Column(length: 50, options: ['default' => 'SUPPORT_ADMIN'])]
    #[Assert\Choice(choices: ['SUPER_ADMIN', 'FINANCE_ADMIN', 'MODERATION_ADMIN', 'SUPPORT_ADMIN'], message: "Le rôle admin est invalide.")]
    #[Groups(['admin:read', 'admin:write'])]
    private string $adminRole = 'SUPPORT_ADMIN';

    /**
     * Admin status: active, inactive, suspended
     */
    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    #[Assert\Choice(choices: ['active', 'inactive', 'suspended'], message: "Le statut est invalide.")]
    #[Groups(['admin:read', 'admin:write'])]
    private string $status = 'active';

    /**
     * JSON array of permissions specific to this admin
     * Example: {"view_users", "edit_users", "view_finance", "approve_withdrawals"}
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['admin:read', 'admin:write'])]
    private ?array $permissions = null;

    /**
     * Department or area of responsibility
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['admin:read', 'admin:write'])]
    private ?string $department = null;

    /**
     * Notes or additional info about the admin
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['admin:read', 'admin:write'])]
    private ?string $notes = null;

    /**
     * Last login timestamp
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['admin:read'])]
    private ?\DateTimeInterface $lastLoginAt = null;

    /**
     * When admin was created in the system
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['admin:read'])]
    private ?\DateTimeInterface $createdAt = null;

    /**
     * When admin was last modified
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['admin:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getAdminRole(): string
    {
        return $this->adminRole;
    }

    public function setAdminRole(string $adminRole): static
    {
        $this->adminRole = $adminRole;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getPermissions(): ?array
    {
        return $this->permissions;
    }

    public function setPermissions(?array $permissions): static
    {
        $this->permissions = $permissions;
        return $this;
    }

    public function hasPermission(string $permission): bool
    {
        if (!$this->permissions) {
            return false;
        }
        return in_array($permission, $this->permissions, true);
    }

    public function addPermission(string $permission): static
    {
        if (!$this->permissions) {
            $this->permissions = [];
        }
        if (!in_array($permission, $this->permissions, true)) {
            $this->permissions[] = $permission;
        }
        return $this;
    }

    public function removePermission(string $permission): static
    {
        if ($this->permissions) {
            $this->permissions = array_filter(
                $this->permissions,
                fn($p) => $p !== $permission
            );
        }
        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $this->department = $department;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeInterface
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeInterface $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
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

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
