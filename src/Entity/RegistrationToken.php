<?php

namespace App\Entity;

use App\Repository\RegistrationTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton de courte durée prouvant qu'un numéro de téléphone vient d'être
 * vérifié par OTP, sans qu'aucun compte n'existe encore pour ce numéro.
 *
 * Émis par AuthController::verifyOtp() quand aucun User n'est trouvé.
 * Consommé par AuthController::completeProfile() lors de la création du compte.
 *
 * On ne stocke jamais le jeton en clair : seul son hash SHA-256 est persisté,
 * comme pour les refresh tokens.
 */
#[ORM\Entity(repositoryClass: RegistrationTokenRepository::class)]
#[ORM\Table(name: 'registration_tokens')]
class RegistrationToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'phone_number', length: 20)]
    private string $phoneNumber;

    #[ORM\Column(name: 'token_hash', length: 255, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    public function __construct(
        string $phoneNumber,
        string $tokenHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt
    ) {
        $this->phoneNumber = $phoneNumber;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhoneNumber(): string
    {
        return $this->phoneNumber;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt < $now;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
    }
}