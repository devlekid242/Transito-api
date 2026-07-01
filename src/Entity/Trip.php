<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Controller\TripController;
use App\Repository\TripRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\Table(name: '`trips`')]
#[ApiResource(
    normalizationContext: ['groups' => ['trip:read']],
    denormalizationContext: ['groups' => ['trip:write']],
    operations: [
        new GetCollection(
            uriTemplate: '/trips',
            controller: TripController::class . '::index',
            read: false,
            name: 'api_trips_search'
        ),
        new GetCollection(
            uriTemplate: '/trips/popular',
            controller: TripController::class . '::popular',
            read: false,
            name: 'api_trip_popular'
        ),
        new Get(
            uriTemplate: '/trips/{id}',
            controller: TripController::class . '::detail',
            read: false,
            name: 'api_trip_detail'
        ),
        new GetCollection(
            uriTemplate: '/trips/cities/departure',
            controller: TripController::class . '::departureCities',
            read: false,
            name: 'api_trip_departure_cities'
        ),
        new GetCollection(
            uriTemplate: '/trips/cities/arrival',
            controller: TripController::class . '::arrivalCities',
            read: false,
            name: 'api_trip_arrival_cities'
        )
    ]
)]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['trip:read', 'reservation:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agency::class)]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: "L'agence organisatrice est obligatoire.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?Agency $agency = null;

    #[ORM\ManyToOne(targetEntity: Bus::class)]
    #[ORM\JoinColumn(name: 'bus_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: "Le bus assigné est obligatoire.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?Bus $bus = null;

    #[ORM\ManyToOne(targetEntity: AgencyPoint::class)]
    #[ORM\JoinColumn(name: 'departure_point_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: "Le point de départ d'embarquement est obligatoire.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?AgencyPoint $departurePoint = null;

    #[ORM\ManyToOne(targetEntity: AgencyPoint::class)]
    #[ORM\JoinColumn(name: 'arrival_point_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: "Le point d'arrivée de débarquement est obligatoire.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?AgencyPoint $arrivalPoint = null;

    #[ORM\Column(name: 'departure_time', type: Types::DATETIME_MUTABLE)]
    #[Assert\NotNull(message: "L'heure de départ est obligatoire.")]
    #[Assert\GreaterThanOrEqual("today", message: "La date de départ ne peut pas être antérieure à aujourd'hui.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?\DateTimeInterface $departureTime = null;

    #[ORM\Column(name: 'estimated_arrival_time', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['trip:read', 'trip:write'])]
    private ?\DateTimeInterface $estimatedArrivalTime = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: "Le prix du ticket est obligatoire.")]
    #[Assert\Positive(message: "Le prix doit être positif.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?string $price = null;

    #[ORM\Column(name: 'driver_name', length: 100, nullable: true)]
    #[Groups(['trip:read', 'trip:write'])]
    private ?string $driverName = null;

    #[ORM\Column(length: 30, options: ['default' => 'planifie'])]
    #[Assert\Choice(choices: ['planifie', 'embarquement', 'en_route', 'termine', 'annule'], message: "Le statut du trajet est invalide.")]
    #[Groups(['trip:read', 'trip:write'])]
    private string $status = 'planifie';

    #[ORM\Column(name: 'seats_reserved', options: ['default' => 0])]
    #[Groups(['trip:read', 'trip:write'])]
    private int $seatsReserved = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['trip:read'])]
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

    public function getBus(): ?Bus
    {
        return $this->bus;
    }

    public function setBus(?Bus $bus): static
    {
        $this->bus = $bus;
        return $this;
    }

    public function getDeparturePoint(): ?AgencyPoint
    {
        return $this->departurePoint;
    }

    public function setDeparturePoint(?AgencyPoint $departurePoint): static
    {
        $this->departurePoint = $departurePoint;
        return $this;
    }

    public function getArrivalPoint(): ?AgencyPoint
    {
        return $this->arrivalPoint;
    }

    public function setArrivalPoint(?AgencyPoint $arrivalPoint): static
    {
        $this->arrivalPoint = $arrivalPoint;
        return $this;
    }

    public function getDepartureTime(): ?\DateTimeInterface
    {
        return $this->departureTime;
    }

    public function setDepartureTime(\DateTimeInterface $departureTime): static
    {
        $this->departureTime = $departureTime;
        return $this;
    }

    public function getEstimatedArrivalTime(): ?\DateTimeInterface
    {
        return $this->estimatedArrivalTime;
    }

    public function setEstimatedArrivalTime(?\DateTimeInterface $estimatedArrivalTime): static
    {
        $this->estimatedArrivalTime = $estimatedArrivalTime;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getDriverName(): ?string
    {
        return $this->driverName;
    }

    public function setDriverName(?string $driverName): static
    {
        $this->driverName = $driverName;
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

    public function getSeatsReserved(): int
    {
        return $this->seatsReserved;
    }

    public function setSeatsReserved(int $seatsReserved): static
    {
        $this->seatsReserved = $seatsReserved;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}