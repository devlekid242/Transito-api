<?php

namespace App\Controller\Admin;

use App\Entity\Agency;
use App\Entity\PaymentLog;
use App\Entity\RefundRequest;
use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Repository\AgencyRepository;
use App\Repository\RefundRequestRepository;
use App\Repository\ReservationRepository;
use App\Repository\WalletRepository;
use App\Repository\WalletTransactionRepository;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin Refund Management Controller
 * Handles listing refund requests, processing standard refunds, and force refunds.
 */
#[Route('/api/admin/refunds')]
class AdminRefundController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private RefundRequestRepository $refundRequestRepository,
        private ReservationRepository $reservationRepository,
        private AgencyRepository $agencyRepository,
        private WalletRepository $walletRepository,
        private WalletTransactionRepository $walletTransactionRepository,
    ) {
        // Inject repositories into wallet service for advanced features
        $this->walletService->setRefundRequestRepository($this->refundRequestRepository);
    }

    /**
     * List all refund requests with filtering and pagination
     */
    #[Route('', name: 'api_admin_refunds_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);
        $perPage = (int) $request->query->get('perPage', 20);
        $status = $request->query->get('status');
        $agencyId = $request->query->get('agencyId');
        $search = $request->query->get('search');
        $forceOnly = $request->query->getBoolean('forceOnly', false);

        $queryBuilder = $this->refundRequestRepository->createQueryBuilder('rr')
            ->addSelect('a', 'r', 'u')
            ->join('rr.agency', 'a')
            ->join('rr.reservation', 'r')
            ->leftJoin('rr.requestedBy', 'u')
            ->orderBy('rr.createdAt', 'DESC');

        // Apply filters
        if ($status) {
            $queryBuilder->andWhere('rr.status = :status')->setParameter('status', $status);
        }

        if ($agencyId) {
            $queryBuilder->andWhere('a.id = :agencyId')->setParameter('agencyId', $agencyId);
        }

        if ($search) {
            $queryBuilder
                ->andWhere('r.transactionReference LIKE :search OR u.fullName LIKE :search OR a.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($forceOnly) {
            $queryBuilder->andWhere('rr.forceProcessed = :forceProcessed')
                ->setParameter('forceProcessed', true);
        }

        // Pagination
        $total = count($queryBuilder->getQuery()->getResult());
        $offset = ($page - 1) * $perPage;
        $totalPages = ceil($total / $perPage);

        $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        /** @var RefundRequest[] $refundRequests */
        $refundRequests = $queryBuilder->getQuery()->getResult();

        // Format response data
        $data = [];
        foreach ($refundRequests as $refundRequest) {
            $agency = $refundRequest->getAgency();
            $reservation = $refundRequest->getReservation();
            $user = $refundRequest->getRequestedBy();
            $wallet = $this->walletRepository->findOneBy(['agency' => $agency]);

            // Check if agent has negative balance
            $hasNegativeBalance = $wallet && (float) $wallet->getAvailableBalance() < 0;

            // Check if standard refund is possible (sufficient funds)
            $canStandardRefund = $this->canProcessStandardRefund($refundRequest);

            $data[] = [
                'id' => $refundRequest->getId(),
                'agencyId' => $agency ? $agency->getId() : null,
                'agencyName' => $agency ? $agency->getName() : 'Inconnue',
                'clientId' => $user ? $user->getId() : null,
                'clientName' => $user ? $user->getFullName() : 'Inconnu',
                'clientPhone' => $user ? $user->getPhoneNumber() : null,
                'reservationId' => $reservation ? $reservation->getId() : null,
                'bookingReference' => $reservation ? $reservation->getTransactionReference() : null,
                'amount' => (float) $refundRequest->getRequestedAmount(),
                'netAmount' => $this->calculateNetRefundAmount($refundRequest),
                'reason' => $refundRequest->getReason(),
                'status' => $refundRequest->getStatus(),
                'forceProcessed' => $refundRequest->isPending() ? false : ($refundRequest->getProcessedByAdmin() ? true : false),
                'processedByAdminId' => $refundRequest->getProcessedByAdmin()?->getId(),
                'processedByAdminName' => $refundRequest->getProcessedByAdmin()?->getFullName(),
                'processedAt' => $refundRequest->getProcessedAt()?->format(\DateTimeInterface::ATOM),
                'createdAt' => $refundRequest->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'hasNegativeBalance' => $hasNegativeBalance,
                'agentAvailableBalance' => $wallet ? (float) $wallet->getAvailableBalance() : 0,
                'agentReservedBalance' => $wallet ? (float) $wallet->getReservedBalance() : 0,
                'canStandardRefund' => $canStandardRefund,
            ];
        }

        // Calculate KPIs
        $kpis = [
            'totalPending' => $this->refundRequestRepository->count(['status' => RefundRequest::STATUS_PENDING]),
            'totalApproved' => $this->refundRequestRepository->count(['status' => RefundRequest::STATUS_APPROVED]),
            'totalRejected' => $this->refundRequestRepository->count(['status' => RefundRequest::STATUS_REJECTED]),
            'totalCompleted' => $this->refundRequestRepository->count(['status' => RefundRequest::STATUS_COMPLETED]),
            'totalAmountPending' => $this->refundRequestRepository->getTotalPendingRefundsAmount(),
        ];

        return $this->json([
            'success' => true,
            'data' => $data,
            'kpis' => $kpis,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
            ],
        ]);
    }

    /**
     * Get a single refund request detail
     */
    #[Route('/{id}', name: 'api_admin_refunds_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $refundRequest = $this->em->getRepository(RefundRequest::class)->find($id);

        if (!$refundRequest) {
            return new JsonResponse(['message' => 'Demande de remboursement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $agency = $refundRequest->getAgency();
        $reservation = $refundRequest->getReservation();
        $user = $refundRequest->getRequestedBy();
        $wallet = $this->walletRepository->findOneBy(['agency' => $agency]);

        $hasNegativeBalance = $wallet && (float) $wallet->getAvailableBalance() < 0;
        $canStandardRefund = $this->canProcessStandardRefund($refundRequest);

        $data = [
            'id' => $refundRequest->getId(),
            'agency' => [
                'id' => $agency ? $agency->getId() : null,
                'name' => $agency ? $agency->getName() : 'Inconnue',
                'city' => $agency ? $agency->getCity() : null,
                'phone' => $agency ? $agency->getPhone() : null,
                'hasNegativeBalance' => $hasNegativeBalance,
                'availableBalance' => $wallet ? (float) $wallet->getAvailableBalance() : 0,
                'reservedBalance' => $wallet ? (float) $wallet->getReservedBalance() : 0,
            ],
            'client' => [
                'id' => $user ? $user->getId() : null,
                'name' => $user ? $user->getFullName() : 'Inconnu',
                'phone' => $user ? $user->getPhoneNumber() : null,
                'email' => $user ? $user->getEmail() : null,
            ],
            'reservation' => [
                'id' => $reservation ? $reservation->getId() : null,
                'bookingReference' => $reservation ? $reservation->getTransactionReference() : null,
                'totalAmount' => $reservation ? (float) $reservation->getTotalAmount() : 0,
                'paymentStatus' => $reservation ? $reservation->getPaymentStatus() : null,
                'createdAt' => $reservation ? $reservation->getCreatedAt()?->format(\DateTimeInterface::ATOM) : null,
            ],
            'refund' => [
                'requestedAmount' => (float) $refundRequest->getRequestedAmount(),
                'netAmount' => $this->calculateNetRefundAmount($refundRequest),
                'refundedAmount' => (float) $refundRequest->getRefundedAmount(),
                'reason' => $refundRequest->getReason(),
                'status' => $refundRequest->getStatus(),
                'adminNote' => $refundRequest->getAdminNote(),
            ],
            'processing' => [
                'canStandardRefund' => $canStandardRefund,
                'requiresForce' => !$canStandardRefund && $refundRequest->getStatus() === RefundRequest::STATUS_PENDING,
                'processedByAdminId' => $refundRequest->getProcessedByAdmin()?->getId(),
                'processedByAdminName' => $refundRequest->getProcessedByAdmin()?->getFullName(),
                'processedAt' => $refundRequest->getProcessedAt()?->format(\DateTimeInterface::ATOM),
                'createdAt' => $refundRequest->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ],
        ];

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Process a standard refund (with balance check)
     */
    #[Route('/{id}/process-standard', name: 'api_admin_refunds_process_standard', methods: ['POST'])]
    public function processStandard(int $id, Request $request): JsonResponse
    {
        $refundRequest = $this->em->getRepository(RefundRequest::class)->find($id);

        if (!$refundRequest) {
            return new JsonResponse(['message' => 'Demande de remboursement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($refundRequest->getStatus() !== RefundRequest::STATUS_PENDING) {
            return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
        }

        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $adminNote = $data['adminNote'] ?? null;

        // Check if standard refund is possible
        if (!$this->canProcessStandardRefund($refundRequest)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Fonds insuffisants pour traiter ce remboursement standard. Utilisez le remboursement forcé si nécessaire.',
                'requiresForce' => true,
                'agentAvailableBalance' => $this->getAgentAvailableBalance($refundRequest),
                'refundAmount' => (float) $refundRequest->getRequestedAmount(),
            ], Response::HTTP_CONFLICT);
        }

        try {
            $result = $this->processRefund($refundRequest, $currentAdmin, $adminNote, false);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Remboursement standard traité avec succès.',
                'refundId' => $refundRequest->getId(),
                'newAgentBalance' => $result['newBalance'],
                'transactionId' => $result['transactionId'],
                'status' => $refundRequest->getStatus(),
                'processedAt' => $refundRequest->getProcessedAt()?->format(\DateTimeInterface::ATOM),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Process a forced refund (override balance check)
     */
    #[Route('/{id}/process-forced', name: 'api_admin_refunds_process_forced', methods: ['POST'])]
    public function processForced(int $id, Request $request): JsonResponse
    {
        $refundRequest = $this->em->getRepository(RefundRequest::class)->find($id);

        if (!$refundRequest) {
            return new JsonResponse(['message' => 'Demande de remboursement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($refundRequest->getStatus() !== RefundRequest::STATUS_PENDING) {
            return new JsonResponse(['message' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
        }

        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $adminNote = $data['adminNote'] ?? 'Remboursement forcé par admin';

        try {
            $result = $this->processRefund($refundRequest, $currentAdmin, $adminNote, true);
            $this->em->flush();

            $wallet = $this->walletRepository->findOneBy(['agency' => $refundRequest->getAgency()]);
            $hasNegativeBalance = $wallet && (float) $wallet->getAvailableBalance() < 0;

            return new JsonResponse([
                'success' => true,
                'message' => 'Remboursement forcé traité avec succès.',
                'refundId' => $refundRequest->getId(),
                'newAgentBalance' => $result['newBalance'],
                'transactionId' => $result['transactionId'],
                'status' => $refundRequest->getStatus(),
                'hasNegativeBalance' => $hasNegativeBalance,
                'processedAt' => $refundRequest->getProcessedAt()?->format(\DateTimeInterface::ATOM),
                'forceProcessed' => true,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Create a manual refund request (admin-initiated)
     */
    #[Route('/create-manual', name: 'api_admin_refunds_create_manual', methods: ['POST'])]
    public function createManual(Request $request): JsonResponse
    {
        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        // Validate required fields
        if (empty($data['userId'])) {
            return new JsonResponse(['message' => 'L\'utilisateur est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['reservationId'])) {
            return new JsonResponse(['message' => 'La réservation est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['amount']) || $data['amount'] <= 0) {
            return new JsonResponse(['message' => 'Le montant doit être supérieur à 0.'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['reason'])) {
            return new JsonResponse(['message' => 'La raison est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->em->getRepository(User::class)->find($data['userId']);
        if (!$user) {
            return new JsonResponse(['message' => 'Utilisateur introuvable.'], Response::HTTP_BAD_REQUEST);
        }

        $reservation = $this->em->getRepository(Reservation::class)->find($data['reservationId']);
        if (!$reservation) {
            return new JsonResponse(['message' => 'Réservation introuvable.'], Response::HTTP_BAD_REQUEST);
        }

        $trip = $reservation->getTrip();
        $agency = $trip?->getAgency();
        if (!$agency) {
            return new JsonResponse(['message' => 'Agence introuvable pour cette réservation.'], Response::HTTP_BAD_REQUEST);
        }

        // Create refund request
        $refundRequest = new RefundRequest();
        $refundRequest->setAgency($agency);
        $refundRequest->setReservation($reservation);
        $refundRequest->setRequestedBy($user);
        $refundRequest->setRequestedAmount((string) $data['amount']);
        $refundRequest->setReason($data['reason']);
        $refundRequest->setStatus(RefundRequest::STATUS_PENDING);
        $refundRequest->setAdminNote($data['adminNote'] ?? 'Créé manuellement par admin');

        $this->em->persist($refundRequest);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Demande de remboursement créée avec succès.',
            'refundId' => $refundRequest->getId(),
            'status' => $refundRequest->getStatus(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Check if a standard refund can be processed (sufficient funds)
     */
    private function canProcessStandardRefund(RefundRequest $refundRequest): bool
    {
        $agency = $refundRequest->getAgency();
        if (!$agency) {
            return false;
        }

        $wallet = $this->walletRepository->findOneBy(['agency' => $agency]);
        if (!$wallet) {
            return false;
        }

        $refundAmount = (float) $refundRequest->getRequestedAmount();
        $availableBalance = (float) $wallet->getAvailableBalance();

        // Standard refund requires sufficient available balance
        return $availableBalance >= $refundAmount;
    }

    /**
     * Get agent's available balance for a refund request
     */
    private function getAgentAvailableBalance(RefundRequest $refundRequest): float
    {
        $agency = $refundRequest->getAgency();
        if (!$agency) {
            return 0.0;
        }

        $wallet = $this->walletRepository->findOneBy(['agency' => $agency]);
        return $wallet ? (float) $wallet->getAvailableBalance() : 0.0;
    }

    /**
     * Calculate net refund amount (amount minus platform fee if applicable)
     */
    private function calculateNetRefundAmount(RefundRequest $refundRequest): float
    {
        $reservation = $refundRequest->getReservation();
        if (!$reservation) {
            return (float) $refundRequest->getRequestedAmount();
        }

        return $refundRequest->getNetAmount();
    }

    /**
     * Process a refund (standard or forced)
     * 
     * @param RefundRequest $refundRequest The refund request to process
     * @param User $admin The admin processing the refund
     * @param string|null $adminNote Optional admin note
     * @param bool $isForced Whether this is a forced refund
     * @return array Result with new balance and transaction ID
     * @throws \RuntimeException If processing fails
     */
    private function processRefund(RefundRequest $refundRequest, User $admin, ?string $adminNote, bool $isForced): array
    {
        $agency = $refundRequest->getAgency();
        if (!$agency) {
            throw new \RuntimeException('Agence introuvable pour cette demande de remboursement.');
        }

        $reservation = $refundRequest->getReservation();
        if (!$reservation) {
            throw new \RuntimeException('Réservation introuvable pour cette demande de remboursement.');
        }

        $wallet = $this->getOrCreateWallet($agency);
        $refundAmount = (float) $refundRequest->getRequestedAmount();
        $netAmount = $this->calculateNetRefundAmount($refundRequest);

        // For standard refunds, verify sufficient funds
        if (!$isForced) {
            $availableBalance = (float) $wallet->getAvailableBalance();
            if ($availableBalance < $netAmount) {
                throw new \RuntimeException(sprintf(
                    'Fonds insuffisants pour le remboursement standard. Solde disponible: %.2f XAF, Montant requis: %.2f XAF',
                    $availableBalance,
                    $netAmount
                ));
            }
        }

        // Process the refund - this will handle negative balance for forced refunds
        $oldBalance = (float) $wallet->getAvailableBalance();
        $newBalance = round($oldBalance - $netAmount, 2);
        
        $wallet->setAvailableBalance((string) $newBalance);
        $wallet->touch();
        $this->em->persist($wallet);

        // Create audit transaction
        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource($isForced ? WalletTransaction::SOURCE_ADMIN_DEBIT : WalletTransaction::SOURCE_REFUND);
        $tx->setAmount((string) $netAmount);
        $tx->setBalanceAfter((string) $newBalance);
        $tx->setReservation($reservation);
        $tx->setAdmin($admin);
        $tx->setAdminReason($adminNote ?? ($isForced ? 'Remboursement forcé par admin' : 'Remboursement standard'));
        $tx->setDescription(sprintf(
            '%s remboursement #%d: %.2f XAF (Agence: %s, Réservation: #%d)',
            $isForced ? 'FORCED' : 'STANDARD',
            $refundRequest->getId(),
            $netAmount,
            $agency->getName(),
            $reservation->getId()
        ));

        $this->em->persist($tx);

        // 👈 NOUVEAU : trace de la validation du remboursement dans le journal
        // des paiements (payment_logs), en miroir du log créé côté client lors
        // de la demande d'annulation (BookingController::cancel()).
        $paymentLog = new PaymentLog();
        $paymentLog->setReservation($reservation);
        $paymentLog->setOperator($agency->getName() ?? 'N/A');
        $paymentLog->setReference(uniqid('refund_processed_', true));
        $paymentLog->setAmount((string) $netAmount);
        $paymentLog->setStatus($isForced ? 'REFUNDED_FORCE' : 'REFUNDED_COMPLETED');
        $paymentLog->setRawResponse(json_encode([
            'type' => 'refund_processed',
            'refund_request_id' => $refundRequest->getId(),
            'processed_by_admin_id' => $admin->getId(),
            'is_forced' => $isForced,
            'admin_note' => $adminNote,
        ]));
        $this->em->persist($paymentLog);

        // Update refund request status
        $refundRequest->setStatus(RefundRequest::STATUS_COMPLETED);
        $refundRequest->setRefundedAmount((string) $refundAmount);
        $refundRequest->setProcessedAt(new \DateTime());
        $refundRequest->setProcessedByAdmin($admin);
        $refundRequest->setAdminNote($adminNote ?? ($isForced ? 'Remboursement forcé' : 'Remboursement standard'));

        $this->em->persist($refundRequest);

        return [
            'newBalance' => $newBalance,
            'transactionId' => $tx->getId(),
        ];
    }

    /**
     * Get or create wallet for an agency
     */
    private function getOrCreateWallet(Agency $agency): Wallet
    {
        $wallet = $this->walletRepository->findOneBy(['agency' => $agency]);
        if (!$wallet) {
            $wallet = new Wallet();
            $wallet->setAgency($agency);
            $wallet->setType(Wallet::TYPE_AGENCY);
            $this->em->persist($wallet);
        }
        return $wallet;
    }
}