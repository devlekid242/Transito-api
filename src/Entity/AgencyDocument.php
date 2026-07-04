<?php

namespace App\Entity;

use App\Repository\AgencyDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AgencyDocumentRepository::class)]
#[ORM\Table(name: '`agency_documents`')]
class AgencyDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['agency:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agency::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Agency $agency = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le nom du document est obligatoire.')]
    #[Groups(['agency:read'])]
    private ?string $name = null;

    #[ORM\Column(name: 'file_url', length: 500)]
    #[Assert\NotBlank(message: 'L\'URL du document est obligatoire.')]
    #[Groups(['agency:read'])]
    private ?string $fileUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['agency:read'])]
    private ?string $type = null;

    #[ORM\Column(length: 50, options: ['default' => 'pending'])]
    #[Groups(['agency:read'])]
    private string $status = 'pending';

    #[ORM\Column(name: 'expiry_date', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['agency:read'])]
    private ?\DateTimeInterface $expiryDate = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['agency:read'])]
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getFileUrl(): ?string
    {
        return $this->fileUrl;
    }

    public function setFileUrl(string $fileUrl): static
    {
        $this->fileUrl = $fileUrl;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

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

    public function getExpiryDate(): ?\DateTimeInterface
    {
        return $this->expiryDate;
    }

    public function setExpiryDate(?\DateTimeInterface $expiryDate): static
    {
        $this->expiryDate = $expiryDate;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
