<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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

    #[ORM\Column(name: 'created_at' , type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $closedAt = null;


    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: Trip::class)]
    #[ORM\JoinColumn(name: 'trip_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Trip $trip = null;

    #[ORM\ManyToOne(targetEntity: Agency::class)]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Agency $agency = null;

    #[ORM\Column(name: 'first_response_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstResponseAt = null;

    #[ORM\Column(name: 'sla_due_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $slaDueAt = null;

    #[ORM\Column(name: 'closed_reason', type: Types::TEXT, nullable: true)]
    private ?string $closedReason = null;

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

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getClosedAt(): ?\DateTimeInterface
    {
        return $this->closedAt;
    }


    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): static
    {
        $this->reservation = $reservation;
        return $this;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): static
    {
        $this->trip = $trip;
        return $this;
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

    public function getFirstResponseAt(): ?\DateTimeInterface
    {
        return $this->firstResponseAt;
    }

    public function markFirstResponse(): static
    {
        $this->firstResponseAt ??= new \DateTime();
        return $this;
    }

    public function getSlaDueAt(): ?\DateTimeInterface
    {
        return $this->slaDueAt;
    }

    public function setSlaDueAt(?\DateTimeInterface $slaDueAt): static
    {
        $this->slaDueAt = $slaDueAt;
        return $this;
    }

    public function getClosedReason(): ?string
    {
        return $this->closedReason;
    }

    public function setClosedReason(?string $closedReason): static
    {
        $this->closedReason = $closedReason;
        return $this;
    }

    public function isSlaBreached(?\DateTimeInterface $now = null): bool
    {
        if ($this->status === 'closed' || $this->firstResponseAt !== null || $this->slaDueAt === null) {
            return false;
        }
        return ($now ?? new \DateTime()) > $this->slaDueAt;
    }

    public function close(): static
    {
        $this->status = 'closed';
        $this->closedAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        return $this;
    }
}