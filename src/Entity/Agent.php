<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\AgentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AgentRepository::class)]
#[ORM\Table(name: '`agents`')]
#[ApiResource(
    normalizationContext: ['groups' => ['agent:read']],
    denormalizationContext: ['groups' => ['agent:write']]
)]
class Agent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['agent:read', 'agent:write'])]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', unique: true)]
    #[Assert\NotNull(message: "Un utilisateur est obligatoire.")]
    #[Groups(['agent:read', 'agent:write'])]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Agency::class)]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: "L'agence d'appartenance est obligatoire.")]
    #[Groups(['agent:read', 'agent:write'])]
    private ?Agency $agency = null;

    #[ORM\Column(length: 50, options: ['default' => 'agent_quai'])]
    #[Assert\Choice(choices: ['admin_agence', 'agent_quai'], message: "Le rôle de l'agent est invalide.")]
    #[Groups(['agent:read', 'agent:write'])]
    private string $agentRole = 'agent_quai';

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    #[Assert\Choice(choices: ['active', 'inactive'], message: "Le statut de l'agent est invalide.")]
    #[Groups(['agent:read', 'agent:write'])]
    private string $status = 'active';

    public function __construct() {}

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
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

    public function getAgentRole(): string
    {
        return $this->agentRole;
    }

    public function setAgentRole(string $agentRole): static
    {
        $this->agentRole = $agentRole;
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
}
