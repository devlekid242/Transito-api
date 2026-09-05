<?php

namespace App\Service\Messaging;

use App\Service\InfobipService;
use App\Service\TwilioService;

/**
 * Point d'entrée unique pour envoyer un SMS ou un WhatsApp,
 * quel que soit le provider actif.
 *
 * Le provider par défaut est configurable indépendamment par canal
 * (tu peux par exemple utiliser Twilio pour le WhatsApp et Infobip pour le SMS).
 *
 * Utilisation :
 *   $factory->getWhatsAppSender()->sendWhatsApp($to, $message);
 *   $factory->getSmsSender()->sendSms($to, $message);
 *
 *   // ou en forçant un provider précis, ex. pour un test / fallback :
 *   $factory->getWhatsAppSender('infobip')->sendWhatsApp($to, $message);
 */
class MessagingProviderFactory
{
    public function __construct(
        private TwilioService $twilioService,
        private InfobipService $infobipService,
        // Valeurs injectées depuis la config, ex: %env(MESSAGING_PROVIDER_WHATSAPP)%
        private string $defaultWhatsAppProvider = 'twilio',
        // ex: %env(MESSAGING_PROVIDER_SMS)%
        private string $defaultSmsProvider = 'infobip',
    ) {
    }

    public function getWhatsAppSender(?string $provider = null): WhatsAppSenderInterface
    {
        $provider = strtolower($provider ?? $this->defaultWhatsAppProvider);

        return match ($provider) {
            'twilio' => $this->twilioService,
            'infobip' => $this->infobipService,
            default => throw new \InvalidArgumentException(
                sprintf('Provider WhatsApp inconnu : "%s". Valeurs possibles : twilio, infobip.', $provider)
            ),
        };
    }

    public function getSmsSender(?string $provider = null): SmsSenderInterface
    {
        $provider = strtolower($provider ?? $this->defaultSmsProvider);

        return match ($provider) {
            'twilio' => $this->twilioService,
            'infobip' => $this->infobipService,
            default => throw new \InvalidArgumentException(
                sprintf('Provider SMS inconnu : "%s". Valeurs possibles : twilio, infobip.', $provider)
            ),
        };
    }
}