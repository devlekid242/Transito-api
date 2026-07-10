<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\TicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\TicketController;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: '`tickets`')]
#[ApiResource(
    normalizationContext: ['groups' => ['ticket:read']],
    denormalizationContext: ['groups' => ['ticket:write']],
    operations: [
        new GetCollection(),
        new GetCollection(
            uriTemplate: '/tickets/available',
            controller: TicketController::class . '::getAvailableTickets',
            name: 'api_tickets_available'
        ),
        new Get(
            uriTemplate: '/tickets/{id}/validate',
            controller: TicketController::class . '::validateTicket',
            name: 'api_tickets_validate'
        ),
        new Get(
            uriTemplate: '/tickets/{id}',
            controller: TicketController::class . '::getTicketById',
            name: 'api_tickets_embark'
        )
    ]
)]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['ticket:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: "La réservation parente est obligatoire.")]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?Reservation $reservation = null;

    #[ORM\Column(name: 'passenger_name', length: 100)]
    #[Assert\NotBlank(message: "Le nom du passager est obligatoire pour le manifeste légal.")]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?string $passengerName = null;

    #[ORM\Column(name: 'passenger_phone', length: 20)]
    #[Assert\NotBlank(message: "Le numéro de téléphone du passager est obligatoire.")]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?string $passengerPhone = null;

    #[ORM\Column(name: 'passenger_cni', length: 50)]
    #[Assert\NotBlank(message: "Le numéro de CNI/Passeport est obligatoire pour la gendarmerie.")]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?string $passengerCni = null;

    #[ORM\Column(name: 'seat_number')]
    #[Assert\NotNull(message: "Un numéro de siège doit être assigné.")]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?int $seatNumber = null;

    #[ORM\Column(name: 'qr_code_token', length: 255, unique: true)]
    #[Assert\NotBlank(message: "Le jeton unique du QR Code est obligatoire.")]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?string $qrCodeToken = null;

    #[ORM\Column(length: 30, options: ['default' => 'en_attente'])]
    #[Assert\Choice(choices: ['en_attente', 'embarque', 'annule'], message: "Statut du ticket invalide.")]
    #[Groups(['ticket:read', 'ticket:write'])]
    private string $status = 'en_attente';

    #[ORM\Column(name: 'validated_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?\DateTimeInterface $validatedAt = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'validated_by_agent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['ticket:read', 'ticket:write'])]
    private ?Agent $validatedByAgent = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['ticket:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPassengerName(): ?string
    {
        return $this->passengerName;
    }

    public function setPassengerName(string $passengerName): static
    {
        $this->passengerName = $passengerName;
        return $this;
    }

    public function getPassengerPhone(): ?string
    {
        return $this->passengerPhone;
    }

    public function setPassengerPhone(string $passengerPhone): static
    {
        $this->passengerPhone = $passengerPhone;
        return $this;
    }

    public function getPassengerCni(): ?string
    {
        return $this->passengerCni;
    }

    public function setPassengerCni(string $passengerCni): static
    {
        $this->passengerCni = $passengerCni;
        return $this;
    }

    public function getSeatNumber(): ?int
    {
        return $this->seatNumber;
    }

    public function setSeatNumber(int $seatNumber): static
    {
        $this->seatNumber = $seatNumber;
        return $this;
    }

    public function getQrCodeToken(): ?string
    {
        return $this->qrCodeToken;
    }

    public function setQrCodeToken(string $qrCodeToken): static
    {
        $this->qrCodeToken = $qrCodeToken;
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

    public function getValidatedAt(): ?\DateTimeInterface
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeInterface $validatedAt): static
    {
        $this->validatedAt = $validatedAt;
        return $this;
    }

    public function getValidatedByAgent(): ?Agent
    {
        return $this->validatedByAgent;
    }

    public function setValidatedByAgent(?Agent $validatedByAgent): static
    {
        $this->validatedByAgent = $validatedByAgent;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
