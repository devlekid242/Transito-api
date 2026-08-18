<?php

namespace App\Service\MobileMoney;

/**
 * MTN MoMo exige que X-Reference-Id soit un UUID v4 valide (voir
 * documentation officielle, section requesttopay > Request headers).
 * uniqid() ne produit PAS un UUID ("pay_68a...12" par exemple), donc
 * TOUJOURS passer par ce générateur pour toute valeur envoyée comme
 * referenceId à MobileMoneyGatewayInterface::requestToPay()/transfer().
 */
final class Uuid4Generator
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}