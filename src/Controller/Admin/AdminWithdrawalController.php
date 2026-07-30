<?php

namespace App\Controller\Admin;

use App\Entity\Agency;
use App\Entity\RefundRequest;
use App\Entity\User;
use App\Entity\WithdrawalRequest;
use App\Repository\RefundRequestRepository;
use App\Service\WalletService;
use App\Repository\WithdrawalRequestRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Traitement des demandes de retrait par le back-office SuperAdmin.
 *
 * 👈 CORRIGÉ (audit sécurité) : le contrôle de rôle manquant est désormais
 * branché via #[IsGranted('ROLE_ADMIN')]. Auparavant, seule la connexion
 * (getUser() instanceof User) était vérifiée dans chaque action, ce qui
 * permettait à n'importe quel utilisateur authentifié d'approuver/rejeter
 * des retraits. Adapter le rôle exact à votre hiérarchie réelle si besoin
 * (ex : 'ROLE_SUPER_ADMIN').
 */
#[Route('/api/admin/withdrawals')]
#[IsGranted('ROLE_ADMIN')]
class AdminWithdrawalController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private WithdrawalRequestRepository $withdrawalRepository,
        private RefundRequestRepository $refundRequestRepository,
    ) {}

    /**
     * Liste toutes les demandes de retrait avec pagination et filtrage.
     * Utilisé par le tableau de bord d'administration.
     */
    #[Route('', name: 'api_admin_withdrawals_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);
        $perPage = (int) $request->query->get('perPage', 20);
        $status = $request->query->get('status');
        $agencyId = $request->query->get('agencyId');
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');
        $search = $request->query->get('search');

        $queryBuilder = $this->withdrawalRepository->createQueryBuilder('wr')
            ->addSelect('a', 'u') // ✅ Optimisation de jointure Doctrine
            ->join('wr.agency', 'a')
            ->leftJoin('wr.requestedBy', 'u')
            ->orderBy('wr.createdAt', 'DESC');

        // Apply filters
        if ($status) {
            $queryBuilder->andWhere('wr.status = :status')->setParameter('status', $status);
        }

        if ($agencyId) {
            $queryBuilder->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if ($dateFrom) {
            $queryBuilder->andWhere('wr.createdAt >= :dateFrom')->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo) {
            $queryBuilder->andWhere('wr.createdAt <= :dateTo')->setParameter('dateTo', new \DateTime($dateTo));
        }

        if ($search) {
            $queryBuilder
                ->andWhere('a.name LIKE :search OR wr.id LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Pagination
        $total = (int) $this->withdrawalRepository->countForList($queryBuilder);
        $offset = ($page - 1) * $perPage;
        $totalPages = ceil($total / $perPage);

        $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        /** @var WithdrawalRequest[] $withdrawals */
        $withdrawals = $queryBuilder->getQuery()->getResult();

        // Normalize data for API response
        $data = [];
        foreach ($withdrawals as $withdrawal) { // ✅ $withdrawal est directement l'entité
            $agency = $withdrawal->getAgency();
            $user = $withdrawal->getRequestedBy();
            

            $data[] = [
                'id' => $withdrawal->getId(),
                'agencyId' => $agency ? $agency->getId() : null,
                'agencyName' => $agency ? $agency->getName() : 'Inconnue',
                'requestedById' => $user ? $user->getId() : null,
                'requestedByName' => $user ? $user->getFullName() : null,
                'amount' => (float) $withdrawal->getAmount(),
                'method' => $withdrawal->getMethod(),
                'status' => $withdrawal->getStatus(),
                'notes' => $withdrawal->getNotes(),
                'adminNote' => $withdrawal->getAdminNote(),
                'processedAt' => $withdrawal->getProcessedAt()?->format(\DateTimeInterface::ATOM),
                'createdAt' => $withdrawal->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
            ],
        ]);
    }

    /**
     * Récupère une seule demande de retrait par ID.
     */
    #[Route('/{id}', name: 'api_admin_withdrawals_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $withdrawal = $this->em->getRepository(WithdrawalRequest::class)->find($id);

        if (!$withdrawal) {
            return new JsonResponse(['message' => 'Demande de retrait introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $agency = $withdrawal->getAgency();
        $user = $withdrawal->getRequestedBy();

        $data = [
            'id' => $withdrawal->getId(),
            'agencyId' => $agency ? $agency->getId() : null,
            'agencyName' => $agency ? $agency->getName() : 'Inconnue',
            'requestedById' => $user ? $user->getId() : null,
            'requestedByName' => $user ? $user->getFullName() : null,
            'amount' => (float) $withdrawal->getAmount(),
            'method' => $withdrawal->getMethod(),
            'status' => $withdrawal->getStatus(),
            'notes' => $withdrawal->getNotes(),
            'adminNote' => $withdrawal->getAdminNote(),
            'processedAt' => $withdrawal->getProcessedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $withdrawal->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Approuve une demande de retrait : les fonds réservés (bloqués depuis la
     * création de la demande) sortent définitivement du portefeuille de l'agence.
     * 
     * AVANT d'approuver, vérifie que l'agence a suffisamment de fonds pour couvrir
     * les remboursements clients en attente (sauvegarde de remboursement).
     * 
     * Le virement / paiement Mobile Money reste effectué manuellement par
     * l'admin en dehors du système ; cet endpoint acte que c'est fait.
     * 
     * @param int $id ID de la demande de retrait
     * @param Request $request Contient {note?: string, forcePay?: boolean}
     */
    #[Route('/{id}/approve', name: 'api_admin_withdrawals_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $withdrawal = $this->em->getRepository(WithdrawalRequest::class)->find($id);
        if (!$withdrawal) {
            return new JsonResponse(['message' => 'Demande de retrait introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($withdrawal->getStatus() !== 'pending') {
            return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
        }

        // 👈 NOUVEAU : verrou pessimiste + re-vérification du statut sous
        // transaction. AVANT, deux appels concurrents à approve() (double
        // clic, retry réseau) pouvaient tous les deux passer le contrôle de
        // statut ci-dessus avant que l'un des deux ne flush, et déclencher
        // WalletService::completeWithdrawal() deux fois pour la même demande
        // (double décrément de reservedBalance / double incrément de
        // totalWithdrawn). Le verrou PESSIMISTIC_WRITE sérialise les deux
        // appels ; le second, une fois le verrou obtenu, retrouve un statut
        // déjà "approved" et s'arrête proprement.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $lockedWithdrawal = $this->em->getRepository(WithdrawalRequest::class)
                ->find($id, LockMode::PESSIMISTIC_WRITE);

            if (!$lockedWithdrawal || $lockedWithdrawal->getStatus() !== 'pending') {
                $connection->rollBack();
                return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
            }
            $withdrawal = $lockedWithdrawal;

            $response = $this->doApprove($withdrawal, $request);

            $this->em->flush();
            $connection->commit();

            return $response;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Logique métier de l'approbation, exécutée sous verrou pessimiste par
     * approve() ci-dessus. Extraite dans sa propre méthode pour ne pas
     * flusher au milieu de la transaction verrouillée.
     */
    private function doApprove(WithdrawalRequest $withdrawal, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $forcePay = $data['forcePay'] ?? false;
        $adminNote = $data['note'] ?? null;

        // Get the current admin user for traceability
        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise pour traiter cette demande.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $withdrawal->getAgency();
        if (!$agency) {
            return new JsonResponse(['message' => 'Agence introuvable pour cette demande.'], Response::HTTP_BAD_REQUEST);
        }

        // Perform solvency check unless forcePay is enabled
        if (!$forcePay) {
            // Ensure the wallet service has the refund request repository
            if ($this->walletService instanceof \App\Service\WalletService && method_exists($this->walletService, 'setRefundRequestRepository')) {
                $this->walletService->setRefundRequestRepository($this->refundRequestRepository);
            }
            
            try {
                $solvencyResult = $this->walletService->checkWithdrawalSolvency($withdrawal);
                
                if (!$solvencyResult['solvent']) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $solvencyResult['message'],
                        'remainingBalance' => $solvencyResult['remainingBalance'],
                        'totalPendingRefunds' => $solvencyResult['totalPendingRefunds'],
                        'withdrawalAmount' => (float) $withdrawal->getAmount(),
                        'requiresForcePay' => true,
                    ], Response::HTTP_CONFLICT);
                }
            } catch (\Exception $e) {
                // 👈 CORRIGÉ (audit sécurité financière) : AVANT, une exception
                // ici (bug, DB indisponible, dépendance manquante...) laissait
                // le retrait passer SANS AUCUN contrôle de solvabilité — un
                // contrôle de sécurité financière qui "fail open" sur erreur
                // est une faille en soi. On bloque désormais par défaut et on
                // laisse l'admin décider consciemment via forcePay.
                error_log('Solvency check failed: ' . $e->getMessage());
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Impossible de vérifier la solvabilité de l\'agence pour le moment. Réessayez, ou utilisez le paiement forcé en connaissance de cause.',
                    'requiresForcePay' => true,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // If forcePay is enabled, mark it on the withdrawal
        if ($forcePay) {
            $withdrawal->setForcePaid(true);
        }

        // Process the withdrawal
        $this->walletService->completeWithdrawal($withdrawal);

        // Update withdrawal status and traceability
        $withdrawal->setStatus('approved');
        $withdrawal->setProcessedAt(new \DateTime());
        $withdrawal->setProcessedByAdmin($currentAdmin);
        
        if (!empty($adminNote)) {
            $withdrawal->setAdminNote($adminNote);
        }

        $this->em->persist($withdrawal);

        return new JsonResponse([
            'success' => true,
            'id' => $withdrawal->getId(),
            'status' => $withdrawal->getStatus(),
            'forcePaid' => $withdrawal->isForcePaid(),
            'processedByAdminId' => $currentAdmin->getId(),
            'processedByAdminName' => $currentAdmin->getFullName(),
            'processedAt' => $withdrawal->getProcessedAt()?->format(\DateTimeInterface::ATOM),
            'message' => $forcePay ? 'Demande de retrait approuvée avec paiement forcé.' : 'Demande de retrait approuvée.',
        ], Response::HTTP_OK);
    }

    /**
     * Rejette une demande de retrait : les fonds réservés reviennent dans le
     * solde disponible de l'agence, qui peut les redemander ou les utiliser
     * pour une autre demande.
     * 
     * @param int $id ID de la demande de retrait
     * @param Request $request Contient {note?: string}
     */
    #[Route('/{id}/reject', name: 'api_admin_withdrawals_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        $withdrawal = $this->em->getRepository(WithdrawalRequest::class)->find($id);
        if (!$withdrawal) {
            return new JsonResponse(['message' => 'Demande de retrait introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($withdrawal->getStatus() !== 'pending') {
            return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
        }

        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise pour traiter cette demande.'], Response::HTTP_UNAUTHORIZED);
        }

        // 👈 NOUVEAU : même correctif de course critique qu'approve() — verrou
        // pessimiste + re-vérification du statut sous transaction, pour
        // empêcher qu'un reject() concurrent à un autre reject()/approve()
        // ne libère les fonds réservés deux fois.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $lockedWithdrawal = $this->em->getRepository(WithdrawalRequest::class)
                ->find($id, LockMode::PESSIMISTIC_WRITE);

            if (!$lockedWithdrawal || $lockedWithdrawal->getStatus() !== 'pending') {
                $connection->rollBack();
                return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
            }
            $withdrawal = $lockedWithdrawal;

            $data = json_decode($request->getContent(), true) ?? [];

            $this->walletService->releaseWithdrawal($withdrawal);

            $withdrawal->setStatus('rejected');
            $withdrawal->setProcessedAt(new \DateTime());
            $withdrawal->setProcessedByAdmin($currentAdmin);
            $withdrawal->setAdminNote($data['note'] ?? 'Rejeté par l\'administrateur.');

            $this->em->persist($withdrawal);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return new JsonResponse([
            'success' => true,
            'id' => $withdrawal->getId(),
            'status' => $withdrawal->getStatus(),
            'processedByAdminId' => $currentAdmin->getId(),
            'processedByAdminName' => $currentAdmin->getFullName(),
            'processedAt' => $withdrawal->getProcessedAt()?->format(\DateTimeInterface::ATOM),
            'adminNote' => $withdrawal->getAdminNote(),
        ], Response::HTTP_OK);
    }

    /**
     * Get solvency check for a withdrawal (public method for API)
     */
    #[Route('/{id}/check-solvency', name: 'api_admin_withdrawals_check_solvency', methods: ['GET'])]
    public function checkSolvency(int $id): JsonResponse
    {
        $withdrawal = $this->em->getRepository(WithdrawalRequest::class)->find($id);
        if (!$withdrawal) {
            return new JsonResponse(['message' => 'Demande de retrait introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $agency = $withdrawal->getAgency();
        if (!$agency) {
            return new JsonResponse(['message' => 'Agence introuvable pour cette demande.'], Response::HTTP_BAD_REQUEST);
        }

        // Ensure the wallet service has the refund request repository
        if ($this->walletService instanceof \App\Service\WalletService && method_exists($this->walletService, 'setRefundRequestRepository')) {
            $this->walletService->setRefundRequestRepository($this->refundRequestRepository);
        }

        try {
            $solvencyResult = $this->walletService->checkWithdrawalSolvency($withdrawal);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la vérification de solvabilité: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        
        return new JsonResponse([
            'success' => true,
            'withdrawalId' => $withdrawal->getId(),
            'agencyId' => $agency->getId(),
            'agencyName' => $agency->getName(),
            'withdrawalAmount' => (float) $withdrawal->getAmount(),
            'solvent' => $solvencyResult['solvent'],
            'message' => $solvencyResult['message'],
            'remainingBalance' => $solvencyResult['remainingBalance'],
            'totalPendingRefunds' => $solvencyResult['totalPendingRefunds'],
            'requiresForcePay' => !$solvencyResult['solvent'],
        ], Response::HTTP_OK);
    }
}