<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class TwilioService
{
    private string $accountSid;
    private string $authToken;
    private string $whatsappFrom;
    private ?\Psr\Log\LoggerInterface $logger;
    private const DEFAULT_SANDBOX_FROM = 'whatsapp:+14155238886';
    private const DEFAULT_SANDBOX_AUTH_TOKEN = '0e5d425276828894f85646efbeb7d87f';
    private const DEFAULT_SANDBOX_ACCOUNT_SID = 'AC34f537a4fed885e65182c9b1c0617927';

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->accountSid = getenv('TWILIO_ACCOUNT_SID') ?: self::DEFAULT_SANDBOX_ACCOUNT_SID;
        $this->authToken = getenv('TWILIO_AUTH_TOKEN') ?: self::DEFAULT_SANDBOX_AUTH_TOKEN;
        // En version gratuite Twilio, le numéro expéditeur doit être le sandbox WhatsApp de Twilio.
        // En production, on peut remplacer cette valeur par un vrai numéro WhatsApp approuvé.
        $this->whatsappFrom = getenv('TWILIO_WHATSAPP_FROM') ?: self::DEFAULT_SANDBOX_FROM;
        $this->logger = $logger;
    }

    public function sendWhatsApp(string $to, string $message): bool
    {
        if (empty($this->accountSid) || empty($this->authToken) || empty($this->whatsappFrom)) {
            if ($this->logger) {
                $this->logger->error('Twilio credentials not configured.');
            }
            return false;
        }

        try {
            $client = new \Twilio\Rest\Client($this->accountSid, $this->authToken);
            $toFormatted = preg_match('/^whatsapp:/', $to) ? $to : 'whatsapp:'.$to;
            $fromFormatted = preg_match('/^whatsapp:/', $this->whatsappFrom) ? $this->whatsappFrom : 'whatsapp:'.$this->whatsappFrom;

            // Version de production (commentée) si tu as un vrai numéro WhatsApp approuvé :
            // $client->messages->create($toFormatted, ['from' => $fromFormatted, 'body' => $message]);

            $client->messages->create($toFormatted, [
                'from' => $fromFormatted,
                'body' => $message,
                // 'contentSid' => 'HXb5b62575e6e4ff6129ad7c8efe1f983e',
                // 'contentVariables' => '{"1":"12/1","2":"3pm"}',
            ]);

            return true;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Twilio send error: '.$e->getMessage());
            }
            return false;
        }
    }
}
