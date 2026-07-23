<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\WalletTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Une ligne du "ledger" d'un portefeuille : chaque variation de solde d'un
 * Wallet doit être accompagnée d'une WalletTransaction. C'est cette table
 * qui sert de source de vérité pour l'historique des transactions affiché
 * au partenaire (et qui remplace l'utilisation directe des PaymentLog pour
 * calculer un solde).
 */
#[ORM\Entity(repositoryClass: WalletTransactionRepository::class)]
#[ORM\Table(name: '`wallet_transactions`')]
#[ApiResource(
    normalizationContext: ['groups' => ['wallet_tx:read']],
    operations: [
        new GetCollection(),
    ]
)]
class WalletTransaction
{
    public const TYPE_CREDIT = 'CREDIT';
    public const TYPE_DEBIT = 'DEBIT';

    // Crédit suite au paiement confirmé d'une réservation (montant brut)
    public const SOURCE_RESERVATION_PAYMENT = 'RESERVATION_PAYMENT';
    // Débit correspondant à la commission plateforme prélevée sur un paiement
    public const SOURCE_PLATFORM_FEE = 'PLATFORM_FEE';
    // Débit suite au remboursement d'une réservation déjà créditée
    public const SOURCE_REFUND = 'REFUND';
    // Débit : fonds gelés le temps qu'une demande de retrait soit traitée
    public const SOURCE_WITHDRAWAL_HOLD = 'WITHDRAWAL_HOLD';
    // Débit définitif : le retrait a été approuvé et versé
    public const SOURCE_WITHDRAWAL_COMPLETED = 'WITHDRAWAL_COMPLETED';
    // Crédit : la demande de retrait a été rejetée, les fonds reviennent au solde disponible
    public const SOURCE_WITHDRAWAL_RELEASED = 'WITHDRAWAL_RELEASED';
    // Ajustement manuel (litige, correction...)
    public const SOURCE_ADJUSTMENT = 'ADJUSTMENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['wallet_tx:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['wallet_tx:read'])]
    private ?Wallet $wallet = null;

    #[ORM\Column(length: 10)]
    #[Groups(['wallet_tx:read'])]
    private ?string $type = null; // CREDIT | DEBIT

    #[ORM\Column(length: 40)]
    #[Groups(['wallet_tx:read'])]
    private ?string $source = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['wallet_tx:read'])]
    private ?string $amount = null;

    #[ORM\Column(name: 'fee_amount', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['wallet_tx:read'])]
    private string $feeAmount = '0.00';

    // Solde disponible du portefeuille juste après ce mouvement (utile pour l'audit)
    #[ORM\Column(name: 'balance_after', type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['wallet_tx:read'])]
    private ?string $balanceAfter = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['wallet_tx:read'])]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(targetEntity: WithdrawalRequest::class)]
    #[ORM\JoinColumn(name: 'withdrawal_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['wallet_tx:read'])]
    private ?WithdrawalRequest $withdrawalRequest = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['wallet_tx:read'])]
    private ?string $description = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['wallet_tx:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWallet(): ?Wallet
    {
        return $this->wallet;
    }

    public function setWallet(?Wallet $wallet): static
    {
        $this->wallet = $wallet;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getFeeAmount(): string
    {
        return $this->feeAmount;
    }

    public function setFeeAmount(string $feeAmount): static
    {
        $this->feeAmount = $feeAmount;
        return $this;
    }

    public function getBalanceAfter(): ?string
    {
        return $this->balanceAfter;
    }

    public function setBalanceAfter(string $balanceAfter): static
    {
        $this->balanceAfter = $balanceAfter;
        return $this;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): static
    {
        $this->reservation = $reservation;
        return $this;
    }

    public function getWithdrawalRequest(): ?WithdrawalRequest
    {
        return $this->withdrawalRequest;
    }

    public function setWithdrawalRequest(?WithdrawalRequest $withdrawalRequest): static
    {
        $this->withdrawalRequest = $withdrawalRequest;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
