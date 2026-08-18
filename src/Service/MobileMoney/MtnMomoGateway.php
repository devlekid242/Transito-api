<?php

namespace App\Service\MobileMoney;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Client MTN Mobile Money Open API — produits "Collection" et
 * "Disbursement". Documentation officielle : https://momodeveloper.mtn.com
 *
 * Fonctionnement en sandbox (résumé officiel) :
 *  1. Vous créez un "API user" une seule fois (POST /v1_0/apiuser) puis une
 *     "API key" pour cet utilisateur (POST /v1_0/apiuser/{id}/apikey).
 *     -> Ce sont MOMO_COLLECTION_API_USER / MOMO_COLLECTION_API_KEY et
 *        MOMO_DISBURSEMENT_API_USER / MOMO_DISBURSEMENT_API_KEY ci-dessous.
 *        Un script one-shot est fourni dans le guide pour les générer.
 *  2. Chaque requête métier a besoin d'un access_token (Basic Auth avec
 *     l'API user/key), valable ~1h, obtenu via POST /collection/token/ ou
 *     /disbursement/token/. On le met en cache pour ne pas le régénérer à
 *     chaque appel.
 *  3. requesttopay / transfer renvoient un simple 202 Accepted (pas de
 *     corps) : la seule façon fiable de connaître le résultat est
 *     d'interroger GET .../{referenceId} ensuite, car en sandbox les
 *     callbacks HTTP ne peuvent pas être livrés (pas de vrai téléphone qui
 *     valide le paiement) — voir PollMobileMoneyPaymentsCommand.
 */
