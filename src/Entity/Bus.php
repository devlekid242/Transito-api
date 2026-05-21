<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\BusRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BusRepository::class)]
#[ORM\Table(name: '`buses`')]
#[ApiResource(
    normalizationContext: ['groups' => ['bus:read']],
    denormalizationContext: ['groups' => ['bus:write']]
)]
class Bus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['bus:read', 'trip:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agency::class)]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: "L'agence d'appartenance est obligatoire.")]
    #[Groups(['bus:read', 'bus:write'])]
    private ?Agency $agency = null;

    #[ORM\Column(name: 'registration_number', length: 30, unique: true)]
    #[Assert\NotBlank(message: "Le numéro d'immatriculation est obligatoire.")]
    #[Groups(['bus:read', 'bus:write', 'trip:read'])]
    private ?string $registrationNumber = null;

    #[ORM\Column]
    #[Assert\NotNull(message: "La capacité d'assise du bus est obligatoire.")]
    #[Assert\Positive(message: "La capacité doit être supérieure à zéro.")]
    #[Groups(['bus:read', 'bus:write', 'trip:read'])]
    private ?int $capacity = null;

    #[ORM\Column(length: 30, options: ['default' => 'Classique'])]
    #[Assert\Choice(choices: ['VIP', 'Classique'], message: "La catégorie de bus choisie est invalide.")]
    #[Groups(['bus:read', 'bus:write', 'trip:read'])]
    private string $category = 'Classique';

    #[ORM\Column(length: 30, options: ['default' => 'disponible'])]
    #[Assert\Choice(choices: ['disponible', 'maintenance', 'hors_service'], message: "Le statut du bus est invalide.")]
    #[Groups(['bus:read', 'bus:write'])]
    private string $status = 'disponible';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['bus:read'])]
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

    public function getRegistrationNumber(): ?string
    {
        return $this->registrationNumber;
    }

    public function setRegistrationNumber(string $registrationNumber): static
    {
        $this->registrationNumber = $registrationNumber;
        return $this;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): static
    {
        $this->capacity = $capacity;
        return $this;
    }

    public function getCategory(): string
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}