<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\PaymentLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: PaymentLogRepository::class)]
#[ORM\Table(name: '`payment_logs`')]
#[ApiResource(
    normalizationContext: ['groups' => ['payment:read']],
    // Généralement, on empêche l'écriture directe via l'API, ce sont nos webhooks internes qui créent les logs
    operations: [
        new \ApiPlatform\Metadata\Get(),
        new \ApiPlatform\Metadata\GetCollection()
    ]
)]
class PaymentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['payment:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['payment:read'])]
    private ?Reservation $reservation = null;

    #[ORM\Column(length: 50)]
    #[Groups(['payment:read'])]
    private ?string $operator = null; // Ex: MTN, AIRTEL

    #[ORM\Column(length: 100)]
    #[Groups(['payment:read'])]
    private ?string $reference = null; // L'ID envoyé par l'opérateur GSM

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['payment:read'])]
    private ?string $amount = null;

    #[ORM\Column(length: 30)]
    #[Groups(['payment:read'])]
    private ?string $status = null; // Ex: SUCCESS, FAILED, PENDING

    #[ORM\Column(name: 'raw_response', type: Types::TEXT, nullable: true)]
    // On cache généralement la réponse brute dans l'API publique pour des raisons de sécurité
    private ?string $rawResponse = null; 

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['payment:read'])]
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

    public function getOperator(): ?string
    {
        return $this->operator;
    }

    public function setOperator(string $operator): static
    {
        $this->operator = $operator;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }

    public function setRawResponse(?string $rawResponse): static
    {
        $this->rawResponse = $rawResponse;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}