<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reservation_reschedules')]
class ReservationReschedule
{
    public const STATUS_READY = 'READY';
    public const STATUS_PAYMENT_PENDING = 'PAYMENT_PENDING';
    public const STATUS_REFUND_REQUIRED = 'REFUND_REQUIRED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: Trip::class)]
    #[ORM\JoinColumn(name: 'from_trip_id', referencedColumnName: 'id', nullable: false)]
    private ?Trip $fromTrip = null;

    #[ORM\ManyToOne(targetEntity: Trip::class)]
    #[ORM\JoinColumn(name: 'to_trip_id', referencedColumnName: 'id', nullable: false)]
    private ?Trip $toTrip = null;

    #[ORM\Column(name: 'old_total', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $oldTotal = '0.00';

    #[ORM\Column(name: 'new_total', type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $newTotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $difference = '0.00';

    #[ORM\Column(length: 20)]
    private string $direction = 'NONE';

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_READY;

    #[ORM\Column(name: 'requested_seats', type: Types::JSON)]
    private array $requestedSeats = [];

    #[ORM\Column(name: 'quote_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $quoteExpiresAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReservation(): ?Reservation { return $this->reservation; }
    public function setReservation(Reservation $reservation): static { $this->reservation = $reservation; return $this; }
    public function getFromTrip(): ?Trip { return $this->fromTrip; }
    public function setFromTrip(Trip $trip): static { $this->fromTrip = $trip; return $this; }
    public function getToTrip(): ?Trip { return $this->toTrip; }
    public function setToTrip(Trip $trip): static { $this->toTrip = $trip; return $this; }
    public function getOldTotal(): string { return $this->oldTotal; }
    public function setOldTotal(string $value): static { $this->oldTotal = $value; return $this; }
    public function getNewTotal(): string { return $this->newTotal; }
    public function setNewTotal(string $value): static { $this->newTotal = $value; return $this; }
    public function getDifference(): string { return $this->difference; }
    public function setDifference(string $value): static { $this->difference = $value; return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $value): static { $this->direction = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): static { $this->status = $value; return $this; }
    public function getRequestedSeats(): array { return $this->requestedSeats; }
    public function setRequestedSeats(array $value): static { $this->requestedSeats = array_values($value); return $this; }
    public function getQuoteExpiresAt(): ?\DateTimeImmutable { return $this->quoteExpiresAt; }
    public function setQuoteExpiresAt(?\DateTimeImmutable $value): static { $this->quoteExpiresAt = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
