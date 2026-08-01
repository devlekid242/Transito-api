<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use App\Repository\ApplicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: '`partnership_applications`')]
/*
 * NOTE IMPORTANTE :
 * Toute la logique métier (soumission, statut, liste, détail, approbation, rejet)
 * est déjà implémentée dans EnrollmentController et AdminApplicationController
 * via des routes Symfony classiques (#[Route]). Déclarer les mêmes opérations
 * ici, dans #[ApiResource], provoquait un conflit de noms de route (les deux
 * mécanismes définissaient des routes identiques : 'api_admin_applications_list',
 * 'api_admin_applications_approve', etc.), et aurait de toute façon laissé
 * API Platform tenter de gérer ces requêtes avec son fournisseur Doctrine
 * générique — qui ne crée ni agence, ni utilisateur admin, ni email.
 * On ne déclare donc plus d'opérations ici : l'entité reste utilisable par le
 * Serializer (groupes application:read / application:write) mais n'expose
 * plus de routes API Platform en doublon.
 */
#[ApiResource(
    operations: [],
    normalizationContext: ['groups' => ['application:read']],
    denormalizationContext: ['groups' => ['application:write']]
)]
class Application
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['application:read'])]
    private ?int $id = null;

    #[ORM\Column(name: 'reference', length: 50, unique: true)]
    #[Assert\NotBlank(message: "La référence est obligatoire.")]
    #[Groups(['application:read', 'application:write'])]
    private string $reference;

    // Agency Information
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "Le nom de l'agence est obligatoire.")]
    #[Assert\Length(max: 100, maxMessage: "Le nom de l'agence ne peut pas dépasser {{ limit }} caractères.")]
    #[Groups(['application:read', 'application:write'])]
    private string $agencyName;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "Le représentant légal est obligatoire.")]
    #[Assert\Length(max: 100, maxMessage: "Le nom du représentant légal ne peut pas dépasser {{ limit }} caractères.")]
    #[Groups(['application:read', 'application:write'])]
    private string $legalRepresentative;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "L'email est obligatoire.")]
    #[Assert\Email(mode: 'strict', message: "L'email doit être valide.")]
    #[Groups(['application:read', 'application:write'])]
    private string $email;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: "Le téléphone est obligatoire.")]
    #[Assert\Regex(pattern: '/^[\+]?[0-9\s\-\()]+$/', message: "Le numéro de téléphone doit être valide.")]
    #[Groups(['application:read', 'application:write'])]
    private string $phone;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: "La ville est obligatoire.")]
    #[Groups(['application:read', 'application:write'])]
    private string $city;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['application:read', 'application:write'])]
    private ?string $address = null;

    #[ORM\Column(name: 'fleet_size', type: Types::SMALLINT)]
    #[Assert\Positive(message: "La taille de la flotte doit être positive.")]
    #[Assert\Type(type: 'integer', message: "La taille de la flotte doit être un nombre.")]
    #[Groups(['application:read', 'application:write'])]
    private int $fleetSize = 0;

    #[ORM\Column(name: 'routes_planned', type: Types::JSON)]
    #[Assert\NotBlank(message: "Les routes prévues sont obligatoires.")]
    #[Assert\Type(type: 'array', message: "Les routes prévues doivent être un tableau.")]
    #[Groups(['application:read', 'application:write'])]
    private array $routesPlanned = [];

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: "La description est obligatoire.")]
    #[Assert\Length(min: 50, minMessage: "La description doit contenir au moins {{ limit }} caractères.")]
    #[Groups(['application:read', 'application:write'])]
    private string $description;

    // Status & Tracking
    #[ORM\Column(length: 20, options: ['default' => 'PENDING'])]
    #[Assert\Choice(choices: ['PENDING', 'UNDER_REVIEW', 'APPROVED', 'REJECTED'], message: "Le statut de la candidature est invalide.")]
    #[Groups(['application:read'])]
    private string $status = 'PENDING';

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['application:read'])]
    private ?\DateTimeInterface $submittedAt = null;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['application:read'])]
    private ?\DateTimeInterface $reviewedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['application:read'])]
    private ?string $reviewer = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['application:read'])]
    private ?string $rejectionReason = null;

    #[ORM\Column(name: 'reviewer_notes', type: Types::TEXT, nullable: true)]
    #[Groups(['application:read', 'application:write'])]
    private ?string $reviewerNotes = null;

    // Relationship to created Agency (nullable until approved)
    #[ORM\OneToOne(targetEntity: Agency::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['application:read'])]
    private ?Agency $agency = null;

    // Relationship to created Admin User (nullable until approved)
    #[ORM\OneToOne(targetEntity: User::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'admin_user_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['application:read'])]
    private ?User $adminUser = null;

    // Document Relationships
    #[ORM\OneToMany(targetEntity: ApplicationDocument::class, mappedBy: 'application', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['application:read', 'application:write'])]
    private Collection $documents;

    // Timestamps
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['application:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'], nullable: true)]
    #[Groups(['application:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->submittedAt = new \DateTime();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;
        return $this;
    }

    public function getAgencyName(): string
    {
        return $this->agencyName;
    }

    public function setAgencyName(string $agencyName): static
    {
        $this->agencyName = $agencyName;
        return $this;
    }

    public function getLegalRepresentative(): string
    {
        return $this->legalRepresentative;
    }

    public function setLegalRepresentative(string $legalRepresentative): static
    {
        $this->legalRepresentative = $legalRepresentative;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
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

    public function getFleetSize(): int
    {
        return $this->fleetSize;
    }

    public function setFleetSize(int $fleetSize): static
    {
        $this->fleetSize = $fleetSize;
        return $this;
    }

    public function getRoutesPlanned(): array
    {
        return $this->routesPlanned;
    }

    public function setRoutesPlanned(array $routesPlanned): static
    {
        $this->routesPlanned = $routesPlanned;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    public function getSubmittedAt(): ?\DateTimeInterface
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeInterface $submittedAt): static
    {
        $this->submittedAt = $submittedAt;
        return $this;
    }

    public function getReviewedAt(): ?\DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeInterface $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    public function getReviewer(): ?string
    {
        return $this->reviewer;
    }

    public function setReviewer(?string $reviewer): static
    {
        $this->reviewer = $reviewer;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getReviewerNotes(): ?string
    {
        return $this->reviewerNotes;
    }

    public function setReviewerNotes(?string $reviewerNotes): static
    {
        $this->reviewerNotes = $reviewerNotes;
        return $this;
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

    public function getAdminUser(): ?User
    {
        return $this->adminUser;
    }

    public function setAdminUser(?User $adminUser): static
    {
        // NOTE : User n'a pas de méthode setAgency() (seule l'entité Agent en a une).
        // Le lien Utilisateur <-> Agence passe exclusivement par Agent (voir
        // ApplicationApprovalService::approveApplication). L'appel supprimé ici
        // provoquait une erreur fatale à chaque approbation de candidature.
        $this->adminUser = $adminUser;
        return $this;
    }

    /**
     * @return Collection<int, ApplicationDocument>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(ApplicationDocument $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setApplication($this);
        }
        return $this;
    }

    public function removeDocument(ApplicationDocument $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getApplication() === $this) {
                $document->setApplication(null);
            }
        }
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }
}