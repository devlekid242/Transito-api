<?php

namespace App\Service;

use App\Repository\SystemSettingRepository;

/**
 * Calcule les frais que les opérateurs mobile money (MTN, Airtel, ...)
 * facturent à la plateforme sur chaque opération momo.
 *
 * Les taux sont configurables par opérateur depuis le back-office
 * (PUT /api/admin/settings, clé "momoOperators"), car ils varient dans le
 * temps et diffèrent d'un opérateur à l'autre.
 *
 * RÈGLE MÉTIER (validée le 04/09/2026 avec le porteur du projet) :
 *  - ENCAISSEMENT (paiement client) : le coût est répercuté sur le CLIENT,
 *    ajouté au total facturé au moment du paiement
 *    (prix billet + frais app + frais momo).
 *  - DÉCAISSEMENT (remboursement client ou retrait partenaire) : le coût
 *    est absorbé par la PLATEFORME. Le bénéficiaire reçoit toujours le
 *    montant net plein, jamais amputé du frais opérateur.
 *
 * Toute la logique financière est en bcmath (chaînes), jamais en float.
 */
class MomoFeeService
{
    /** Taux de secours (%) si un opérateur n'est pas / plus configuré. */
    private const DEFAULT_RATE = 3.0;

    public function __construct(
        private readonly SystemSettingRepository $systemSettingRepository,
    ) {
    }

    /**
     * @return array<int, array{id:string,name:string,collectionFeeRate:float,disbursementFeeRate:float,enabled:bool}>
     */
    public function listOperators(bool $onlyEnabled = false): array
    {
        $setting = $this->systemSettingRepository->findOrCreateSystemSetting();
        $data = $setting->getData();
        $operators = is_array($data['momoOperators'] ?? null) ? $data['momoOperators'] : [];

        if ($onlyEnabled) {
            $operators = array_values(array_filter(
                $operators,
                static fn (array $op): bool => (bool) ($op['enabled'] ?? true)
            ));
        }

        return $operators;
    }

    public function getOperator(string $operatorId): ?array
    {
        foreach ($this->listOperators() as $operator) {
            if (($operator['id'] ?? null) === $operatorId) {
                return $operator;
            }
        }

        return null;
    }

    public function isOperatorEnabled(string $operatorId): bool
    {
        $operator = $this->getOperator($operatorId);
        return $operator !== null && (bool) ($operator['enabled'] ?? true);
    }

    /**
     * Frais d'ENCAISSEMENT pour un opérateur, calculé sur une base donnée
     * (prix billet + frais app). À ajouter au total facturé au client.
     */
    public function collectionFee(string $operatorId, string $baseAmount): string
    {
        return $this->applyRate($baseAmount, $this->rateFor($operatorId, 'collectionFeeRate'));
    }

    /**
     * Frais de DÉCAISSEMENT pour un opérateur, calculé sur le montant
     * transféré. C'est un coût plateforme : ne jamais le déduire du montant
     * reçu par le bénéficiaire (client remboursé / agence qui retire).
     */
    public function disbursementFee(string $operatorId, string $amount): string
    {
        return $this->applyRate($amount, $this->rateFor($operatorId, 'disbursementFeeRate'));
    }

    private function rateFor(string $operatorId, string $field): float
    {
        $operator = $this->getOperator($operatorId);
        if (!$operator || !array_key_exists($field, $operator)) {
            return self::DEFAULT_RATE;
        }

        $rate = (float) $operator[$field];
        return $rate >= 0 ? $rate : self::DEFAULT_RATE;
    }

    private function applyRate(string $baseAmount, float $ratePercent): string
    {
        $rate = number_format($ratePercent, 4, '.', '');
        $fee = bcdiv(bcmul($baseAmount, $rate, 6), '100', 2);

        return bccomp($fee, '0.00', 2) < 0 ? '0.00' : $fee;
    }
}
