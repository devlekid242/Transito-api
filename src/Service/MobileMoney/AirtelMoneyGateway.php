<?php

namespace App\Service\MobileMoney;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Client Airtel Money OpenAPI — Collections + Disbursements.
 * Documentation officielle : https://developers.airtel.africa
 *
 * Auth : OAuth2 client_credentials sur /auth/oauth2/token (client_id +
 * client_secret obtenus après inscription sur le portail développeur).
 *
 * ⚠️ IMPORTANT — Congo Brazzaville : vérifiez dans votre tableau de bord
 * Airtel Developer que le marché "Congo Brazzaville" (code pays CG / devise
 * XAF) est bien activé pour votre application. La couverture Airtel Money
 * varie par pays et doit être confirmée avec votre "Relationship Manager"
 * Airtel avant la mise en prod — en sandbox le endpoint répond même pour
 * des pays non activés en prod, donc le test seul ne le garantit pas.
 *
 * ⚠️ Disbursement (transfer) : l'API Airtel exige un PIN marchand chiffré
 * en RSA avec la clé publique fournie dans votre portail développeur
 * (Encryption Keys). Le squelette ci-dessous (encryptPin) doit être
 * complété avec cette clé — voir le guide joint.
 */
final class AirtelMoneyGateway implements MobileMoneyGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,        // ex: https://openapiuat.airtel.africa (sandbox)
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $country = 'CG',
        private readonly string $currency = 'XAF',
        private readonly ?string $disbursementPin = null,        // PIN marchand en clair, JAMAIS logué
        private readonly ?string $disbursementPublicKeyPem = null, // clé publique RSA fournie par Airtel pour chiffrer le PIN
    ) {
    }

    public function getOperatorCode(): string
    {
        return 'AIRTEL_MONEY';
    }

    // ------------------------------------------------------------------
    // Collections
    // ------------------------------------------------------------------

    public function requestToPay(
        string $referenceId,
        string $amount,
        string $msisdn,
        string $externalId,
        string $payerMessage = 'Paiement billet',
        string $payeeNote = 'Reservation'
    ): void {
        $token = $this->getToken();

        $this->call('POST', '/merchant/v1/payments/', [
            'headers' => $this->headers($token),
            'json' => [
                'reference' => $externalId,
                'subscriber' => [
                    'country' => $this->country,
                    'currency' => $this->currency,
                    'msisdn' => $this->normalizeMsisdnForAirtel($msisdn),
                ],
                'transaction' => [
                    'amount' => (float) $amount, // Airtel attend un nombre JSON, pas une chaîne
                    'country' => $this->country,
                    'currency' => $this->currency,
                    'id' => $referenceId,
                ],
            ],
            'expectedStatus' => [200],
        ]);
    }

    public function getCollectionStatus(string $referenceId): MobileMoneyStatus
    {
        $token = $this->getToken();
        $response = $this->call('GET', '/standard/v1/payments/' . $referenceId, [
            'headers' => $this->headers($token),
            'expectedStatus' => [200],
        ]);

        return $this->mapStatus($response);
    }

    // ------------------------------------------------------------------
    // Disbursements
    // ------------------------------------------------------------------

    public function transfer(
        string $referenceId,
        string $amount,
        string $msisdn,
        string $externalId,
        string $payerMessage = 'Remboursement',
        string $payeeNote = 'Refund'
    ): void {
        $token = $this->getToken();

        $this->call('POST', '/standard/v1/disbursements/', [
            'headers' => $this->headers($token),
            'json' => [
                'payee' => ['msisdn' => $this->normalizeMsisdnForAirtel($msisdn)],
                'reference' => $externalId,
                'pin' => $this->encryptPin(),
                'transaction' => [
                    'amount' => (float) $amount,
                    'id' => $referenceId,
                    'type' => 'B2C',
                ],
            ],
            'expectedStatus' => [200],
        ]);
    }

    public function getDisbursementStatus(string $referenceId): MobileMoneyStatus
    {
        $token = $this->getToken();
        $response = $this->call('GET', '/standard/v1/disbursements/' . $referenceId, [
            'headers' => $this->headers($token),
            'expectedStatus' => [200],
        ]);

        return $this->mapStatus($response);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function getToken(): string
    {
        $item = $this->cache->getItem('airtel_token');
        if ($item->isHit()) {
            return $item->get();
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/auth/oauth2/token', [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => '*/*'],
                'json' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ],
            ]);
            $data = $response->toArray();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('Airtel Money: échec récupération token', ['error' => $e->getMessage()]);
            throw new MobileMoneyException('Impossible d\'obtenir un token Airtel Money.', 0, $e);
        }

        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        if (!$token) {
            throw new MobileMoneyException('Réponse token Airtel Money invalide.');
        }

        $item->set($token);
        $item->expiresAfter(max(60, $expiresIn - 60));
        $this->cache->save($item);

        return $token;
    }

    private function headers(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => '*/*',
            'X-Country' => $this->country,
            'X-Currency' => $this->currency,
        ];
    }

    private function call(string $method, string $path, array $options): array
    {
        $expectedStatus = $options['expectedStatus'] ?? [200];
        unset($options['expectedStatus']);

        try {
            $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
            if (!in_array($status, $expectedStatus, true)) {
                $this->logger->error('Airtel Money: réponse HTTP inattendue', [
                    'method' => $method, 'path' => $path, 'status' => $status, 'body' => $body,
                ]);
                throw new MobileMoneyException(sprintf('Airtel Money a répondu %d sur %s %s.', $status, $method, $path));
            }
            return $body;
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('Airtel Money: échec appel réseau', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);
            throw new MobileMoneyException('Échec de communication avec Airtel Money sur ' . $path . '.', 0, $e);
        }
    }

    private function mapStatus(array $response): MobileMoneyStatus
    {
        // Airtel renvoie transaction.status en TS (success), TF (failed), TIP/TI (in progress/pending)
        $data = $response['data'] ?? $response;
        $airtelStatus = strtoupper((string) ($data['transaction']['status'] ?? $data['status'] ?? 'TIP'));
        $status = match ($airtelStatus) {
            'TS', 'SUCCESS' => MobileMoneyStatus::SUCCESS,
            'TF', 'FAILED' => MobileMoneyStatus::FAILED,
            default => MobileMoneyStatus::PENDING,
        };

        return new MobileMoneyStatus(
            status: $status,
            providerReference: $data['transaction']['airtel_money_id'] ?? null,
            reason: $data['transaction']['message'] ?? null,
            rawResponse: json_encode($response),
        );
    }

    /**
     * Le PIN marchand doit être chiffré en RSA/ECB/PKCS1Padding avec la clé
     * publique fournie par Airtel dans votre portail (section Disbursement
     * > Encryption Keys), puis encodé en base64. À compléter avec vos
     * vraies clés avant d'activer les remboursements/retraits Airtel.
     */
    private function encryptPin(): string
    {
        if (!$this->disbursementPin || !$this->disbursementPublicKeyPem) {
            throw new MobileMoneyException('Clé publique / PIN de disbursement Airtel non configurés (AIRTEL_DISBURSEMENT_PIN / AIRTEL_DISBURSEMENT_PUBLIC_KEY).');
        }

        $publicKey = openssl_pkey_get_public($this->disbursementPublicKeyPem);
        if ($publicKey === false) {
            throw new MobileMoneyException('Clé publique Airtel invalide.');
        }

        $encrypted = '';
        if (!openssl_public_encrypt($this->disbursementPin, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
            throw new MobileMoneyException('Échec du chiffrement du PIN Airtel.');
        }

        return base64_encode($encrypted);
    }

    /** Airtel attend en général le MSISDN local SANS l'indicatif pays (ex: 068001122, pas 242068001122). */
    private function normalizeMsisdnForAirtel(string $msisdn): string
    {
        $digits = preg_replace('/\D+/', '', $msisdn);
        if (str_starts_with($digits, '242') && strlen($digits) > 9) {
            $digits = substr($digits, 3);
        }
        return $digits;
    }
}
