<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\Partner\PartnerFinanceController;
use App\Entity\User;
use App\Repository\WithdrawalRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WithdrawalRequestRepository::class)]
#[ORM\Table(name: '`withdrawal_requests`')]
#[ApiResource(
    normalizationContext: ['groups' => ['withdrawal:read']],
    denormalizationContext: ['groups' => ['withdrawal:write']],
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            uriTemplate: '/partner/withdrawals',
            controller: PartnerFinanceController::class . '::createWithdrawal',
            name: 'api_partner_withdrawals_create'
        ),
        new Get(
            uriTemplate: '/partner/withdrawals/{id}',
            controller: PartnerFinanceController::class . '::getWithdrawal',
            name: 'api_partner_withdrawals_detail'
        ),
        new Get(
            uriTemplate: '/partner/withdrawals',
            controller: PartnerFinanceController::class . '::listWithdrawals',
            name: 'api_partner_withdrawals_list'
        )
    ]
)]
class WithdrawalRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['withdrawal:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['withdrawal:read'])]
    private ?User $user = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant est obligatoire.')]
    #[Assert\Positive(message: 'Le montant doit être positif.')]
    #[Groups(['withdrawal:read', 'withdrawal:write'])]
    private ?string $amount = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'La méthode de retrait est obligatoire.')]
    #[Groups(['withdrawal:read', 'withdrawal:write'])]
    private ?string $method = null;

    #[ORM\Column(length: 50)]
    #[Groups(['withdrawal:read'])]
    private ?string $status = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['withdrawal:read', 'withdrawal:write'])]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['withdrawal:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
