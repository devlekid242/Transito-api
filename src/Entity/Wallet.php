<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repository\WalletRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Portefeuille d'une agence.
 *
 * Règle d'or : ce solde ne doit JAMAIS être modifié directement depuis un
 * contrôleur. Toute écriture doit passer par App\Service\WalletService, qui
 * garantit que chaque variation de solde est accompagnée d'une ligne dans
 * WalletTransaction (le "ledger"), afin que le solde affiché soit toujours
 * expliqué par un historique traçable.
 */
#[ORM\Entity(repositoryClass: WalletRepository::class)]
#[ORM\Table(name: '`wallets`')]
#[ApiResource(
    normalizationContext: ['groups' => ['wallet:read']],
    operations: [
        new Get(),
    ]
)]
class Wallet
{
    public const TYPE_AGENCY = 'agency';
    public const TYPE_PLATFORM = 'platform';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['wallet:read'])]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'wallet', targetEntity: Agency::class)]
    #[ORM\JoinColumn(name: 'agency_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE', unique: true)]
    #[Groups(['wallet:read'])]
    private ?Agency $agency = null;

    #[ORM\Column(length: 20, options: ['default' => 'agency'])]
    #[Groups(['wallet:read'])]
    private string $type = self::TYPE_AGENCY;

    // Solde immédiatement disponible pour une demande de retrait
    #[ORM\Column(name: 'available_balance', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['wallet:read'])]
    private string $availableBalance = '0.00';

    // Fonds bloqués le temps qu'une demande de retrait en cours soit traitée par l'admin
    #[ORM\Column(name: 'reserved_balance', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['wallet:read'])]
    private string $reservedBalance = '0.00';

    // Cumul historique des gains nets crédités (indicatif, ne diminue jamais)
    #[ORM\Column(name: 'total_earned', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['wallet:read'])]
    private string $totalEarned = '0.00';

    // Cumul historique des montants effectivement versés à l'agence
    #[ORM\Column(name: 'total_withdrawn', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['wallet:read'])]
    private string $totalWithdrawn = '0.00';

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    #[Groups(['wallet:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function isPlatform(): bool
    {
        return $this->type === self::TYPE_PLATFORM;
    }

    public function isAgency(): bool
    {
        return $this->type === self::TYPE_AGENCY;
    }

    public function getAvailableBalance(): string
    {
        return $this->availableBalance;
    }

    public function setAvailableBalance(string $availableBalance): static
    {
        $this->availableBalance = $availableBalance;
        return $this;
    }

    public function getReservedBalance(): string
    {
        return $this->reservedBalance;
    }

    public function setReservedBalance(string $reservedBalance): static
    {
        $this->reservedBalance = $reservedBalance;
        return $this;
    }

    public function getTotalEarned(): string
    {
        return $this->totalEarned;
    }

    public function setTotalEarned(string $totalEarned): static
    {
        $this->totalEarned = $totalEarned;
        return $this;
    }

    public function getTotalWithdrawn(): string
    {
        return $this->totalWithdrawn;
    }

    public function setTotalWithdrawn(string $totalWithdrawn): static
    {
        $this->totalWithdrawn = $totalWithdrawn;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTime();
        return $this;
    }
}
