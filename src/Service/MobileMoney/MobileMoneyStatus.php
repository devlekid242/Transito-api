<?php

namespace App\Service\MobileMoney;

/**
 * Statut normalisé, commun à MTN et Airtel, pour que PaymentController /
 * PayoutService n'aient jamais à connaître le vocabulaire propre à chaque
 * opérateur (MTN: SUCCESSFUL/FAILED, Airtel: TS/TF...).
 */
final class MobileMoneyStatus
{
    public const PENDING = 'PENDING';
    public const SUCCESS = 'SUCCESS';
    public const FAILED = 'FAILED';

    public function __construct(
        public readonly string $status,        // PENDING | SUCCESS | FAILED
        public readonly ?string $providerReference = null,
        public readonly ?string $reason = null,  // code/raison d'échec, si dispo
        public readonly ?string $rawResponse = null, // payload brut JSON, pour audit/PaymentLog::rawResponse
    ) {
    }

    public function isFinal(): bool
    {
        return $this->status !== self::PENDING;
    }
}
