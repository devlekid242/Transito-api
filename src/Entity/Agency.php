<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\AgencyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use App\Controller\AgencyController;

#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: '`agencies`')]
#[ApiResource(
    normalizationContext: ['groups' => ['agency:read']],
    denormalizationContext: ['groups' => ['agency:write']],
    operations: [
        new GetCollection(), // Pour l'infinite scroll de l'application mobile
        new Get(),
        new Post(),          // Inscription d'une nouvelle agence via le back-office SuperAdmin
        new Put(),           // Mise à jour des infos par l'admin d'agence
        new Delete(),
        new GetCollection(
            uriTemplate: '/agencies/active',
            controller: AgencyController::class . '::getActiveAgencies',
            name: 'api_agencies_active'
        ),
        new Post(
            uriTemplate: '/agencies/register',
            controller: AgencyController::class . '::registerAgency',
            name: 'api_agencies_register'
        )
    ]
)]
class Agency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['agency:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "Le nom de l'agence est obligatoire.")]
    #[Groups(['agency:read', 'agency:write'])]
    private ?string $name = null;

    #[ORM\Column(name: 'logo_url', length: 255, nullable: true)]
    #[Groups(['agency:read', 'agency:write'])]
    private ?string $logoUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['agency:read', 'agency:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: "Le numéro de téléphone est obligatoire.")]
    #[Groups(['agency:read', 'agency:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank(message: "L'adresse email est obligatoire.")]
    #[Assert\Email(message: "L'email n'est pas valide.")]
    #[Groups(['agency:read', 'agency:write'])]
    private ?string $email = null;

    #[ORM\Column(name: 'password_hash', length: 255)]
    #[Assert\NotBlank(message: "Le mot de passe est obligatoire.")]
    #[Groups(['agency:write'])] // Ne jamais afficher le mot de passe dans les lectures (Read)
    private ?string $passwordHash = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    #[Assert\Choice(choices: ['active', 'suspended', 'pending'], message: "Statut invalide.")]
    #[Groups(['agency:read', 'agency:write'])]
    private string $status = 'active';

    #[ORM\Column(name: 'rating_cache', type: Types::DECIMAL, precision: 3, scale: 2, nullable: true, options: ['default' => 0.00])]
    #[Groups(['agency:read'])]
    private ?string $ratingCache = '0.00';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['agency:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    // --- GETTERS ET SETTERS ---

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function setLogoUrl(?string $logoUrl): static { $this->logoUrl = $logoUrl; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(string $phone): static { $this->phone = $phone; return $this; }

    public function getEmail(): ?string { return $this->email; }    
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getPasswordHash(): ?string { return $this->passwordHash; }
    public function setPasswordHash(string $passwordHash): static { $this->passwordHash = $passwordHash; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getRatingCache(): ?string { return $this->ratingCache; }
    public function setRatingCache(?string $ratingCache): static { $this->ratingCache = $ratingCache; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
}