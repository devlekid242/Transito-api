<?php
/**
 * Génère un API user + API key sandbox MTN MoMo pour UN produit
 * (Collection ou Disbursement). À exécuter une seule fois par produit.
 *
 * Usage :
 *   php scripts/momo-provision.php collection <VOTRE_SUBSCRIPTION_KEY_COLLECTIONS>
 *   php scripts/momo-provision.php disbursement <VOTRE_SUBSCRIPTION_KEY_DISBURSEMENTS>
 *
 * La subscription key s'obtient sur momodeveloper.mtn.com après vous être
 * abonné (bouton "Subscribe") au produit correspondant sur la page Products.
 *
 * Copiez le X-Reference-Id généré et l'apiKey renvoyée dans votre .env.local :
 *   MOMO_COLLECTION_API_USER=<X-Reference-Id>
 *   MOMO_COLLECTION_API_KEY=<apiKey>
 * (ou l'équivalent MOMO_DISBURSEMENT_* pour l'autre produit)
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php momo-provision.php <collection|disbursement> <subscription_key>\n");
    exit(1);
}

$baseUrl = 'https://sandbox.momodeveloper.mtn.com';
$subscriptionKey = $argv[2];
$referenceId = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

function req(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $response];
}

echo "1) Création de l'API user (X-Reference-Id = $referenceId)...\n";
[$status, $body] = req('POST', "$baseUrl/v1_0/apiuser", [
    'X-Reference-Id: ' . $referenceId,
    'Ocp-Apim-Subscription-Key: ' . $subscriptionKey,
    'Content-Type: application/json',
], json_encode(['providerCallbackHost' => 'webhook.site']));

if ($status !== 201) {
    fwrite(STDERR, "Échec création API user (HTTP $status): $body\n");
    exit(1);
}
echo "   OK (201 Created)\n";

echo "2) Génération de l'API key...\n";
[$status, $body] = req('POST', "$baseUrl/v1_0/apiuser/$referenceId/apikey", [
    'Ocp-Apim-Subscription-Key: ' . $subscriptionKey,
]);

if ($status !== 201) {
    fwrite(STDERR, "Échec création API key (HTTP $status): $body\n");
    exit(1);
}

$data = json_decode($body, true);
$apiKey = $data['apiKey'] ?? null;

echo "   OK — NOTEZ CES VALEURS MAINTENANT (l'apiKey ne sera plus jamais réaffichée) :\n\n";
$product = strtoupper($argv[1]);
echo "MOMO_{$product}_SUBSCRIPTION_KEY={$subscriptionKey}\n";
echo "MOMO_{$product}_API_USER={$referenceId}\n";
echo "MOMO_{$product}_API_KEY={$apiKey}\n";
