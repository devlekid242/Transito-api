<?php

namespace App\Service\MobileMoney;

/**
 * Erreur technique (réseau, auth, réponse HTTP inattendue) lors d'un appel
 * à l'API d'un opérateur. Ne représente jamais un simple "paiement refusé
 * par le client" — ça, c'est un MobileMoneyStatus::FAILED normal, pas une
 * exception.
 */
class MobileMoneyException extends \RuntimeException
{
}
