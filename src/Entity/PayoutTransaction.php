<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace un décaissement mobile money réel (Disbursement API MTN/Airtel)
 * déclenché après qu'une écriture de ledger (WalletTransaction) a déjà eu
 * lieu côté interne. Distincte de PaymentLog (qui trace les ENCAISSEMENTS
 * client) et de PaymentIntent (spécifique aux reports de réservation).
 *
 * Un PayoutTransaction est toujours créé APRÈS le débit du wallet
 * (WalletService::debitForRefund / completeWithdrawal), jamais avant :
 * le ledger interne reste la source de vérité comptable, l'appel API
 * externe n'est qu'une conséquence à exécuter et dont l'échec doit être
 * visible et rejouable par l'admin (voir PollMobileMoneyPayoutsCommand).
 */
#[ORM\Entity]
#[ORM\Table(name: 'payout_transactions')]
class PayoutTransaction
{
    public const PURPOSE_REFUND = 'REFUND';
    public const PURPOSE_WITHDRAWAL = 'WITHDRAWAL';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $reference;

    #[ORM\Column(length: 20)]
    private string $purpose;

    #[ORM\ManyToOne(targetEntity: RefundRequest::class)]
    #[ORM\JoinColumn(name: 'refund_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?RefundRequest $refundRequest = null;

    #[ORM\ManyToOne(targetEntity: WithdrawalRequest::class)]
    #[ORM\JoinColumn(name: 'withdrawal_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WithdrawalRequest $withdrawalRequest = null;

    #[ORM\Column(length: 50)]
    private string $operator; // MTN_MOMO | AIRTEL_MONEY

    #[ORM\Column(length: 30)]
    private string $recipientMsisdn;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'provider_reference', length: 150, nullable: true, unique: true)]
    private ?string $providerReference = null;

    #[ORM\Column(name: 'failure_reason', length: 255, nullable: true)]
    private ?string $failureReason = null;

    /** Nombre de tentatives de polling/relance effectuées (voir PollMobileMoneyPayoutsCommand) */
    #[ORM\Column(name: 'attempts', type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'raw_response', type: Types::TEXT, nullable: true)]
    private ?string $rawResponse = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = \App\Service\MobileMoney\Uuid4Generator::generate();
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getPurpose(): string { return $this->purpose; }
    public function setPurpose(string $purpose): static { $this->purpose = $purpose; return $this; }
    public function getRefundRequest(): ?RefundRequest { return $this->refundRequest; }
    public function setRefundRequest(?RefundRequest $r): static { $this->refundRequest = $r; return $this; }
    public function getWithdrawalRequest(): ?WithdrawalRequest { return $this->withdrawalRequest; }
    public function setWithdrawalRequest(?WithdrawalRequest $w): static { $this->withdrawalRequest = $w; return $this; }
    public function getOperator(): string { return $this->operator; }
    public function setOperator(string $operator): static { $this->operator = $operator; return $this; }
    public function getRecipientMsisdn(): string { return $this->recipientMsisdn; }
    public function setRecipientMsisdn(string $msisdn): static { $this->recipientMsisdn = $msisdn; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getProviderReference(): ?string { return $this->providerReference; }
    public function setProviderReference(?string $ref): static { $this->providerReference = $ref; return $this; }
    public function getFailureReason(): ?string { return $this->failureReason; }
    public function setFailureReason(?string $reason): static { $this->failureReason = $reason; return $this; }
    public function getAttempts(): int { return $this->attempts; }
    public function incrementAttempts(): static { $this->attempts++; return $this; }
    public function getRawResponse(): ?string { return $this->rawResponse; }
    public function setRawResponse(?string $raw): static { $this->rawResponse = $raw; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }
    public function setProcessedAt(?\DateTimeImmutable $at): static { $this->processedAt = $at; return $this; }
}
