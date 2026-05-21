<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\PromoCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
#[ORM\Table(name: '`promo_codes`')]
#[ApiResource(
    normalizationContext: ['groups' => ['promo:read']],
    denormalizationContext: ['groups' => ['promo:write']]
)]
class PromoCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['promo:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank(message: "Le code est obligatoire.")]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $code = null;

    #[ORM\Column(name: 'discount_type', length: 20)]
    #[Assert\Choice(choices: ['percentage', 'fixed'], message: "Le type de réduction est invalide.")]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $discountType = 'percentage';

    #[ORM\Column(name: 'discount_value', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank(message: "La valeur de la réduction est obligatoire.")]
    #[Assert\Positive(message: "La valeur doit être positive.")]
    #[Groups(['promo:read', 'promo:write'])]
    private ?string $discountValue = null;

    #[ORM\Column(name: 'valid_from', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?\DateTimeInterface $validFrom = null;

    #[ORM\Column(name: 'valid_until', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?\DateTimeInterface $validUntil = null;

    #[ORM\Column(name: 'max_uses', nullable: true)]
    #[Groups(['promo:read', 'promo:write'])]
    private ?int $maxUses = null;

    #[ORM\Column(name: 'current_uses', options: ['default' => 0])]
    #[Groups(['promo:read'])]
    private int $currentUses = 0;

    #[ORM\Column(name: 'is_active', type: Types::SMALLINT, options: ['default' => 1])]
    #[Groups(['promo:read', 'promo:write'])]
    private int $isActive = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['promo:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
        return $this;
    }

    public function getDiscountType(): ?string
    {
        return $this->discountType;
    }

    public function setDiscountType(string $discountType): static
    {
        $this->discountType = $discountType;
        return $this;
    }

    public function getDiscountValue(): ?string
    {
        return $this->discountValue;
    }

    public function setDiscountValue(string $discountValue): static
    {
        $this->discountValue = $discountValue;
        return $this;
    }

    public function getValidFrom(): ?\DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeInterface $validFrom): static
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeInterface $validUntil): static
    {
        $this->validUntil = $validUntil;
        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): static
    {
        $this->maxUses = $maxUses;
        return $this;
    }

    public function getCurrentUses(): int
    {
        return $this->currentUses;
    }

    public function setCurrentUses(int $currentUses): static
    {
        $this->currentUses = $currentUses;
        return $this;
    }

    public function incrementUses(): static
    {
        $this->currentUses++;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}