<?php

namespace App\Controller\Admin;

use App\Repository\SystemSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/settings')]
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
}
