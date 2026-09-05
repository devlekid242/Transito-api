<?php

namespace App\Service\Messaging;

/**
 * Contrat commun que doit respecter tout service capable d'envoyer
 * un SMS, quel que soit le provider (Twilio, Infobip, ...).
 */
interface SmsSenderInterface
{
    /**
     * Envoie un SMS.
     *
     * @param string $to      Numéro du destinataire (format E.164, ex: +33612345678)
     * @param string $message Contenu du message (ex: le code OTP)
     *
     * @return bool true si l'envoi a réussi, false sinon
     */
    public function sendSms(string $to, string $message): bool;
}