<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use App\Controller\BookingController;
use App\Entity\Ticket;
use App\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: '`reservations`')]
#[ApiResource(
    normalizationContext: ['groups' => ['reservation:read']],
    denormalizationContext: ['groups' => ['reservation:write']],
    operations: [
        new GetCollection(),
        new Get(
            uriTemplate: '/bookings/my-bookings',
            controller: BookingController::class . '::myBookings',
            name: 'api_my_bookings'
        ),
        new Get(
            uriTemplate: '/bookings/{id}',
            controller: BookingController::class . '::getBookingDetail',
            read: false,
            name: 'api_booking_detail'
        ),
        new Post(
            uriTemplate: '/bookings/create',
            controller: BookingController::class . '::create',
            name: 'api_bookings_create'
        ),
        new GetCollection(
            uriTemplate: '/bookings/history',
            controller: BookingController::class . '::bookingHistory',
            name: 'api_bookings_history'
        )
    ]
)]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['reservation:read', 'ticket:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: "L'acheteur est obligatoire.")]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Trip::class)]
    #[ORM\JoinColumn(name: 'trip_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: "Le trajet est obligatoire.")]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?Trip $trip = null;

    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: "Le montant de la transaction est obligatoire.")]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?string $totalAmount = null;

    #[ORM\Column(name: 'payment_phone', length: 20)]
    #[Assert\NotBlank(message: "Le numéro mobile utilisé pour le prélèvement est obligatoire.")]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?string $paymentPhone = null;

    #[ORM\Column(name: 'payment_method', length: 50)]
    #[Assert\Choice(choices: ['MTN_MOMO', 'AIRTEL_MONEY'], message: "Le mode de paiement mobile est inconnu.")]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?string $paymentMethod = null;

    #[ORM\Column(name: 'payment_status', length: 30, options: ['default' => 'en_attente'])]
    #[Assert\Choice(choices: ['en_attente', 'paye', 'echoue', 'rembourse'], message: "Statut de paiement invalide.")]
    #[Groups(['reservation:read', 'reservation:write'])]
    private string $paymentStatus = 'en_attente';

    #[ORM\Column(name: 'transaction_reference', length: 100, unique: true, nullable: true)]
    #[Groups(['reservation:read', 'reservation:write'])]
    private ?string $transactionReference = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['reservation:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\OneToMany(targetEntity: \App\Entity\Ticket::class, mappedBy: 'reservation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tickets;

    public function __construct()
    {
        $this->tickets = new ArrayCollection();
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

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): static
    {
        $this->trip = $trip;
        return $this;
    }

    public function getTotalAmount(): ?string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): static
    {
        $this->totalAmount = $totalAmount;
        return $this;
    }

    public function getPaymentPhone(): ?string
    {
        return $this->paymentPhone;
    }

    public function setPaymentPhone(string $paymentPhone): static
    {
        $this->paymentPhone = $paymentPhone;
        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    public function getPaymentStatus(): string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(string $paymentStatus): static
    {
        $this->paymentStatus = $paymentStatus;
        return $this;
    }

    public function getTransactionReference(): ?string
    {
        return $this->transactionReference;
    }

    public function setTransactionReference(?string $transactionReference): static
    {
        $this->transactionReference = $transactionReference;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getTickets(): Collection
    {
        return $this->tickets;
    }
}
