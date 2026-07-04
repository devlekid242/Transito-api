<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\AgencyPointController;
use App\Entity\Agency;
use App\Repository\AgencyPointRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AgencyPointRepository::class)]
#[ORM\Table(name: '`agency_points`')]
#[ApiResource(
    normalizationContext: ['groups' => ['point:read']],
    denormalizationContext: ['groups' => ['point:write']],
    operations: [
        new GetCollection(),
        new GetCollection(
            uriTemplate: '/agency-points/by-agency/{agencyId}',
            controller: AgencyPointController::class . '::getByAgency',
            name: 'api_agency_points_by_agency'
        )
    ]
)]
class AgencyPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['point:read', 'trip:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agency::class)]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: "L'agence est obligatoire.")]
    #[Groups(['point:read', 'point:write'])]
    private ?Agency $agency = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "La ville est obligatoire.")]
    #[Groups(['point:read', 'point:write', 'trip:read'])]
    private ?string $city = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: "Le nom du point est obligatoire.")]
    #[Groups(['point:read', 'point:write', 'trip:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['point:read', 'point:write'])]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['point:read', 'point:write'])]
    private ?string $quartier = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['point:read', 'point:write'])]
    private ?string $phone = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['point:read', 'point:write'])]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['point:read', 'point:write'])]
    private ?float $longitude = null;

    #[ORM\Column(length: 40, options: ['default' => 'principal'])]
    #[Groups(['point:read', 'point:write'])]
    private string $pointType = 'principal';

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    #[Groups(['point:read', 'point:write'])]
    private string $status = 'active';

    #[ORM\Column(name: 'is_active', type: Types::SMALLINT, options: ['default' => 1])]
    #[Groups(['point:read', 'point:write'])]
    private int $isActive = 1;

    #[ORM\Column(name: 'has_vip_lounge', type: Types::SMALLINT, options: ['default' => 0])]
    #[Groups(['point:read', 'point:write'])]
    private int $hasVipLounge = 0;

    // #[ORM\Column(name: 'has_vip_lounge', type: Types::SMALLINT, options: ['default' => 0])]
    // #[Groups(['point:read', 'point:write'])]
    // private int $hasVipLounge = 0;

    #[ORM\Column(name: 'has_wifi', type: Types::SMALLINT, options: ['default' => 0])]
    #[Groups(['point:read', 'point:write'])]
    private int $hasWifi = 0;

    #[ORM\Column(name: 'has_ac', type: Types::SMALLINT, options: ['default' => 0])]
    #[Groups(['point:read', 'point:write'])]
    private int $hasAc = 0;

    #[ORM\Column(name: 'has_parking', type: Types::SMALLINT, options: ['default' => 0])]
    #[Groups(['point:read', 'point:write'])]
    private int $hasParking = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['point:read'])]
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

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    public function getQuartier(): ?string
    {
        return $this->quartier;
    }

    public function setQuartier(?string $quartier): static
    {
        $this->quartier = $quartier;
        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getPointType(): string
    {
        return $this->pointType;
    }

    public function setPointType(string $pointType): static
    {
        $this->pointType = $pointType;
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

    public function getIsActive(): int
    {
        return $this->isActive;
    }

    public function setIsActive(int $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive === 1;
    }

    public function getHasVipLounge(): int
    {
        return $this->hasVipLounge;
    }

    public function setHasVipLounge(int $hasVipLounge): static
    {
        $this->hasVipLounge = $hasVipLounge;
        return $this;
    }

    public function getHasWifi(): int
    {
        return $this->hasWifi;
    }

    public function setHasWifi(int $hasWifi): static
    {
        $this->hasWifi = $hasWifi;
        return $this;
    }

    public function getHasAc(): int
    {
        return $this->hasAc;
    }

    public function setHasAc(int $hasAc): static
    {
        $this->hasAc = $hasAc;
        return $this;
    }

    public function getHasParking(): int
    {
        return $this->hasParking;
    }

    public function setHasParking(int $hasParking): static
    {
        $this->hasParking = $hasParking;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
