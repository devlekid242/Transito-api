<?php

namespace App\Controller\Admin;

use App\Security\AdminRoleVoter;

use App\Entity\Agency;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Repository\AgencyRepository;
use App\Repository\RefundRequestRepository;
use App\Repository\TicketRepository;
use App\Repository\WalletRepository;
use App\Repository\WalletTransactionRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin Wallet Management Controller
 * Handles wallet listing, detail viewing, manual adjustments, and freeze/unfreeze operations.
 *
 * 👈 CORRIGÉ (audit sécurité) : #[IsGranted('ROLE_ADMIN')] ajouté — ce
 * contrôleur permet de créditer/débiter/geler manuellement N'IMPORTE QUEL
 * portefeuille d'agence, ce qui en fait la surface la plus sensible de
 * l'API. Il n'y avait auparavant aucune vérification de rôle.
 */
#[Route('/api/admin/wallets')]
#[IsGranted(AdminRoleVoter::FINANCE)]
#[IsGranted('ROLE_ADMIN')]
class AdminWalletController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private WalletRepository $walletRepository,
        private WalletTransactionRepository $walletTransactionRepository,
        private AgencyRepository $agencyRepository,
        private RefundRequestRepository $refundRequestRepository,
        private TicketRepository $ticketRepository,
        private WithdrawalRequestRepository $withdrawalRequestRepository,
    ) {
        // Symfony autowires repositories directly into WalletService; 
        // no manual setters needed here.
    }

    /**
     * List all agency wallets with balance summaries
     */
    #[Route('', name: 'api_admin_wallets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);
        $perPage = (int) $request->query->get('perPage', 20);
        $search = $request->query->get('search');
        $status = $request->query->get('status'); // normal, frozen, all

        // Get all agency wallets with summary
        $walletsWithSummary = $this->walletRepository->findAllAgencyWalletsWithSummary(
            $this->refundRequestRepository,
            $this->ticketRepository,
            $this->withdrawalRequestRepository
        );

        // Apply filters
        if ($search) {
            $walletsWithSummary = array_filter($walletsWithSummary, function($item) use ($search) {
                $agencyName = $item['agency'] ? $item['agency']->getName() : '';
                return stripos($agencyName, $search) !== false || stripos($item['wallet']->getId() ?? '', $search) !== false;
            });
        }

        if ($status && $status !== 'all') {
            $isFrozen = $status === 'frozen';
            $walletsWithSummary = array_filter($walletsWithSummary, function($item) use ($isFrozen) {
                return $item['frozen'] === $isFrozen;
            });
        }

        // Calculate total blocked balance across all wallets
        $totalBlockedBalance = (float) $this->walletRepository->getTotalBlockedBalance();

        // Calculate totals
        $totalAvailable = array_sum(array_column($walletsWithSummary, 'available'));
        $totalReserved = array_sum(array_column($walletsWithSummary, 'reserved'));
        $totalBalance = $totalAvailable + $totalReserved + $totalBlockedBalance;

        // Pagination
        $total = count($walletsWithSummary);
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedWallets = array_slice($walletsWithSummary, $offset, $perPage);

        // Format response
        $data = [];
        foreach ($paginatedWallets as $item) {
            $wallet = $item['wallet'];
            $agency = $item['agency'];
            
            $data[] = [
                'id' => $wallet->getId(),
                'agencyId' => $agency ? $agency->getId() : null,
                'agencyName' => $agency ? $agency->getName() : 'Inconnue',
                'available' => $item['available'],
                'reserved' => $item['reserved'],
                'blocked' => $item['blocked'],
                'total' => $item['total'],
                'currency' => 'XAF',
                'frozen' => $item['frozen'],
                'frozenAt' => $wallet->getFrozenAt()?->format(\DateTimeInterface::ATOM),
                'frozenByAdminId' => $wallet->getFrozenByAdmin()?->getId(),
                'frozenByAdminName' => $wallet->getFrozenByAdmin()?->getFullName(),
                'lastTransaction' => $this->getLastTransactionDate($wallet),
                'pendingWithdrawals' => $item['pendingWithdrawals'],
                'pendingRefunds' => $item['pendingRefunds'],
                'unvalidatedTickets' => $item['unvalidatedTickets'],
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
            'kpis' => [
                'totalAvailable' => $totalAvailable,
                'totalReserved' => $totalReserved,
                'totalBlocked' => $totalBlockedBalance,
                'totalBalance' => $totalBalance,
                'totalWallets' => $total,
                'frozenWallets' => count(array_filter($walletsWithSummary, fn($item) => $item['frozen'])),
            ],
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
            ],
        ]);
    }

    /**
     * Get a single wallet with detailed information
     */
    #[Route('/{id}', name: 'api_admin_wallets_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $wallet = $this->em->getRepository(Wallet::class)->find($id);
        
        if (!$wallet) {
            return new JsonResponse(['message' => 'Portefeuille introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $agency = $wallet->getAgency();
        
        if (!$agency) {
            return new JsonResponse(['message' => 'Agence introuvable pour ce portefeuille.'], Response::HTTP_BAD_REQUEST);
        }

        // Calculate blocked balance
        $blockedBalance = $this->walletService->calculateBlockedBalance($wallet);
        
        // Get balance summary
        $balanceSummary = $this->walletService->getWalletBalanceSummary($wallet);

        // Get wallet transactions
        $transactions = $this->walletTransactionRepository->findBy(
            ['wallet' => $wallet],
            ['createdAt' => 'DESC']
        );

        // Get agency withdrawal requests
        $withdrawals = $this->withdrawalRequestRepository->findBy(
            ['agency' => $agency],
            ['createdAt' => 'DESC']
        );

        // Calculate KPIs
        $totalIn = 0.0;
        $totalOut = 0.0;
        $agencyRevenue = 0.0;
        $platformCommission = 0.0;
        $refundTotal = 0.0;

        foreach ($transactions as $tx) {
            $amount = (float) $tx->getAmount();
            if ($tx->getType() === WalletTransaction::TYPE_CREDIT) {
                $totalIn += $amount;
                if ($tx->getSource() === WalletTransaction::SOURCE_RESERVATION_PAYMENT) {
                    $agencyRevenue += $amount;
                }
                if ($tx->getSource() === WalletTransaction::SOURCE_PLATFORM_FEE) {
                    $platformCommission += $amount;
                }
            } else {
                $totalOut += $amount;
                if ($tx->getSource() === WalletTransaction::SOURCE_REFUND) {
                    $refundTotal += $amount;
                }
            }
        }

        // Format transaction data
        $formattedTransactions = [];
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'id' => $tx->getId(),
                'type' => $tx->getType(),
                'source' => $tx->getSource(),
                'label' => $tx->getDescription() ?? $tx->getSource(),
                'amount' => (float) $tx->getAmount(),
                'balanceAfter' => (float) $tx->getBalanceAfter(),
                'date' => $tx->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'adminId' => $tx->getAdmin()?->getId(),
                'adminName' => $tx->getAdmin()?->getFullName(),
                'adminReason' => $tx->getAdminReason(),
            ];
        }

        // Format withdrawal data
        $formattedWithdrawals = [];
        foreach ($withdrawals as $withdrawal) {
            $formattedWithdrawals[] = [
                'id' => $withdrawal->getId(),
                'amount' => (float) $withdrawal->getAmount(),
                'method' => $withdrawal->getMethod(),
                'status' => $withdrawal->getStatus(),
                'notes' => $withdrawal->getNotes(),
                'adminNote' => $withdrawal->getAdminNote(),
                'date' => $withdrawal->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'processedAt' => $withdrawal->getProcessedAt()?->format(\DateTimeInterface::ATOM),
                'remainingBalance' => $this->calculateRemainingBalanceAfterWithdrawal($withdrawal),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => [
                'wallet' => [
                    'id' => $wallet->getId(),
                    'agencyId' => $agency->getId(),
                    'agency' => $agency->getName(),
                    'available' => (float) $wallet->getAvailableBalance(),
                    'reserved' => (float) $wallet->getReservedBalance(),
                    'blocked' => $blockedBalance,
                    'total' => (float) $wallet->getTotalBalance(),
                    'frozen' => $wallet->isFrozen(),
                    'frozenAt' => $wallet->getFrozenAt()?->format(\DateTimeInterface::ATOM),
                    'frozenByAdminId' => $wallet->getFrozenByAdmin()?->getId(),
                    'frozenByAdminName' => $wallet->getFrozenByAdmin()?->getFullName(),
                    'currency' => 'XAF',
                    'createdAt' => $wallet->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                ],
                'kpis' => [
                    'totalIn' => $totalIn,
                    'totalOut' => $totalOut,
                    'agencyRevenue' => $agencyRevenue,
                    'platformCommission' => $platformCommission,
                    'refundTotal' => $refundTotal,
                    'netBalance' => (float) $wallet->getTotalBalance(),
                    'transactionCount' => count($transactions),
                    'withdrawalCount' => count($withdrawals),
                    'blockedBalance' => $blockedBalance,
                    'availableForWithdrawal' => $balanceSummary['availableForWithdrawal'],
                ],
                'transactions' => $formattedTransactions,
                'withdrawals' => $formattedWithdrawals,
            ],
        ]);
    }

    /**
     * Manually credit an agency wallet
     */
    #[Route('/{id}/credit', name: 'api_admin_wallets_credit', methods: ['POST'])]
    public function creditWallet(int $id, Request $request): JsonResponse
    {
        $wallet = $this->em->getRepository(Wallet::class)->find($id);
        
        if (!$wallet) {
            return new JsonResponse(['message' => 'Portefeuille introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $amount = $data['amount'] ?? 0;
        $reason = $data['reason'] ?? '';

        if (!$reason) {
            return new JsonResponse(['message' => 'Une justification est obligatoire pour cette opération.'], Response::HTTP_BAD_REQUEST);
        }

        if ($amount <= 0) {
            return new JsonResponse(['message' => 'Le montant doit être positif.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $tx = $this->walletService->creditWalletManually($wallet, $amount, $currentAdmin, $reason);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('%.2f XAF crédités avec succès sur le portefeuille.', $amount),
                'walletId' => $wallet->getId(),
                'newBalance' => (float) $wallet->getAvailableBalance(),
                'transactionId' => $tx->getId(),
                'transaction' => [
                    'id' => $tx->getId(),
                    'type' => $tx->getType(),
                    'source' => $tx->getSource(),
                    'amount' => (float) $tx->getAmount(),
                    'balanceAfter' => (float) $tx->getBalanceAfter(),
                    'date' => $tx->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                    'adminId' => $currentAdmin->getId(),
                    'adminName' => $currentAdmin->getFullName(),
                    'reason' => $reason,
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Manually debit an agency wallet
     */
    #[Route('/{id}/debit', name: 'api_admin_wallets_debit', methods: ['POST'])]
    public function debitWallet(int $id, Request $request): JsonResponse
    {
        $wallet = $this->em->getRepository(Wallet::class)->find($id);
        
        if (!$wallet) {
            return new JsonResponse(['message' => 'Portefeuille introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $amount = $data['amount'] ?? 0;
        $reason = $data['reason'] ?? '';

        if (!$reason) {
            return new JsonResponse(['message' => 'Une justification est obligatoire pour cette opération.'], Response::HTTP_BAD_REQUEST);
        }

        if ($amount <= 0) {
            return new JsonResponse(['message' => 'Le montant doit être positif.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $tx = $this->walletService->debitWalletManually($wallet, $amount, $currentAdmin, $reason);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('%.2f XAF débités avec succès du portefeuille.', $amount),
                'walletId' => $wallet->getId(),
                'newBalance' => (float) $wallet->getAvailableBalance(),
                'transactionId' => $tx->getId(),
                'transaction' => [
                    'id' => $tx->getId(),
                    'type' => $tx->getType(),
                    'source' => $tx->getSource(),
                    'amount' => (float) $tx->getAmount(),
                    'balanceAfter' => (float) $tx->getBalanceAfter(),
                    'date' => $tx->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                    'adminId' => $currentAdmin->getId(),
                    'adminName' => $currentAdmin->getFullName(),
                    'reason' => $reason,
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Freeze an agency wallet
     */
    #[Route('/{id}/freeze', name: 'api_admin_wallets_freeze', methods: ['POST'])]
    public function freezeWallet(int $id, Request $request): JsonResponse
    {
        $wallet = $this->em->getRepository(Wallet::class)->find($id);
        
        if (!$wallet) {
            return new JsonResponse(['message' => 'Portefeuille introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($wallet->isFrozen()) {
            return new JsonResponse(['message' => 'Ce portefeuille est déjà gelé.'], Response::HTTP_CONFLICT);
        }

        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reason = $data['reason'] ?? null;

        try {
            $this->walletService->freezeWallet($wallet, $currentAdmin, $reason);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Portefeuille gelé avec succès.',
                'walletId' => $wallet->getId(),
                'frozen' => true,
                'frozenAt' => $wallet->getFrozenAt()?->format(\DateTimeInterface::ATOM),
                'frozenByAdminId' => $currentAdmin->getId(),
                'frozenByAdminName' => $currentAdmin->getFullName(),
                'reason' => $reason,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Unfreeze an agency wallet
     */
    #[Route('/{id}/unfreeze', name: 'api_admin_wallets_unfreeze', methods: ['POST'])]
    public function unfreezeWallet(int $id, Request $request): JsonResponse
    {
        $wallet = $this->em->getRepository(Wallet::class)->find($id);
        
        if (!$wallet) {
            return new JsonResponse(['message' => 'Portefeuille introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (!$wallet->isFrozen()) {
            return new JsonResponse(['message' => 'Ce portefeuille n\'est pas gelé.'], Response::HTTP_CONFLICT);
        }

        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof User) {
            return new JsonResponse(['message' => 'Authentification requise.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reason = $data['reason'] ?? null;

        try {
            $this->walletService->unfreezeWallet($wallet, $currentAdmin, $reason);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Portefeuille dégélé avec succès.',
                'walletId' => $wallet->getId(),
                'frozen' => false,
                'frozenAt' => null,
                'frozenByAdminId' => null,
                'frozenByAdminName' => null,
                'reason' => $reason,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get wallet balance summary (available, reserved, blocked)
     */
    #[Route('/{id}/summary', name: 'api_admin_wallets_summary', methods: ['GET'])]
    public function getWalletSummary(int $id): JsonResponse
    {
        $wallet = $this->em->getRepository(Wallet::class)->find($id);
        
        if (!$wallet) {
            return new JsonResponse(['message' => 'Portefeuille introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $summary = $this->walletService->getWalletBalanceSummary($wallet);

        return $this->json([
            'success' => true,
            'walletId' => $wallet->getId(),
            'available' => $summary['available'],
            'reserved' => $summary['reserved'],
            'blocked' => $summary['blocked'],
            'total' => $summary['total'],
            'availableForWithdrawal' => $summary['availableForWithdrawal'],
            'pendingRefunds' => $this->refundRequestRepository->getPendingRefundsAmountForAgency($wallet->getAgency()),
            'unvalidatedTickets' => $this->ticketRepository->getUnvalidatedTicketsAmountForAgency($wallet->getAgency()),
        ]);
    }

    /**
     * Helper: Get last transaction date for a wallet
     */
    private function getLastTransactionDate(Wallet $wallet): ?string
    {
        $lastTx = $this->walletTransactionRepository->findOneBy(
            ['wallet' => $wallet],
            ['createdAt' => 'DESC']
        );

        if ($lastTx) {
            return $lastTx->getCreatedAt()?->format('d/m/Y H:i');
        }

        return null;
    }

    /**
     * Helper: Calculate remaining balance after a specific withdrawal
     */
    private function calculateRemainingBalanceAfterWithdrawal($withdrawal): float
    {
        $wallet = $withdrawal->getAgency() ? $this->walletRepository->findOneBy(['agency' => $withdrawal->getAgency()]) : null;
        if (!$wallet) {
            return 0.0;
        }

        $available = (float) $wallet->getAvailableBalance();
        $reserved = (float) $wallet->getReservedBalance();
        $withdrawalAmount = (float) $withdrawal->getAmount();

        // Total available = available + reserved - withdrawal (since it's reserved)
        return round($available + $reserved - $withdrawalAmount, 2);
    }
}
