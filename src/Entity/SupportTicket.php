<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * IMPORTANT — correction de sécurité :
 * L'entité portait auparavant #[ApiResource] SANS spécifier d'opérations,
 * ce qui active par défaut le CRUD complet d'API Platform (Get, GetCollection,
 * Post, Put, Patch, Delete) SANS AUCUNE CONTRAINTE DE SÉCURITÉ.
 * Concrètement, n'importe quel utilisateur (authentifié ou non, selon le
 * firewall) pouvait lister TOUS les tickets de TOUS les usagers, les modifier
 * ou les supprimer via /api/support_tickets.
 *
 * La création et la consultation des tickets sont déjà gérées par des
 * endpoints dédiés (SupportResponse::createTicket, ::getMyTickets) et
 * l'administration par AdminSupportController. Cette entité n'a donc plus
 * besoin d'être exposée comme ApiResource générique : l'attribut est retiré.
 *
 * Si un accès API Platform direct à SupportTicket est réellement nécessaire,
 * il doit être réintroduit avec des opérations explicites et sécurisées,
 * par exemple :
 *   - GetCollection avec un security "is_granted('ROLE_ADMIN')"
 *   - Get avec un security "object.getUser() == user or is_granted('ROLE_ADMIN')"
 * et ne jamais laisser d'opérations sans "operations:" explicite.
 */
#[ORM\Entity]
#[ORM\Table(name: '`support_tickets`')]
class SupportTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le sujet ne peut pas être vide.')]
    #[Assert\Length(max: 255)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le message ne peut pas être vide.')]
    private ?string $message = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private ?string $category = null;

    #[ORM\Column(length: 30, options: ['default' => 'open'])]
    #[Assert\Choice(choices: ['open', 'answered', 'closed', 'pending'])]
    private string $status = 'open';

    #[ORM\Column(length: 20, options: ['default' => 'medium'])]
    #[Assert\Choice(choices: ['low', 'medium', 'high', 'critical'])]
    private string $priority = 'medium';

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: SupportResponse::class, cascade: ['persist', 'remove'])]
    private Collection $responses;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->responses = new ArrayCollection();
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
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

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getResponses(): Collection
    {
        return $this->responses;
    }

    public function addResponse(SupportResponse $response): static
    {
        if (!$this->responses->contains($response)) {
            $this->responses->add($response);
            $response->setTicket($this);
        }
        return $this;
    }

    public function removeResponse(SupportResponse $response): static
    {
        if ($this->responses->removeElement($response)) {
            if ($response->getTicket() === $this) {
                $response->setTicket(null);
            }
        }
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}