<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\Partner\PartnerFinanceController;
use App\Controller\Admin\AdminWithdrawalController;
use App\Entity\Agency;
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
        new GetCollection(
            uriTemplate: '/partner/withdrawals',
            controller: PartnerFinanceController::class . '::listWithdrawals',
            name: 'api_partner_withdrawals_list'
        ),
        // Traitement admin : ces deux routes finalisent (ou annulent) le blocage
        // de fonds effectué à la création de la demande. Brancher un contrôle de
        // rôle (super-admin) avant mise en production — voir TODO dans le contrôleur.
        new Post(
            uriTemplate: '/admin/withdrawals/{id}/approve',
            controller: AdminWithdrawalController::class . '::approve',
            name: 'api_admin_withdrawals_approve'
        ),
        new Post(
            uriTemplate: '/admin/withdrawals/{id}/reject',
            controller: AdminWithdrawalController::class . '::reject',
            name: 'api_admin_withdrawals_reject'
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

    // L'agence propriétaire du portefeuille débité. Tous les agents d'une même
    // agence (admin_agence ou agent_quai) partagent donc le même historique de
    // retraits, puisque c'est l'agence — et non l'agent — qui possède les fonds.
    #[ORM\ManyToOne(targetEntity: Agency::class)]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['withdrawal:read'])]
    private ?Agency $agency = null;

    // L'utilisateur (agent) qui a initié la demande — traçabilité uniquement,
    // n'est plus utilisé pour retrouver le solde ou l'historique.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['withdrawal:read'])]
    private ?User $requestedBy = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant est obligatoire.')]
    #[Assert\Positive(message: 'Le montant doit être positif.')]
    #[Groups(['withdrawal:read', 'withdrawal:write'])]
    private ?string $amount = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'La méthode de retrait est obligatoire.')]
    #[Groups(['withdrawal:read', 'withdrawal:write'])]
    private ?string $method = null;

    // pending | approved | rejected
    #[ORM\Column(length: 50)]
    #[Groups(['withdrawal:read'])]
    private ?string $status = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['withdrawal:read', 'withdrawal:write'])]
    private ?string $notes = null;

    // Renseigné par l'admin lors de l'approbation ou du rejet
    #[ORM\Column(name: 'admin_note', type: Types::TEXT, nullable: true)]
    #[Groups(['withdrawal:read'])]
    private ?string $adminNote = null;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['withdrawal:read'])]
    private ?\DateTimeInterface $processedAt = null;

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

    public function getAgency(): ?Agency
    {
        return $this->agency;
    }

    public function setAgency(?Agency $agency): static
    {
        $this->agency = $agency;
        return $this;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): static
    {
        $this->requestedBy = $requestedBy;
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

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): static
    {
        $this->adminNote = $adminNote;
        return $this;
    }

    public function getProcessedAt(): ?\DateTimeInterface
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeInterface $processedAt): static
    {
        $this->processedAt = $processedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
