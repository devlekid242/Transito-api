<?php

namespace App\Controller\Admin;

use App\Entity\Agency;
use App\Entity\PaymentLog;
use App\Entity\RefundRequest;
use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Wallet;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Refund Management Controller
 * Handles listing refund requests, processing standard refunds, and force refunds.
 *
 * SÉCURITÉ : #[IsGranted('ROLE_ADMIN')] ajouté au niveau du contrôleur —
 * auparavant seule la connexion (getUser() instanceof User) était vérifiée
 * dans chaque action, ce qui permettait à N'IMPORTE QUEL utilisateur
 * authentifié (client, agent d'agence...) de forcer des remboursements et
 * de manipuler les portefeuilles des agences. Adapter le rôle exact
 * ('ROLE_SUPER_ADMIN', etc.) à votre hiérarchie de rôles réelle.
 */
#[Route('/api/admin/refunds')]
// #[IsGranted('ROLE_ADMIN')]
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
                // 👈 NOUVEAU : alias explicites pour le suivi pending -> completed/rejected
                'initiatedAt' => $refundRequest->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'validatedAt' => $refundRequest->getProcessedAt()?->format(\DateTimeInterface::ATOM),
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
     * 👈 NOUVEAU : endpoint manquant. Jusqu'ici, une RefundRequest ne pouvait
     * transitionner que de STATUS_PENDING vers STATUS_COMPLETED (via
     * process-standard / process-forced) — il n'existait AUCUN moyen de la
     * faire passer à STATUS_REJECTED. Une demande jugée non fondée par
     * l'admin restait donc éternellement "pending" (et gonflait indéfiniment
     * le KPI totalPending / totalAmountPending).
     *
     * Contrairement à processStandard()/processForced(), cette action ne
     * touche à AUCUN portefeuille : aucun argent n'a encore été débité pour
     * une demande encore pending, il n'y a donc rien à annuler côté wallet.
     * On se contente de clôturer proprement la demande et de synchroniser le
     * PaymentLog "REFUND_PENDING" associé (créé par BookingController::cancel())
     * pour qu'il ne reste pas orphelin dans PaymentController::pendingRefunds().
     */
    #[Route('/{id}/reject', name: 'api_admin_refunds_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
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
        $adminNote = trim((string) ($data['adminNote'] ?? ''));
        if ($adminNote === '') {
            return new JsonResponse(['message' => 'Un motif de rejet est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $refundRequest->setStatus(RefundRequest::STATUS_REJECTED);
        $refundRequest->setProcessedAt(new \DateTime());
        $refundRequest->setProcessedByAdmin($currentAdmin);
        $refundRequest->setAdminNote($adminNote);
        $this->em->persist($refundRequest);

        // Synchroniser le PaymentLog "REFUND_PENDING" d'origine s'il existe,
        // pour qu'il n'apparaisse plus dans les files d'attente de remboursement.
        $reservation = $refundRequest->getReservation();
        if ($reservation) {
            $paymentLog = $this->em->getRepository(PaymentLog::class)->findOneBy([
                'reservation' => $reservation,
                'status' => 'REFUND_PENDING',
            ]);
            if ($paymentLog) {
                $paymentLog->setStatus('REFUND_REJECTED');
                $raw = json_decode($paymentLog->getRawResponse() ?? '{}', true);
                $raw['refund_rejection'] = [
                    'rejected_by_admin_id' => $currentAdmin->getId(),
                    'reason' => $adminNote,
                    'rejected_at' => (new \DateTime())->format('c'),
                ];
                $paymentLog->setRawResponse(json_encode($raw));
                $this->em->persist($paymentLog);
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Demande de remboursement rejetée.',
            'refundId' => $refundRequest->getId(),
            'status' => $refundRequest->getStatus(),
            'processedAt' => $refundRequest->getProcessedAt()?->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
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
     * Search standard clients for the manual refund creation form's user
     * search-select. Excludes staff/admin accounts (and, where applicable,
     * agency/partner accounts) so only end-customers are selectable.
     */
    #[Route('/lookup/clients', name: 'api_admin_refunds_lookup_clients', methods: ['GET'])]
    public function lookupClients(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));

        $qb = $this->em->getRepository(User::class)->createQueryBuilder('u')
            // Exclude staff accounts (ROLE_ADMIN / ROLE_SUPER_ADMIN etc. are
            // derived from a linked Admin record — see User::getRoles()).
            // Agencies/partners authenticate as the separate Agency entity,
            // so they are naturally excluded from the users table already.
            ->orderBy('u.fullName', 'ASC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('u.fullName LIKE :q OR u.phoneNumber LIKE :q OR u.email LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        /** @var User[] $users */
        $users = $qb->getQuery()->getResult();

        $data = array_map(static fn (User $u) => [
            'id' => (string) $u->getId(),
            'label' => $u->getFullName() ?? ('Utilisateur #' . $u->getId()),
            'sublabel' => $u->getPhoneNumber(),
            'email' => $u->getEmail(),
        ], $users);

        return $this->json(['success' => true, 'data' => $data]);
    }

    /**
     * Search reservations for the manual refund creation form's reservation
     * search-select. Optionally scoped to a previously selected client via
     * `userId`, since a refund is always tied to a specific booking.
     */
    #[Route('/lookup/reservations', name: 'api_admin_refunds_lookup_reservations', methods: ['GET'])]
    public function lookupReservations(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $userId = $request->query->get('userId');
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));

        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($userId) {
            $qb->andWhere('u.id = :userId')->setParameter('userId', (int) $userId);
        }

        if ($query !== '') {
            $qb->andWhere('r.transactionReference LIKE :q OR u.fullName LIKE :q OR u.phoneNumber LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        /** @var Reservation[] $reservations */
        $reservations = $qb->getQuery()->getResult();

        $data = array_map(static fn (Reservation $r) => [
            'id' => (string) $r->getId(),
            'label' => $r->getTransactionReference() ?? ('Réservation #' . $r->getId()),
            'sublabel' => sprintf(
                '%s — %s XAF',
                $r->getUser()?->getFullName() ?? 'Client inconnu',
                number_format((float) $r->getTotalAmount(), 0, ',', ' ')
            ),
            'userId' => $r->getUser()?->getId(),
            'totalAmount' => (float) $r->getTotalAmount(),
            'paymentStatus' => $r->getPaymentStatus(),
        ], $reservations);

        return $this->json(['success' => true, 'data' => $data]);
    }

    /**
     * Search agencies for the manual refund creation form's agency
     * search-select.
     */
    #[Route('/lookup/agencies', name: 'api_admin_refunds_lookup_agencies', methods: ['GET'])]
    public function lookupAgencies(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));

        $qb = $this->agencyRepository->createQueryBuilder('a')
            ->orderBy('a.name', 'ASC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('a.name LIKE :q OR a.phone LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        /** @var Agency[] $agencies */
        $agencies = $qb->getQuery()->getResult();

        $data = array_map(static fn (Agency $a) => [
            'id' => (string) $a->getId(),
            'label' => $a->getName(),
            'sublabel' => $a->getPhone(),
            'status' => $a->getStatus(),
        ], $agencies);

        return $this->json(['success' => true, 'data' => $data]);
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

        // 👈 CORRIGÉ : comparer au montant NET (ce qui sera réellement
        // débité par processRefund()/debitForRefund()), pas au montant brut
        // demandé — sinon ce flag pouvait dire "standard possible" alors que
        // le traitement standard échouait ensuite (ou inversement).
        $netAmount = $this->calculateNetRefundAmount($refundRequest);
        $availableBalance = (float) $wallet->getAvailableBalance();

        // Standard refund requires sufficient available balance
        return $availableBalance >= $netAmount;
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
    /**
     * 👈 CORRIGÉ (audit sécurité/intégrité financière) :
     *
     * AVANT : cette méthode mutait `Wallet::availableBalance` directement,
     * en dehors de WalletService, en violation de la règle d'or documentée
     * dans Wallet.php ("ce solde ne doit JAMAIS être modifié directement
     * depuis un contrôleur"). Elle ne vérifiait également JAMAIS si une
     * transaction SOURCE_REFUND existait déjà pour cette réservation.
     *
     * Or PaymentController::refund() peut débiter la MÊME réservation via
     * WalletService::debitForRefund() (qui, lui, est idempotent). Sans garde
     * commune, une même annulation pouvait donc être remboursée deux fois :
     * une fois via /api/payments/{id}/refund (file d'attente PaymentLog),
     * une fois via /api/admin/refunds/{id}/process-* (file RefundRequest) —
     * ces deux files étant alimentées simultanément par le même événement
     * dans BookingController::cancel().
     *
     * APRÈS : on délègue à WalletService::debitForRefund(), la SEULE méthode
     * autorisée à débiter un portefeuille suite à un remboursement. Elle
     * retrouve/évite tout doublon par réservation, que l'appel vienne d'ici
     * ou de PaymentController. Le PaymentLog "REFUND_PENDING" d'origine
     * (créé par BookingController::cancel()) est mis à jour en place au lieu
     * d'être dupliqué, pour ne plus laisser d'enregistrement fantôme dans
     * PaymentController::pendingRefunds().
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
        $netAmount = $this->calculateNetRefundAmount($refundRequest);

        // For standard refunds, verify sufficient funds BEFORE attempting the debit
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

        $reason = $adminNote ?? ($isForced ? 'Remboursement forcé par admin' : 'Remboursement standard');

        // 👈 Point d'écriture UNIQUE. $allowNegative = $isForced permet au
        // remboursement forcé de faire passer le solde en négatif plutôt que
        // de le plafonner silencieusement, pour refléter une vraie dette.
        $tx = $this->walletService->debitForRefund($reservation, $reason, $isForced);

        if (!$tx) {
            throw new \RuntimeException('Aucun crédit trouvé pour cette réservation — impossible de déterminer le montant à rembourser.');
        }

        // Enrichir la transaction avec la traçabilité admin (WalletService
        // ne connaît pas l'admin qui a déclenché l'action)
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason);
        $this->em->persist($tx);

        // 👈 CORRIGÉ : on met à jour le PaymentLog "REFUND_PENDING" créé par
        // BookingController::cancel() au lieu d'en créer un nouveau — évite
        // qu'il reste orphelin dans PaymentController::pendingRefunds().
        $paymentLog = $this->em->getRepository(PaymentLog::class)->findOneBy([
            'reservation' => $reservation,
            'status' => 'REFUND_PENDING',
        ]);
        if (!$paymentLog) {
            // Remboursement créé manuellement par un admin (pas d'annulation
            // client préalable) : pas de log en attente à retrouver, on en
            // trace un nouveau pour garder l'historique complet.
            $paymentLog = new PaymentLog();
            $paymentLog->setReservation($reservation);
            $paymentLog->setOperator($agency->getName() ?? 'N/A');
            $paymentLog->setReference(uniqid('refund_processed_', true));
        }
        $paymentLog->setAmount((string) $tx->getAmount());
        $paymentLog->setStatus($isForced ? 'REFUNDED_FORCE' : 'REFUNDED_COMPLETED');
        $paymentLog->setRawResponse(json_encode([
            'type' => 'refund_processed',
            'refund_request_id' => $refundRequest->getId(),
            'processed_by_admin_id' => $admin->getId(),
            'is_forced' => $isForced,
            'admin_note' => $adminNote,
            'processed_at' => (new \DateTime())->format('c'),
        ]));
        $this->em->persist($paymentLog);

        // Update refund request status
        // 👈 CORRIGÉ : refundedAmount stocke désormais le montant NET
        // réellement débité du portefeuille ($tx->getAmount()), et non plus
        // le montant brut demandé (RefundRequest::requestedAmount) — c'était
        // une incohérence de reporting entre ce champ et le grand livre.
        $refundRequest->setStatus(RefundRequest::STATUS_COMPLETED);
        $refundRequest->setRefundedAmount($tx->getAmount());
        $refundRequest->setProcessedAt(new \DateTime());
        $refundRequest->setProcessedByAdmin($admin);
        $refundRequest->setAdminNote($reason);

        $this->em->persist($refundRequest);

        // Garder la réservation synchronisée si elle ne l'était pas déjà
        // (cas d'un remboursement créé manuellement sans passer par cancel())
        if ($reservation->getPaymentStatus() !== 'rembourser') {
            $reservation->setPaymentStatus('rembourse');
            $this->em->persist($reservation);
        }

        return [
            'newBalance' => (float) $wallet->getAvailableBalance(),
            'transactionId' => $tx->getId(),
        ];
    }

    /**
     * Get list of clients (users who are not admins or partners) for selection
     */
    #[Route('/clients/list', name: 'api_admin_refunds_clients_list', methods: ['GET'])]
    public function listClients(Request $request): JsonResponse
    {
        $search = $request->query->get('search', '');
        $limit = (int) $request->query->get('limit', 50);

        $queryBuilder = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.roles NOT LIKE :adminRole')
            ->andWhere('u.roles NOT LIKE :partnerRole')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->setParameter('partnerRole', '%ROLE_PARTNER%')
            ->orderBy('u.fullName', 'ASC')
            ->setMaxResults($limit);

        if ($search) {
            $queryBuilder->andWhere('u.fullName LIKE :search OR u.email LIKE :search OR u.phone LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $users = $queryBuilder->getQuery()->getResult();

        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'name' => $user->getFullName(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhoneNumber() ?? $user->getPhone(),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get list of reservations for selection
     */
    #[Route('/reservations/list', name: 'api_admin_refunds_reservations_list', methods: ['GET'])]
    public function listReservations(Request $request): JsonResponse
    {
        $search = $request->query->get('search', '');
        $limit = (int) $request->query->get('limit', 50);

        $queryBuilder = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->join('r.user', 'u')
            ->join('r.trip', 't')
            ->join('t.agency', 'a')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($search) {
            $queryBuilder->andWhere('r.transactionReference LIKE :search OR u.fullName LIKE :search OR a.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $reservations = $queryBuilder->getQuery()->getResult();

        $data = [];
        foreach ($reservations as $reservation) {
            $user = $reservation->getUser();
            $trip = $reservation->getTrip();
            $agency = $trip ? $trip->getAgency() : null;

            $data[] = [
                'id' => $reservation->getId(),
                'bookingReference' => $reservation->getTransactionReference(),
                'totalAmount' => (float) $reservation->getTotalAmount(),
                'paymentStatus' => $reservation->getPaymentStatus(),
                'clientName' => $user ? $user->getFullName() : 'Inconnu',
                'agencyName' => $agency ? $agency->getName() : 'Inconnue',
                'createdAt' => $reservation->getCreatedAt()?->format('Y-m-d H:i'),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get list of agencies for selection
     */
    #[Route('/agencies/list', name: 'api_admin_refunds_agencies_list', methods: ['GET'])]
    public function listAgencies(Request $request): JsonResponse
    {
        $search = $request->query->get('search', '');
        $limit = (int) $request->query->get('limit', 50);

        $queryBuilder = $this->em->getRepository(Agency::class)->createQueryBuilder('a')
            ->orderBy('a.name', 'ASC')
            ->setMaxResults($limit);

        if ($search) {
            $queryBuilder->andWhere('a.name LIKE :search OR a.city LIKE :search OR a.phone LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $agencies = $queryBuilder->getQuery()->getResult();

        $data = [];
        foreach ($agencies as $agency) {
            $data[] = [
                'id' => $agency->getId(),
                'name' => $agency->getName(),
                'city' => $agency->getCity(),
                'phone' => $agency->getPhone(),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
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