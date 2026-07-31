<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\ApplicationDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ApplicationDocumentRepository::class)]
#[ORM\Table(name: '`application_documents`')]
#[ApiResource(
    collectionOperations: [
        new GetCollection(
            name: 'api_application_documents_list',
            security: "is_granted('ROLE_ADMIN')"
        )
    ],
    itemOperations: [
        new Get(
            name: 'api_application_documents_detail',
            security: "is_granted('ROLE_ADMIN')"
        )
    ],
    normalizationContext: ['groups' => ['application_document:read']]
)]
class ApplicationDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['application_document:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Application::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'application_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Application $application = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "Le nom du document est obligatoire.")]
    #[Assert\Length(max: 255, maxMessage: "Le nom du document ne peut pas dépasser {{ limit }} caractères.")]
    #[Groups(['application_document:read', 'application:read', 'application:write'])]
    private string $name;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: "Le type de document est obligatoire.")]
    #[Assert\Choice(
        choices: ['RCCM', 'NINEA', 'ASSURANCE', 'CARTE_GRISE', 'CONTRAT', 'AUTRE'],
        message: "Le type de document doit être l'un des types autorisés."
    )]
    #[Groups(['application_document:read', 'application:read', 'application:write'])]
    private string $type;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: "La taille du document est obligatoire.")]
    #[Groups(['application_document:read', 'application:read', 'application:write'])]
    private string $size;

    #[ORM\Column(name: 'uploaded_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['application_document:read', 'application:read'])]
    private ?\DateTimeInterface $uploadedAt = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank(message: "L'URL du document est obligatoire.")]
    #[Assert\Url(message: "L'URL doit être valide.")]
    #[Groups(['application_document:read'])]
    private string $url;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['application_document:read'])]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'original_filename', length: 255, nullable: true)]
    #[Groups(['application_document:read'])]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'file_path', length: 500, nullable: true)]
    private ?string $filePath = null;

    public function __construct()
    {
        $this->uploadedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }

    public function setApplication(?Application $application): static
    {
        $this->application = $application;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSize(): string
    {
        return $this->size;
    }

    public function setSize(string $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getUploadedAt(): ?\DateTimeInterface
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(?\DateTimeInterface $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    /**
     * Get document type label for display
     */
    public function getTypeLabel(): string
    {
        $labels = [
            'RCCM' => 'Registre du Commerce et du Crédit Mobilier',
            'NINEA' => 'Numéro d\'Identification Nationale des Employeurs',
            'ASSURANCE' => 'Assurance Flotte',
            'CARTE_GRISE' => 'Carte Grise',
            'CONTRAT' => 'Contrat Social',
            'AUTRE' => 'Autre Document'
        ];
        return $labels[$this->type] ?? $this->type;
    }

    /**
     * Get icon class for document type
     */
    public function getIconClass(): string
    {
        $icons = [
            'RCCM' => 'fa-file-contract',
            'NINEA' => 'fa-file-lines',
            'ASSURANCE' => 'fa-shield-halved',
            'CARTE_GRISE' => 'fa-car',
            'CONTRAT' => 'fa-file-signature',
            'AUTRE' => 'fa-file'
        ];
        return $icons[$this->type] ?? 'fa-file';
    }
}
