<?php

namespace App\Service\MobileMoney;

/**
 * Sélectionne le gateway (MTN ou Airtel) à partir du code opérateur déjà
 * utilisé dans votre système : Reservation::paymentMethod ('MTN_MOMO' |
 * 'AIRTEL_MONEY') et PaymentLog::operator.
 *
 * Injectée automatiquement par Symfony via le tag 'app.mobile_money_gateway'
 * (voir config/services.yaml).
 */
final class MobileMoneyGatewayFactory
{
    /** @param iterable<MobileMoneyGatewayInterface> $gateways */
    public function __construct(private readonly iterable $gateways)
    {
    }

    public function get(string $operatorCode): MobileMoneyGatewayInterface
    {
        $operatorCode = strtoupper($operatorCode);
        foreach ($this->gateways as $gateway) {
            if ($gateway->getOperatorCode() === $operatorCode) {
                return $gateway;
            }
        }

        throw new MobileMoneyException(sprintf('Aucun gateway mobile money configuré pour l\'opérateur "%s".', $operatorCode));
    }
}
