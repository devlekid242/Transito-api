<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Zavudev\Client;

class ZavuService
{
    private string $apiKey;
    private ?LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface $logger = null,
        string $zavuApiKey = ''
    ) {
        $this->apiKey = (string) (getenv('ZAVUDEV_API_KEY') ?: $zavuApiKey);
        $this->logger = $logger;
    }

    /**
     * Garde la même signature que TwilioService::sendWhatsApp()
     * pour ne rien casser dans les contrôleurs qui l'appellent déjà.
     */
    public function sendWhatsApp(string $to, string $message): bool
    {
        return $this->send($to, $message, 'whatsapp');
    }

    /**
     * Envoi générique. $channel peut être 'whatsapp', 'sms', 'auto', etc.
     * 'auto' laisse Zavu router intelligemment (WhatsApp -> SMS en fallback).
     */
    public function send(string $to, string $message, string $channel = 'auto'): bool
    {
        if (empty($this->apiKey)) {
            if ($this->logger) {
                $this->logger->error('Zavu API key not configured.');
            }
            return false;
        }

        try {
            $client = new Client(apiKey: $this->apiKey);

            $params = [
                'to' => $to,
                'text' => $message,
            ];

            if ($channel !== 'auto') {
                $params['channel'] = $channel;
            }

            $result = $client->messages->send($params);

            if ($this->logger) {
                $this->logger->info('Zavu message queued.', [
                    'messageId' => $result->message->id ?? null,
                    'status' => $result->message->status ?? null,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Zavu send error: '.$e->getMessage());
            }
            return false;
        }
    }
}
