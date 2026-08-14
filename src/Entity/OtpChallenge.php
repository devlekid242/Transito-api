<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OtpChallengeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OtpChallengeRepository::class)]
#[ORM\Table(name: 'otp_challenges')]
#[ORM\Index(name: 'IDX_OTP_PHONE_CREATED', columns: ['phone_number', 'requested_at'])]
class OtpChallenge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'phone_number', length: 20)]
    private string $phoneNumber;

    #[ORM\Column(name: 'code_hash', length: 255)]
    private string $codeHash;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'attempts', type: Types::SMALLINT, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    public function __construct(string $phoneNumber, string $codeHash, \DateTimeImmutable $requestedAt, \DateTimeImmutable $expiresAt)
    {
        $this->phoneNumber = $phoneNumber;
        $this->codeHash = $codeHash;
        $this->requestedAt = $requestedAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getPhoneNumber(): string { return $this->phoneNumber; }
    public function getCodeHash(): string { return $this->codeHash; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getAttempts(): int { return $this->attempts; }
    public function incrementAttempts(): static { ++$this->attempts; return $this; }
    public function getConsumedAt(): ?\DateTimeImmutable { return $this->consumedAt; }
    public function consume(\DateTimeImmutable $at): static { $this->consumedAt = $at; return $this; }
    public function isExpired(\DateTimeImmutable $now): bool { return $this->expiresAt <= $now; }
    public function isConsumed(): bool { return $this->consumedAt !== null; }
}
