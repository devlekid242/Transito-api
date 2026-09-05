<?php

namespace App\Service;

use App\Service\Messaging\SmsSenderInterface;
use App\Service\Messaging\WhatsAppSenderInterface;
use Psr\Log\LoggerInterface;

class InfobipService implements WhatsAppSenderInterface, SmsSenderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private string $whatsappFrom;
    private string $smsFrom;
    private ?LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface $logger = null,
        string $infobipApiKey = '',
        string $infobipBaseUrl = '',
        string $infobipWhatsAppFrom = '',
        string $infobipSmsFrom = ''
        )
    {
        $this->apiKey = (string) (getenv('INFOBIP_API_KEY') ? getenv('INFOBIP_API_KEY') : $infobipApiKey);
        // Le baseUrl Infobip est propre à chaque compte, ex: https://xxxxxx.api.infobip.com
        $this->baseUrl = rtrim((string) (getenv('INFOBIP_BASE_URL') ? getenv('INFOBIP_BASE_URL') : $infobipBaseUrl), '/');
        // Numéro WhatsApp Business approuvé côté Infobip (format E.164, sans "whatsapp:")
        $this->whatsappFrom = (string) (getenv('INFOBIP_WHATSAPP_FROM') ? getenv('INFOBIP_WHATSAPP_FROM') : $infobipWhatsAppFrom);
        // Sender ID/numéro dédié au SMS chez Infobip (souvent un alphanumeric sender ID, ex: "MonApp")
        $this->smsFrom = (string) (getenv('INFOBIP_SMS_FROM') ? getenv('INFOBIP_SMS_FROM') : $infobipSmsFrom);
        $this->logger = $logger;
    }

    public function sendWhatsApp(string $to, string $message): bool
    {
        if (empty($this->apiKey) || empty($this->baseUrl) || empty($this->whatsappFrom)) {
            if ($this->logger) {
                $this->logger->error('Infobip credentials not configured.');
            }
            return false;
        }

        try {
            // Infobip attend des numéros bruts (sans le préfixe "whatsapp:" utilisé par Twilio)
            $toFormatted = preg_replace('/^whatsapp:/', '', $to);
            $fromFormatted = preg_replace('/^whatsapp:/', '', $this->whatsappFrom);

            $payload = [
                'messages' => [
                    [
                        'from' => $fromFormatted,
                        'to' => $toFormatted,
                        'content' => [
                            'text' => $message,
                        ],
                    ],
                ],
            ];

            $this->callInfobip('/whatsapp/1/message/text', $payload);

            return true;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Infobip WhatsApp send error: '.$e->getMessage());
            }
            return false;
        }
    }

    public function sendSms(string $to, string $message): bool
    {
        if (empty($this->apiKey) || empty($this->baseUrl) || empty($this->smsFrom)) {
            if ($this->logger) {
                $this->logger->error('Infobip SMS credentials not configured.');
            }
            return false;
        }

        try {
            $toFormatted = preg_replace('/^whatsapp:/', '', $to);

            $payload = [
                'messages' => [
                    [
                        'from' => $this->smsFrom,
                        'destinations' => [
                            ['to' => $toFormatted],
                        ],
                        'text' => $message,
                    ],
                ],
            ];

            $this->callInfobip('/sms/2/text/advanced', $payload);

            return true;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Infobip SMS send error: '.$e->getMessage());
            }
            return false;
        }
    }

    /**
     * Appel générique vers l'API Infobip (cURL), factorisé entre SMS et WhatsApp.
     *
     * @throws \RuntimeException si la requête échoue ou renvoie un code HTTP d'erreur
     */
    private function callInfobip(string $endpoint, array $payload): string
    {
        $ch = curl_init($this->baseUrl.$endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: App '.$this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \RuntimeException('cURL error: '.$curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('Infobip API returned HTTP '.$httpCode.': '.$response);
        }

        return (string) $response;
    }
}