<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_intents')]
class PaymentIntent
{
    public const PURPOSE_RESCHEDULE = 'RESCHEDULE';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $reference;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: ReservationReschedule::class)]
    #[ORM\JoinColumn(name: 'reschedule_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ReservationReschedule $reschedule = null;

    #[ORM\Column(length: 40)]
    private string $purpose = self::PURPOSE_RESCHEDULE;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 50)]
    private string $operator;

    #[ORM\Column(name: 'provider_reference', length: 150, nullable: true, unique: true)]
    private ?string $providerReference = null;

    #[ORM\Column(name: 'raw_response', type: Types::TEXT, nullable: true)]
    private ?string $rawResponse = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = uniqid('pi_', true);
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getReservation(): ?Reservation { return $this->reservation; }
    public function setReservation(Reservation $reservation): static { $this->reservation = $reservation; return $this; }
    public function getReschedule(): ?ReservationReschedule { return $this->reschedule; }
    public function setReschedule(ReservationReschedule $reschedule): static { $this->reschedule = $reschedule; return $this; }
    public function getPurpose(): string { return $this->purpose; }
    public function setPurpose(string $purpose): static { $this->purpose = $purpose; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getOperator(): string { return $this->operator; }
    public function setOperator(string $operator): static { $this->operator = $operator; return $this; }
    public function getProviderReference(): ?string { return $this->providerReference; }
    public function setProviderReference(?string $providerReference): static { $this->providerReference = $providerReference; return $this; }
    public function getRawResponse(): ?string { return $this->rawResponse; }
    public function setRawResponse(?string $rawResponse): static { $this->rawResponse = $rawResponse; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }
    public function setProcessedAt(?\DateTimeImmutable $processedAt): static { $this->processedAt = $processedAt; return $this; }
}
