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

    // Statut de gel du portefeuille : false = actif, true = gelé
    #[ORM\Column(name: 'is_frozen', type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['wallet:read'])]
    private bool $isFrozen = false;

    // Date à laquelle le portefeuille a été gelé/dégelé
    #[ORM\Column(name: 'frozen_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['wallet:read'])]
    private ?\DateTimeInterface $frozenAt = null;

    // Admin qui a gelé/dégelé le portefeuille (pour traçabilité)
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'frozen_by_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['wallet:read'])]
    private ?User $frozenByAdmin = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    #[Groups(['wallet:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * 👈 NOUVEAU (audit intégrité financière) : verrouillage optimiste.
     * Aucune mutation de solde (WalletService, AdminWalletController,
     * AdminRefundController, AdminWithdrawalController) ne verrouillait la
     * ligne wallet — deux écritures concurrentes (ex: deux demandes de
     * retrait créées en même temps, ou un crédit manuel admin en même temps
     * qu'un remboursement) pouvaient toutes deux lire le même solde de
     * départ et écraser silencieusement l'une des deux mises à jour.
     * Avec #[ORM\Version], Doctrine lève une OptimisticLockException sur la
     * seconde écriture concurrente au lieu de la perdre silencieusement —
     * à traiter (retry) dans les contrôleurs appelants.
     * Nécessite une migration ajoutant la colonne `version` (INT NOT NULL
     * DEFAULT 1) à la table `wallets`.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    public function getVersion(): int
    {
        return $this->version;
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

    public function isFrozen(): bool
    {
        return $this->isFrozen;
    }

    public function setIsFrozen(bool $isFrozen): static
    {
        $this->isFrozen = $isFrozen;
        return $this;
    }

    public function getFrozenAt(): ?\DateTimeInterface
    {
        return $this->frozenAt;
    }

    public function setFrozenAt(?\DateTimeInterface $frozenAt): static
    {
        $this->frozenAt = $frozenAt;
        return $this;
    }

    public function getFrozenByAdmin(): ?User
    {
        return $this->frozenByAdmin;
    }

    public function setFrozenByAdmin(?User $frozenByAdmin): static
    {
        $this->frozenByAdmin = $frozenByAdmin;
        return $this;
    }

    /**
     * Get the total balance (available + reserved).
     * This is the net balance that the agency can potentially access.
     */
    public function getTotalBalance(): string
    {
        return bcadd($this->availableBalance, $this->reservedBalance, 2);
    }

    /**
     * Freeze the wallet with admin traceability.
     */
    public function freeze(User $admin): static
    {
        $this->isFrozen = true;
        $this->frozenAt = new \DateTime();
        $this->frozenByAdmin = $admin;
        $this->touch();
        return $this;
    }

    /**
     * Unfreeze the wallet.
     */
    public function unfreeze(): static
    {
        $this->isFrozen = false;
        $this->frozenAt = null;
        $this->frozenByAdmin = null;
        $this->touch();
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