<?php

namespace App\Service\Messaging;

/**
 * Contrat commun que doit respecter tout service capable d'envoyer
 * un message WhatsApp (et/ou un code OTP), quel que soit le provider
 * (Twilio, Infobip, ...).
 */
interface WhatsAppSenderInterface
{
    /**
     * Envoie un message WhatsApp.
     *
     * @param string $to      Numéro du destinataire (avec ou sans préfixe "whatsapp:")
     * @param string $message Contenu du message (ex: le code OTP)
     *
     * @return bool true si l'envoi a réussi, false sinon
     */
    public function sendWhatsApp(string $to, string $message): bool;
}