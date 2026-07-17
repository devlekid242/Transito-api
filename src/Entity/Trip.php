<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
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
            uriTemplate: '/trips/uncoming',
            controller: TripController::class . '::uncoming',
            read: false,
            name: 'api_trip_uncoming'
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
        new Post(
            uriTemplate: '/trips',
            controller: TripController::class . '::create',
            read: false,
            name: 'api_trip_create'
        ),
        new Put(
            uriTemplate: '/trips/{id}',
            controller: TripController::class . '::update',
            read: false,
            name: 'api_trip_update'
        ),
        new Delete(
            uriTemplate: '/trips/{id}',
            controller: TripController::class . '::delete',
            read: false,
            name: 'api_trip_delete'
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

    #[ORM\Column(name: 'departure_city', length: 100, nullable: true)]
    #[Assert\NotBlank(message: "La ville de départ est obligatoire.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?string $departureCity = null;

    #[ORM\Column(name: 'arrival_city', length: 100, nullable: true)]
    #[Assert\NotBlank(message: "La ville d'arrivée est obligatoire.")]
    #[Groups(['trip:read', 'trip:write'])]
    private ?string $arrivalCity = null;

    #[ORM\Column(name: 'boarding_points', type: Types::JSON)]
    #[Groups(['trip:read', 'trip:write'])]
    private array $boardingPoints = [];

    #[ORM\Column(name: 'deboarding_points', type: Types::JSON)]
    #[Groups(['trip:read', 'trip:write'])]
    private array $deboardingPoints = [];

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

    #[ORM\Column(name: 'trip_date', type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['trip:read', 'trip:write'])]
    private ?\DateTimeInterface $tripDate = null;

    #[ORM\Column(name: 'departure_time_of_day', type: Types::TIME_MUTABLE, nullable: true)]
    #[Groups(['trip:read', 'trip:write'])]
    private ?\DateTimeInterface $departureTimeOfDay = null;

    #[ORM\Column(name: 'arrival_time_of_day', type: Types::TIME_MUTABLE, nullable: true)]
    #[Groups(['trip:read', 'trip:write'])]
    private ?\DateTimeInterface $arrivalTimeOfDay = null;

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

    public function getDepartureCity(): ?string
    {
        return $this->departureCity;
    }

    public function setDepartureCity(?string $departureCity): static
    {
        $this->departureCity = $departureCity;
        return $this;
    }

    public function getArrivalCity(): ?string
    {
        return $this->arrivalCity;
    }

    public function setArrivalCity(?string $arrivalCity): static
    {
        $this->arrivalCity = $arrivalCity;
        return $this;
    }

    public function getBoardingPoints(): array
    {
        return $this->boardingPoints;
    }

    public function setBoardingPoints(array $boardingPoints): static
    {
        $this->boardingPoints = $boardingPoints;
        return $this;
    }

    public function getDeboardingPoints(): array
    {
        return $this->deboardingPoints;
    }

    public function setDeboardingPoints(array $deboardingPoints): static
    {
        $this->deboardingPoints = $deboardingPoints;
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

    public function getTripDate(): ?\DateTimeInterface
    {
        return $this->tripDate;
    }

    public function setTripDate(?\DateTimeInterface $tripDate): static
    {
        $this->tripDate = $tripDate;
        return $this;
    }

    public function getDepartureTimeOfDay(): ?\DateTimeInterface
    {
        return $this->departureTimeOfDay;
    }

    public function setDepartureTimeOfDay(?\DateTimeInterface $departureTimeOfDay): static
    {
        $this->departureTimeOfDay = $departureTimeOfDay;
        return $this;
    }

    public function getArrivalTimeOfDay(): ?\DateTimeInterface
    {
        return $this->arrivalTimeOfDay;
    }

    public function setArrivalTimeOfDay(?\DateTimeInterface $arrivalTimeOfDay): static
    {
        $this->arrivalTimeOfDay = $arrivalTimeOfDay;
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
