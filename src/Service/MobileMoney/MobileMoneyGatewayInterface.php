<?php

namespace App\Service\MobileMoney;

/**
 * Contrat commun pour un opérateur mobile money (MTN MoMo, Airtel Money...).
 *
 * Les deux opérations "Collection" (encaisser de l'argent d'un client, ex:
 * paiement d'une réservation) et "Disbursement" (envoyer de l'argent à un
 * bénéficiaire, ex: remboursement client ou retrait partenaire) sont
 * volontairement séparées, exactement comme MTN et Airtel les séparent
 * eux-mêmes (deux produits distincts, deux jeux de clés distincts).
 *
 * Toutes les méthodes lèvent MobileMoneyException en cas d'échec réseau ou
 * de réponse HTTP en erreur. Elles ne lèvent JAMAIS d'exception pour un
 * statut métier "PENDING" ou "FAILED" côté opérateur : ça, c'est le contenu
 * normal de MobileMoneyStatus, à gérer par l'appelant.
 */
interface MobileMoneyGatewayInterface
{
    /** Identifiant de l'opérateur, doit correspondre à Reservation::paymentMethod / PaymentLog::operator */
    public function getOperatorCode(): string;

    /**
     * Déclenche une demande de paiement ("Request To Pay" / USSD push) vers
     * le téléphone du client. Ne renvoie PAS le résultat final : l'opérateur
     * répond juste "requête acceptée", le client doit ensuite valider sur
     * son téléphone. Le statut réel s'obtient via getCollectionStatus().
     *
     * @param string $referenceId  UUID v4 généré par nous, sert de clé d'idempotence côté opérateur
     * @param string $amount       Montant en chaîne décimale (jamais de float), ex: "2500.00"
     * @param string $msisdn       Numéro du payeur, format international sans "+", ex: "242068001122"
     * @param string $externalId   Notre référence interne (ex: PaymentLog::reference)
     */
    public function requestToPay(
        string $referenceId,
        string $amount,
        string $msisdn,
        string $externalId,
        string $payerMessage = 'Paiement billet',
        string $payeeNote = 'Reservation'
    ): void;

    /** Interroge le statut réel d'une demande de paiement précédemment initiée. */
    public function getCollectionStatus(string $referenceId): MobileMoneyStatus;

    /**
     * Envoie de l'argent vers un bénéficiaire (remboursement client ou
     * retrait partenaire). Comme requestToPay, c'est asynchrone : le statut
     * réel s'obtient via getDisbursementStatus().
     */
    public function transfer(
        string $referenceId,
        string $amount,
        string $msisdn,
        string $externalId,
        string $payerMessage = 'Remboursement',
        string $payeeNote = 'Refund'
    ): void;

    /** Interroge le statut réel d'un transfert précédemment initié. */
    public function getDisbursementStatus(string $referenceId): MobileMoneyStatus;
}
