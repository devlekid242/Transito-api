<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\SupportController;

#[ORM\Entity]
#[ORM\Table(name: '`support_responses`')]
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/support/tickets',
            controller: SupportController::class . '::createTicket',
            name: 'api_support_create_ticket',
            // Correction : aucune sécurité n'était définie — n'importe quel
            // visiteur pouvait créer un ticket au nom d'un autre utilisateur
            // si le contrôleur ne vérifie pas explicitement l'identité.
            security: "is_granted('ROLE_USER')"
        ),
        new GetCollection(
            uriTemplate: '/support/my-tickets',
            controller: SupportController::class . '::getMyTickets',
            name: 'api_support_my_tickets',
            // Correction : sans cette contrainte, un utilisateur non
            // authentifié pouvait potentiellement appeler cette route.
            // ATTENTION : le contrôleur SupportController (non fourni) doit
            // impérativement filtrer les résultats sur l'utilisateur courant
            // ($this->getUser()) — cette annotation seule ne suffit pas à
            // empêcher un utilisateur connecté de voir les tickets des autres
            // si le contrôleur ne fait pas ce filtrage lui-même.
            security: "is_granted('ROLE_USER')"
        )
    ]
)]
class SupportResponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupportTicket::class, inversedBy: 'responses')]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?SupportTicket $ticket = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Agent $agent = null;

    #[ORM\Column(type: 'text')]
    private ?string $message = null;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?SupportTicket
    {
        return $this->ticket;
    }

    public function setTicket(?SupportTicket $ticket): static
    {
        $this->ticket = $ticket;
        return $this;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function setAgent(?Agent $agent): static
    {
        $this->agent = $agent;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}