final class MtnMomoGateway implements MobileMoneyGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,                 // ex: https://sandbox.momodeveloper.mtn.com
        private readonly string $targetEnvironment,        // "sandbox" ou nom de l'environnement de prod fourni par MTN
        private readonly string $collectionSubscriptionKey,
        private readonly string $collectionApiUser,
        private readonly string $collectionApiKey,
        private readonly string $disbursementSubscriptionKey,
        private readonly string $disbursementApiUser,
        private readonly string $disbursementApiKey,
        private readonly string $currency = 'XAF',
        private readonly ?string $callbackHost = null,
    ) {}

    public function getOperatorCode(): string
    {
        return 'MTN_MOMO';
    }

    // ------------------------------------------------------------------
    // Collections (encaissement client)
    // ------------------------------------------------------------------

    public function requestToPay(
        string $referenceId,
        string $amount,
        string $msisdn,
        string $externalId,
        string $payerMessage = 'Paiement billet',
        string $payeeNote = 'Reservation'
    ): void {
        $token = $this->getToken('collection');
        $normalizedMsisdn = $this->normalizeMsisdn($msisdn);

        $payload = [
            'amount' => $amount,
            'currency' => $this->currency,
            'externalId' => $externalId,
            'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $normalizedMsisdn],
            'payerMessage' => $payerMessage,
            'payeeNote' => $payeeNote,
        ];

        // Diagnostic temporaire : affiche EXACTEMENT ce qui part vers MTN.
        // On log $amount avec bin2hex pour repérer tout caractère invisible
        // (espace insécable, virgule au lieu du point, etc.) qu'un simple
        // affichage texte ne montrerait pas.
        $this->logger->info('MTN MoMo: requestToPay - payload complet', [
            'referenceId' => $referenceId,
            'payload' => $payload,
            'amount_type' => gettype($amount),
            'amount_hex' => bin2hex($amount),
        ]);

        $this->call('POST', '/collection/v1_0/requesttopay', [
            'headers' => $this->headers($token, $this->collectionSubscriptionKey, $referenceId),
            'json' => $payload,
            'expectedStatus' => [202],
        ]);
    }

    public function getCollectionStatus(string $referenceId): MobileMoneyStatus
    {
        $token = $this->getToken('collection');
        $response = $this->call('GET', '/collection/v1_0/requesttopay/' . $referenceId, [
            'headers' => $this->headers($token, $this->collectionSubscriptionKey, null),
            'expectedStatus' => [200],
        ]);

        return $this->mapStatus($response);
    }

    // ------------------------------------------------------------------
    // Disbursements (remboursement client / retrait partenaire)
    // ------------------------------------------------------------------

    public function transfer(
        string $referenceId,
        string $amount,
        string $msisdn,
        string $externalId,
        string $payerMessage = 'Remboursement',
        string $payeeNote = 'Refund'
    ): void {
        $token = $this->getToken('disbursement');

        $this->call('POST', '/disbursement/v1_0/transfer', [
            'headers' => $this->headers($token, $this->disbursementSubscriptionKey, $referenceId),
            'json' => [
                'amount' => $amount,
                'currency' => $this->currency,
                'externalId' => $externalId,
                'payee' => ['partyIdType' => 'MSISDN', 'partyId' => $this->normalizeMsisdn($msisdn)],
                'payerMessage' => $payerMessage,
                'payeeNote' => $payeeNote,
            ],
            'expectedStatus' => [202],
        ]);
    }

    public function getDisbursementStatus(string $referenceId): MobileMoneyStatus
    {
        $token = $this->getToken('disbursement');
        $response = $this->call('GET', '/disbursement/v1_0/transfer/' . $referenceId, [
            'headers' => $this->headers($token, $this->disbursementSubscriptionKey, null),
            'expectedStatus' => [200],
        ]);

        return $this->mapStatus($response);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @param 'collection'|'disbursement' $product */
    private function getToken(string $product): string
    {
        $cacheKey = 'momo_token_' . $product;
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        [$subscriptionKey, $apiUser, $apiKey] = $product === 'collection'
            ? [$this->collectionSubscriptionKey, $this->collectionApiUser, $this->collectionApiKey]
            : [$this->disbursementSubscriptionKey, $this->disbursementApiUser, $this->disbursementApiKey];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/' . $product . '/token/', [
                'auth_basic' => [$apiUser, $apiKey],
                'headers' => ['Ocp-Apim-Subscription-Key' => $subscriptionKey],
            ]);
            $data = $response->toArray();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('MTN MoMo: échec récupération token', ['product' => $product, 'error' => $e->getMessage()]);
            throw new MobileMoneyException('Impossible d\'obtenir un token MTN MoMo (' . $product . ').', 0, $e);
        }

        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        if (!$token) {
            throw new MobileMoneyException('Réponse token MTN MoMo invalide (' . $product . ').');
        }

        $item->set($token);
        // Marge de sécurité de 60s pour ne jamais utiliser un token expiré pile au moment de l'appel.
        $item->expiresAfter(max(60, $expiresIn - 60));
        $this->cache->save($item);

        return $token;
    }

    private function headers(string $token, string $subscriptionKey, ?string $referenceId): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Ocp-Apim-Subscription-Key' => $subscriptionKey,
            'X-Target-Environment' => $this->targetEnvironment,
            'Content-Type' => 'application/json',
        ];
        if ($referenceId !== null) {
            $headers['X-Reference-Id'] = $referenceId;
        }
        return $headers;
    }

    private function call(string $method, string $path, array $options): array
    {
        $expectedStatus = $options['expectedStatus'] ?? [200];
        unset($options['expectedStatus']);

        try {
            $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
            $status = $response->getStatusCode();
            if (!in_array($status, $expectedStatus, true)) {
                $body = $response->getContent(false);
                $this->logger->error('MTN MoMo: réponse HTTP inattendue', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'body' => $body,
                ]);
                throw new MobileMoneyException(sprintf(
                    'MTN MoMo a répondu %d sur %s %s. Corps: %s',
                    $status,
                    $method,
                    $path,
                    $body !== '' ? $body : '(vide)'
                ));
            }
            $content = $response->getContent(false);
            return $content === '' ? [] : ($response->toArray(false) ?: []);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('MTN MoMo: échec appel réseau', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);
            throw new MobileMoneyException('Échec de communication avec MTN MoMo sur ' . $path . '.', 0, $e);
        }
    }

    private function mapStatus(array $response): MobileMoneyStatus
    {
        $mtnStatus = strtoupper((string) ($response['status'] ?? 'PENDING'));
        $status = match ($mtnStatus) {
            'SUCCESSFUL' => MobileMoneyStatus::SUCCESS,
            'FAILED' => MobileMoneyStatus::FAILED,
            default => MobileMoneyStatus::PENDING, // PENDING côté MTN
        };

        $reason = $response['reason']['code'] ?? ($response['reason'] ?? null);

        return new MobileMoneyStatus(
            status: $status,
            providerReference: $response['financialTransactionId'] ?? null,
            reason: is_string($reason) ? $reason : null,
            rawResponse: json_encode($response),
        );
    }

    /**
     * MTN attend le MSISDN en format international SANS le "+", ex: 242068001122.
     *
     * ⚠️ Ancienne implémentation buguée : `ltrim($digits, '+242')` ne retire PAS
     * la sous-chaîne "+242", elle retire caractère par caractère tout '+', '2'
     * ou '4' en tête de chaîne — ce qui mutilait un numéro déjà international
     * (242068001122 devenait 068001122, indicatif perdu) et pouvait produire
     * une chaîne vide pour certains formats. On normalise désormais
     * explicitement par préfixe.
     */
    private function normalizeMsisdn(string $msisdn): string
    {
        $digits = preg_replace('/\D+/', '', $msisdn);

        if ($digits === '') {
            throw new MobileMoneyException(
                'Numéro de téléphone manquant ou invalide pour ce paiement MTN MoMo (paymentPhone vide après nettoyage).'
            );
        }

        if (str_starts_with($digits, '242')) {
            return $digits; // déjà au format international, ex: 242068001122
        }

        // Numéro local, avec ou sans le 0 de tronc initial (068001122 ou 68001122).
        $local = ltrim($digits, '0');

        return '242' . $local;
    }
}