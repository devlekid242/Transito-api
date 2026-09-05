<?php

namespace App\Controller;

use App\Repository\SystemSettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Expose en LECTURE SEULE, sans authentification, le strict nécessaire pour
 * qu'un client (app mobile) calcule le prix exact d'une réservation :
 * frais de service plateforme + liste des opérateurs momo actifs avec leur
 * taux d'ENCAISSEMENT.
 *
 * Ne JAMAIS exposer ici : disbursementFeeRate (taux de décaissement, usage
 * interne uniquement), ni aucune autre clé de SystemSetting (security,
 * maintenanceMessage, etc.) — cette route est publique.
 */
#[Route('/api/public/payment-config')]
class PublicPaymentConfigController extends AbstractController
{
    public function __construct(
        private readonly SystemSettingRepository $systemSettingRepository,
    ) {
    }

    #[Route('', name: 'api_public_payment_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $data = $this->systemSettingRepository->findOrCreateSystemSetting()->getData();

        $platformFee = isset($data['platformFee']) && is_numeric($data['platformFee'])
            ? (float) $data['platformFee']
            : 500.0;

        $operators = is_array($data['momoOperators'] ?? null) ? $data['momoOperators'] : [];
        $publicOperators = [];
        foreach ($operators as $operator) {
            if (!is_array($operator) || empty($operator['enabled'])) {
                continue;
            }
            $publicOperators[] = [
                'id' => (string) ($operator['id'] ?? ''),
                'name' => (string) ($operator['name'] ?? ''),
                // Seul le taux d'encaissement est pertinent côté client : c'est
                // ce qui s'ajoute au prix qu'il va payer.
                'collectionFeeRate' => (float) ($operator['collectionFeeRate'] ?? 0),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => [
                'platformFee' => $platformFee,
                'momoOperators' => $publicOperators,
            ],
        ], JsonResponse::HTTP_OK, [
            // Réponse peu volatile : évite de re-solliciter la base à chaque
            // ouverture du formulaire de réservation.
            'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
