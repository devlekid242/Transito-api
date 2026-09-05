<?php

namespace App\Controller\Admin;

use App\Security\AdminRoleVoter;

use App\Repository\SystemSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/settings')]
#[IsGranted(AdminRoleVoter::SUPER)]
#[IsGranted('ROLE_ADMIN')]
class AdminSystemSettingsController extends AbstractController
{
    public function __construct(
        private readonly SystemSettingRepository $systemSettingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'api_admin_settings_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $setting = $this->systemSettingRepository->findOrCreateSystemSetting();
        $data = $this->normalizeSettings($setting->getData());

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    #[Route('', name: 'api_admin_settings_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $setting = $this->systemSettingRepository->findOrCreateSystemSetting();
        $data = $this->normalizeSettings($setting->getData());

        $allowedKeys = [
            'platformName',
            'supportEmail',
            'supportPhone',
            'currency',
            'platformFee',
            'paymentMethods',
            'momoOperators',
            'security',
            'maintenanceMode',
            'maintenanceMessage',
            'auditRetentionDays',
        ];

        foreach ($payload as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }

            if ($key === 'paymentMethods' && is_array($value)) {
                $data['paymentMethods'] = $this->normalizePaymentMethods($value, $data['paymentMethods']);
                continue;
            }

            if ($key === 'momoOperators' && is_array($value)) {
                $error = null;
                $normalized = $this->normalizeMomoOperators($value, $data['momoOperators'], $error);
                if ($normalized === null) {
                    return $this->json([
                        'success' => false,
                        'message' => $error ?? 'Configuration opérateur momo invalide : chaque opérateur doit avoir un identifiant, un nom et des taux (%) entre 0 et 100.',
                    ], Response::HTTP_BAD_REQUEST);
                }
                $data['momoOperators'] = $normalized;
                continue;
            }

            if ($key === 'security' && is_array($value)) {
                $data['security'] = array_merge($data['security'], $value);
                continue;
            }

            $data[$key] = $value;
        }

        if (!filter_var($data['supportEmail'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'message' => 'L\'adresse email de support est invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $setting->setData($data);
        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => $data,
            'message' => 'Paramètres système mis à jour avec succès.',
        ]);
    }

    private function normalizeSettings(array $data): array
    {
        return array_replace($this->getDefaultSettings(), $data);
    }

    private function getDefaultSettings(): array
    {
        return [
            'platformName' => 'Tansico',
            'supportEmail' => 'support@tansico.com',
            'supportPhone' => '+221 33 800 00 00',
            'currency' => 'FCFA',
            'platformFee' => 350,
            'paymentMethods' => [
                ['name' => 'Wave', 'enabled' => true, 'icon' => 'fa-wave-square'],
                ['name' => 'Orange Money', 'enabled' => true, 'icon' => 'fa-mobile-screen-button'],
                ['name' => 'Carte bancaire', 'enabled' => true, 'icon' => 'fa-credit-card'],
            ],
            // Opérateurs mobile money et leur commission (%), séparée entre
            // encaissement (paiement client) et décaissement (remboursement /
            // retrait partenaire), car ces taux ne sont pas forcément
            // identiques et évoluent selon la politique de chaque opérateur.
            // collectionFeeRate : répercuté sur le client, ajouté au prix total.
            // disbursementFeeRate : absorbé par la plateforme, jamais déduit
            // du montant reçu par le bénéficiaire.
            'momoOperators' => [
                ['id' => 'MTN_MOMO', 'name' => 'MTN Mobile Money', 'collectionFeeRate' => 3.0, 'disbursementFeeRate' => 3.0, 'enabled' => true],
                ['id' => 'AIRTEL_MOMO', 'name' => 'Airtel Money', 'collectionFeeRate' => 3.0, 'disbursementFeeRate' => 3.0, 'enabled' => true],
            ],
            'security' => [
                'force2FA' => true,
                'autoLogoutMinutes' => 30,
                'ipWhitelist' => false,
                'passwordPolicy' => [
                    'minLength' => 8,
                    'requireUppercase' => true,
                    'requireSpecialChar' => true,
                    'expirationDays' => 90,
                    'historyCount' => 5,
                ],
            ],
            'maintenanceMode' => false,
            'maintenanceMessage' => '',
            'auditRetentionDays' => 90,
        ];
    }

