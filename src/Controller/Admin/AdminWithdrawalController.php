<?php

namespace App\Controller\Admin;

use App\Entity\WithdrawalRequest;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Traitement des demandes de retrait par le back-office SuperAdmin.
 *
 * TODO sécurité : brancher un contrôle de rôle (#[IsGranted('ROLE_SUPER_ADMIN')]
 * ou équivalent) avant mise en production — ce fichier ne fait volontairement
 * aucune hypothèse sur votre système d'authentification admin actuel.
 */
class AdminWithdrawalController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
    ) {}

    /**
     * Approuve une demande de retrait : les fonds réservés (bloqués depuis la
     * création de la demande) sortent définitivement du portefeuille de l'agence.
     * Le virement / paiement Mobile Money reste effectué manuellement par
     * l'admin en dehors du système ; cet endpoint acte que c'est fait.
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $withdrawal = $this->em->getRepository(WithdrawalRequest::class)->find($id);
        if (!$withdrawal) {
            return new JsonResponse(['message' => 'Demande de retrait introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($withdrawal->getStatus() !== 'pending') {
            return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $this->walletService->completeWithdrawal($withdrawal);

        $withdrawal->setStatus('approved');
        $withdrawal->setProcessedAt(new \DateTime());
        if (!empty($data['note'])) {
            $withdrawal->setAdminNote($data['note']);
        }

        $this->em->persist($withdrawal);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'id' => $withdrawal->getId(),
            'status' => $withdrawal->getStatus(),
        ], Response::HTTP_OK);
    }

    /**
     * Rejette une demande de retrait : les fonds réservés reviennent dans le
     * solde disponible de l'agence, qui peut les redemander ou les utiliser
     * pour une autre demande.
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        $withdrawal = $this->em->getRepository(WithdrawalRequest::class)->find($id);
        if (!$withdrawal) {
            return new JsonResponse(['message' => 'Demande de retrait introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($withdrawal->getStatus() !== 'pending') {
            return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $this->walletService->releaseWithdrawal($withdrawal);

        $withdrawal->setStatus('rejected');
        $withdrawal->setProcessedAt(new \DateTime());
        $withdrawal->setAdminNote($data['note'] ?? 'Rejeté par l\'administrateur.');

        $this->em->persist($withdrawal);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'id' => $withdrawal->getId(),
            'status' => $withdrawal->getStatus(),
        ], Response::HTTP_OK);
    }
}