    private function normalizePaymentMethods(array $payload, array $currentMethods): array
    {
        $methods = [];
        $existing = [];

        foreach ($currentMethods as $item) {
            if (isset($item['name'])) {
                $existing[$item['name']] = $item;
            }
        }

        foreach ($payload as $item) {
            if (!is_array($item) || !isset($item['name'])) {
                continue;
            }

            $name = (string) $item['name'];
            $enabled = isset($item['enabled']) ? (bool) $item['enabled'] : ($existing[$name]['enabled'] ?? false);
            $methods[] = [
                'name' => $name,
                'enabled' => $enabled,
                'icon' => $existing[$name]['icon'] ?? 'fa-credit-card',
            ];
        }

        if (count($methods) === 0) {
            return $currentMethods;
        }

        return $methods;
    }

    /**
     * Normalise la config des opérateurs momo envoyée par l'admin.
     * Permet d'AJOUTER un nouvel opérateur (id inconnu) ou de mettre à jour
     * un opérateur existant (taux, activation). Retourne null si le payload
     * est structurellement invalide, pour rejeter la requête plutôt que de
     * silencieusement corrompre la config financière.
     *
     * @param string|null $error Rempli avec un message précis si le retour est null.
     * @return array<int, array{id:string,name:string,collectionFeeRate:float,disbursementFeeRate:float,enabled:bool}>|null
     */
    private function normalizeMomoOperators(array $payload, array $currentOperators, ?string &$error = null): ?array
    {
        $existing = [];
        foreach ($currentOperators as $item) {
            if (isset($item['id'])) {
                $existing[$item['id']] = $item;
            }
        }

        $operators = [];
        foreach ($payload as $index => $item) {
            if (!is_array($item) || !isset($item['id'], $item['name'])) {
                $error = sprintf("Opérateur momo #%d : identifiant et nom sont requis.", $index + 1);
                return null;
            }

            $id = trim((string) $item['id']);
            $name = trim((string) $item['name']);
            if ($id === '' || $name === '') {
                $error = sprintf("Opérateur momo #%d : identifiant et nom ne peuvent pas être vides.", $index + 1);
                return null;
            }

            $prev = $existing[$id] ?? null;

            $collectionRate = array_key_exists('collectionFeeRate', $item)
                ? $item['collectionFeeRate']
                : ($prev['collectionFeeRate'] ?? 3.0);
            $disbursementRate = array_key_exists('disbursementFeeRate', $item)
                ? $item['disbursementFeeRate']
                : ($prev['disbursementFeeRate'] ?? 3.0);

            if (!is_numeric($collectionRate) || !is_numeric($disbursementRate)) {
                $error = sprintf("Opérateur \"%s\" : les taux doivent être numériques.", $name);
                return null;
            }
            $collectionRate = (float) $collectionRate;
            $disbursementRate = (float) $disbursementRate;
            if ($collectionRate < 0 || $collectionRate > 100 || $disbursementRate < 0 || $disbursementRate > 100) {
                $error = sprintf("Opérateur \"%s\" : les taux doivent être compris entre 0 et 100.", $name);
                return null;
            }

            $operators[] = [
                'id' => $id,
                'name' => $name,
                'collectionFeeRate' => round($collectionRate, 4),
                'disbursementFeeRate' => round($disbursementRate, 4),
                'enabled' => isset($item['enabled']) ? (bool) $item['enabled'] : ($prev['enabled'] ?? true),
            ];
        }

        if (count($operators) === 0) {
            return $currentOperators;
        }

        return $operators;
    }
}