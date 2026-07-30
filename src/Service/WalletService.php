<?php

namespace App\Service;

use App\Entity\Agency;
use App\Entity\RefundRequest;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Entity\WithdrawalRequest;
use App\Repository\RefundRequestRepository;
use App\Repository\TicketRepository;
use App\Repository\WithdrawalRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralise TOUTES les écritures sur les portefeuilles d'agence.
 *
 * Règle d'or : le solde d'un Wallet ne doit jamais être modifié ailleurs que
 * dans ce service, afin que le ledger (WalletTransaction) reste toujours
 * cohérent avec les soldes affichés au partenaire.
 *
 * NB : ce service ne flush() jamais lui-même — c'est au contrôleur appelant
 * de le faire, pour rester maître de la transaction Doctrine globale.
 */
class WalletService
{
    public const PLATFORM_FEE = 500.00;

    public function __construct(
        private EntityManagerInterface $em,
        private ?RefundRequestRepository $refundRequestRepository = null,
        private ?TicketRepository $ticketRepository = null,
        private ?WithdrawalRequestRepository $withdrawalRequestRepository = null
    ) {
        // For backward compatibility, repositories might be null
        // in some contexts, but they're required for advanced features
    }

    public function getOrCreateWallet(Agency $agency): Wallet
    {
        $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['agency' => $agency]);
        if (!$wallet) {
            $wallet = new Wallet();
            $wallet->setAgency($agency);
            $wallet->setType(Wallet::TYPE_AGENCY);
            $this->em->persist($wallet);
            $this->em->flush();
        }
        return $wallet;
    }

    public function getOrCreatePlatformWallet(): Wallet
    {
        $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['type' => Wallet::TYPE_PLATFORM]);
        if (!$wallet) {
            $wallet = new Wallet();
            $wallet->setType(Wallet::TYPE_PLATFORM);
            $this->em->persist($wallet);
            $this->em->flush();
        }
        return $wallet;
    }

    /**
     * Crédite le portefeuille d'une agence suite au paiement CONFIRMÉ (statut
     * SUCCESS) d'une réservation. Deux lignes sont enregistrées dans le ledger :
     *   1) un crédit du montant brut de la réservation,
     *   2) un débit correspondant à la commission de la plateforme.
     * Le solde net réellement acquis par l'agence est donc gross - fee.
     *
     * Idempotent : si cette réservation a déjà été créditée, la méthode ne
     * fait rien et retourne les transactions existantes (protège contre un
     * double appel de confirm()).
     *
     * @return array{credit: ?WalletTransaction, fee: ?WalletTransaction}
     */
    public function creditForReservationPayment(Reservation $reservation): array
    {
        $trip = $reservation->getTrip();
        $agency = $trip?->getAgency();
        if (!$agency) {
            return ['credit' => null, 'fee' => null];
        }

        $existingCredit = $this->em->getRepository(WalletTransaction::class)->findOneBy([
            'reservation' => $reservation,
            'type' => WalletTransaction::TYPE_CREDIT,
            'source' => WalletTransaction::SOURCE_RESERVATION_PAYMENT,
        ]);
        if ($existingCredit) {
            $existingFee = $this->em->getRepository(WalletTransaction::class)->findOneBy([
                'reservation' => $reservation,
                'source' => WalletTransaction::SOURCE_PLATFORM_FEE,
            ]);
            return ['credit' => $existingCredit, 'fee' => $existingFee];
        }

        $wallet = $this->getOrCreateWallet($agency);

        $grossAmount = round((float) $reservation->getTotalAmount(), 2);
        $platformFee = round(self::PLATFORM_FEE, 2);
        $netAmount = max(0.0, round($grossAmount - $platformFee, 2));

        // 1) Crédit net au portefeuille de l'agence
        $balanceAfterCredit = round((float) $wallet->getAvailableBalance() + $netAmount, 2);
        $wallet->setAvailableBalance((string) $balanceAfterCredit);

        $creditTx = new WalletTransaction();
        $creditTx->setWallet($wallet);
        $creditTx->setType(WalletTransaction::TYPE_CREDIT);
        $creditTx->setSource(WalletTransaction::SOURCE_RESERVATION_PAYMENT);
        $creditTx->setAmount((string) $netAmount);
        $creditTx->setFeeAmount((string) $platformFee);
        $creditTx->setBalanceAfter((string) $balanceAfterCredit);
        $creditTx->setReservation($reservation);
        $creditTx->setDescription(sprintf('Paiement réservation #%d (net après frais plateforme)', $reservation->getId()));
        $this->em->persist($creditTx);

        // 2) Commission plateforme créditée dans le portefeuille plateforme dédié
        $platformWallet = $this->getOrCreatePlatformWallet();
        $platformBalanceAfter = round((float) $platformWallet->getAvailableBalance() + $platformFee, 2);
        $platformWallet->setAvailableBalance((string) $platformBalanceAfter);

        $platformFeeTx = new WalletTransaction();
        $platformFeeTx->setWallet($platformWallet);
        $platformFeeTx->setType(WalletTransaction::TYPE_CREDIT);
        $platformFeeTx->setSource(WalletTransaction::SOURCE_PLATFORM_FEE);
        $platformFeeTx->setAmount((string) $platformFee);
        $platformFeeTx->setBalanceAfter((string) $platformBalanceAfter);
        $platformFeeTx->setReservation($reservation);
        $platformFeeTx->setDescription(sprintf('Commission plateforme réservation #%d', $reservation->getId()));
        $this->em->persist($platformFeeTx);

        $wallet->setTotalEarned((string) round((float) $wallet->getTotalEarned() + $netAmount, 2));
        $wallet->touch();
        $this->em->persist($wallet);
        $this->em->persist($platformWallet);

        return ['credit' => $creditTx, 'fee' => $platformFeeTx];
    }

    /**
     * Débite le portefeuille suite au remboursement d'une réservation déjà
     * créditée. Ne rembourse que le montant NET réellement acquis par
     * l'agence (la commission plateforme, elle, n'a jamais transité par le
     * portefeuille de l'agence et n'a donc rien à "rendre").
     *
     * Si les fonds correspondants ont déjà été retirés (available insuffisant) :
     *   - mode standard ($allowNegative = false) : le débit est PLAFONNÉ à ce
     *     qui reste disponible et le manque à gagner est noté dans la
     *     description — à traiter manuellement par l'admin.
     *   - mode forcé ($allowNegative = true) : le débit est appliqué en
     *     intégralité, quitte à faire passer le solde disponible en négatif,
     *     pour refléter fidèlement la dette réelle de l'agence.
     *
     * SOURCE UNIQUE DE VÉRITÉ : c'est la SEULE méthode qui doit débiter un
     * portefeuille suite à un remboursement, qu'il soit initié depuis
     * PaymentController::refund() ou depuis AdminRefundController. Elle est
     * idempotente PAR RÉSERVATION (peu importe le "mode") : un second appel,
     * standard ou forcé, retrouve la transaction SOURCE_REFUND déjà créée et
     * ne débite jamais deux fois.
     */
    public function debitForRefund(Reservation $reservation, ?string $reason = null, bool $allowNegative = false): ?WalletTransaction
    {
        $trip = $reservation->getTrip();
        $agency = $trip?->getAgency();
        if (!$agency) {
            return null;
        }

        $creditTx = $this->em->getRepository(WalletTransaction::class)->findOneBy([
            'reservation' => $reservation,
            'type' => WalletTransaction::TYPE_CREDIT,
            'source' => WalletTransaction::SOURCE_RESERVATION_PAYMENT,
        ]);
        if (!$creditTx) {
            // La réservation n'avait jamais été créditée sur un portefeuille
            // (ex : paiement jamais confirmé) — rien à rembourser côté wallet.
            return null;
        }

        // 👈 Garde d'idempotence GLOBALE : peu importe quel contrôleur appelle
        // cette méthode (PaymentController::refund ou AdminRefundController),
        // une réservation ne peut jamais être débitée deux fois. C'est ce
        // qui protège contre le double remboursement entre les deux surfaces
        // d'administration.
        $existingRefund = $this->em->getRepository(WalletTransaction::class)->findOneBy([
            'reservation' => $reservation,
            'type' => WalletTransaction::TYPE_DEBIT,
            'source' => WalletTransaction::SOURCE_REFUND,
        ]);
        if ($existingRefund) {
            return $existingRefund;
        }

        $wallet = $this->getOrCreateWallet($agency);

        $netAmount = round((float) $creditTx->getAmount(), 2);
        $available = round((float) $wallet->getAvailableBalance(), 2);

        if ($allowNegative) {
            // Remboursement forcé : on débite le montant complet, même si le
            // solde disponible devient négatif — ce négatif reflète une dette
            // réelle de l'agence envers la plateforme, à recouvrer.
            $debited = $netAmount;
            $shortfall = 0.0;
        } else {
            $debited = min($available, $netAmount);
            $shortfall = round($netAmount - $debited, 2);
        }

        $newAvailable = round($available - $debited, 2);
        $wallet->setAvailableBalance((string) $newAvailable);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        // 👈 Toujours SOURCE_REFUND (même en mode forcé) : c'est ce tag,
        // et non le "mode", qui sert de clé d'idempotence ci-dessus.
        $tx->setSource(WalletTransaction::SOURCE_REFUND);
        $tx->setAmount((string) $debited);
        $tx->setBalanceAfter((string) $newAvailable);
        $tx->setReservation($reservation);

        $description = sprintf('Remboursement réservation #%d (%s)', $reservation->getId(), $reason ?? 'non précisé');
        if ($shortfall > 0) {
            $description .= sprintf(' — manque à gagner %.2f XAF (fonds déjà retirés par l\'agence)', $shortfall);
        }
        if ($allowNegative && $newAvailable < 0) {
            $description .= sprintf(' — REMBOURSEMENT FORCÉ, solde agence désormais négatif (%.2f XAF)', $newAvailable);
        }
        $tx->setDescription($description);

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Réserve le montant demandé sur le solde disponible d'une agence lors de
     * la CRÉATION d'une demande de retrait. C'est cette étape qui corrige
     * l'incohérence historique : dès qu'une demande est en attente, le
     * montant n'est plus utilisable pour une autre demande.
     *
     * @throws \RuntimeException si le solde disponible est insuffisant
     */
    public function reserveForWithdrawal(WithdrawalRequest $withdrawal): WalletTransaction
    {
        $agency = $withdrawal->getAgency();
        $wallet = $this->getOrCreateWallet($agency);
        $amount = round((float) $withdrawal->getAmount(), 2);
        $available = round((float) $wallet->getAvailableBalance(), 2);

        if ($amount > $available) {
            throw new \RuntimeException('Solde disponible insuffisant pour cette demande de retrait.');
        }

        // 👈 NOUVEAU : le solde bloqué (remboursements clients en attente +
        // billets non validés) n'était vérifié qu'à l'approbation admin,
        // jamais à la création. Une agence pouvait donc réserver la totalité
        // de son solde disponible alors qu'elle devait encore de l'argent à
        // des clients. On bloque désormais dès la création de la demande ;
        // l'admin garde la possibilité de passer outre via forcePay au
        // moment de l'approbation si la situation le justifie.
        $blocked = $this->calculateBlockedBalance($wallet);
        if (($available - $amount) < $blocked) {
            throw new \RuntimeException(sprintf(
                'Solde insuffisant pour couvrir les remboursements clients en attente et les billets non validés. Disponible: %.2f XAF, bloqué: %.2f XAF, demandé: %.2f XAF.',
                $available,
                $blocked,
                $amount
            ));
        }

        $newAvailable = round($available - $amount, 2);
        $newReserved = round((float) $wallet->getReservedBalance() + $amount, 2);

        $wallet->setAvailableBalance((string) $newAvailable);
        $wallet->setReservedBalance((string) $newReserved);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_WITHDRAWAL_HOLD);
        $tx->setAmount((string) $amount);
        $tx->setBalanceAfter((string) $newAvailable);
        $tx->setWithdrawalRequest($withdrawal);
        $tx->setDescription('Fonds réservés pour une demande de retrait');

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Finalise une demande de retrait APPROUVÉE par l'admin : les fonds
     * réservés sortent définitivement du portefeuille (versement effectué).
     */
    public function completeWithdrawal(WithdrawalRequest $withdrawal): WalletTransaction
    {
        $agency = $withdrawal->getAgency();
        $wallet = $this->getOrCreateWallet($agency);
        $amount = round((float) $withdrawal->getAmount(), 2);

        $newReserved = max(0.0, round((float) $wallet->getReservedBalance() - $amount, 2));
        $newTotalWithdrawn = round((float) $wallet->getTotalWithdrawn() + $amount, 2);

        $wallet->setReservedBalance((string) $newReserved);
        $wallet->setTotalWithdrawn((string) $newTotalWithdrawn);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED);
        $tx->setAmount((string) $amount);
        $tx->setBalanceAfter((string) $wallet->getAvailableBalance());
        $tx->setWithdrawalRequest($withdrawal);
        $tx->setDescription('Retrait approuvé et versé à l\'agence');

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Libère les fonds réservés d'une demande de retrait REJETÉE par l'admin :
     * ils reviennent dans le solde disponible de l'agence.
     */
    public function releaseWithdrawal(WithdrawalRequest $withdrawal): WalletTransaction
    {
        $agency = $withdrawal->getAgency();
        $wallet = $this->getOrCreateWallet($agency);
        $amount = round((float) $withdrawal->getAmount(), 2);

        $newReserved = max(0.0, round((float) $wallet->getReservedBalance() - $amount, 2));
        $newAvailable = round((float) $wallet->getAvailableBalance() + $amount, 2);

        $wallet->setReservedBalance((string) $newReserved);
        $wallet->setAvailableBalance((string) $newAvailable);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_CREDIT);
        $tx->setSource(WalletTransaction::SOURCE_WITHDRAWAL_RELEASED);
        $tx->setAmount((string) $amount);
        $tx->setBalanceAfter((string) $newAvailable);
        $tx->setWithdrawalRequest($withdrawal);
        $tx->setDescription('Demande de retrait rejetée — fonds libérés');

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Check if an agency can safely process a withdrawal considering pending refunds.
     * 
     * @param WithdrawalRequest $withdrawal The withdrawal request to check
     * @return array {solvent: bool, message: string, remainingBalance: float, totalPendingRefunds: float}
     * @throws \RuntimeException If refund request repository is not available
     */
    public function checkWithdrawalSolvency(WithdrawalRequest $withdrawal): array
    {
        if (!$this->refundRequestRepository) {
            throw new \RuntimeException('RefundRequestRepository is required for solvency check.');
        }

        $agency = $withdrawal->getAgency();
        if (!$agency) {
            return [
                'solvent' => false,
                'message' => 'No agency associated with this withdrawal request.',
                'remainingBalance' => 0.0,
                'totalPendingRefunds' => 0.0,
            ];
        }

        $wallet = $this->getOrCreateWallet($agency);
        $withdrawalAmount = round((float) $withdrawal->getAmount(), 2);

        $currentBalance = round((float) $wallet->getAvailableBalance(), 2);
        $reservedBalance = round((float) $wallet->getReservedBalance(), 2);
        $totalAvailable = round($currentBalance + $reservedBalance, 2);

        $balanceAfterWithdrawal = round($totalAvailable - $withdrawalAmount, 2);

        // 👈 UNIFIÉ avec calculateBlockedBalance() : avant, ce contrôle ne
        // comptait que les remboursements clients en attente et ignorait la
        // valeur des billets non validés/embarqués, alors que ce second
        // risque EST comptabilisé dans le "blocked" affiché au dashboard
        // (AdminWalletController). Les deux définitions de "solde sûr à
        // retirer" divergeaient ; il n'y en a plus qu'une désormais.
        $totalBlocked = $this->calculateBlockedBalance($wallet);
        // Conservé pour rétro-compatibilité de l'API (affichage détaillé)
        $totalPendingRefunds = $this->refundRequestRepository->getPendingRefundsAmountForAgency($agency);

        $solvent = $balanceAfterWithdrawal >= $totalBlocked;

        if ($solvent) {
            return [
                'solvent' => true,
                'message' => 'Agency can safely cover pending refunds and unvalidated tickets after this withdrawal.',
                'remainingBalance' => $balanceAfterWithdrawal,
                'totalPendingRefunds' => $totalPendingRefunds,
                'totalBlocked' => $totalBlocked,
            ];
        } else {
            $shortfall = round($totalBlocked - $balanceAfterWithdrawal, 2);
            return [
                'solvent' => false,
                'message' => sprintf(
                    'Financial risk: After withdrawal of %.2f, agency will have %.2f but owes %.2f (pending refunds + unvalidated tickets). Shortfall: %.2f',
                    $withdrawalAmount,
                    $balanceAfterWithdrawal,
                    $totalBlocked,
                    $shortfall
                ),
                'remainingBalance' => $balanceAfterWithdrawal,
                'totalPendingRefunds' => $totalPendingRefunds,
                'totalBlocked' => $totalBlocked,
            ];
        }
    }

    /**
     * Get the total pending refund amount for an agency
     */
    public function getPendingRefundAmountForAgency(Agency $agency): float
    {
        if (!$this->refundRequestRepository) {
            return 0.0;
        }
        return $this->refundRequestRepository->getPendingRefundsAmountForAgency($agency);
    }

    /**
     * Set the refund request repository (for dependency injection)
     */
    public function setRefundRequestRepository(RefundRequestRepository $repository): void
    {
        $this->refundRequestRepository = $repository;
    }

    /**
     * Set the ticket repository (for dependency injection)
     */
    public function setTicketRepository(TicketRepository $repository): void
    {
        $this->ticketRepository = $repository;
    }

    /**
     * Set the withdrawal request repository (for dependency injection)
     */
    public function setWithdrawalRequestRepository(WithdrawalRequestRepository $repository): void
    {
        $this->withdrawalRequestRepository = $repository;
    }

    /**
     * Calculate the blocked balance for an agency wallet.
     * Blocked Balance = (Sum of pending customer refund requests) + (Total value of unvalidated ticket reservations)
     * 
     * @param Wallet $wallet The wallet to calculate blocked balance for
     * @return float The blocked balance amount
     */
    public function calculateBlockedBalance(Wallet $wallet): float
    {
        $agency = $wallet->getAgency();
        if (!$agency) {
            return 0.0;
        }

        $blockedAmount = 0.0;

        // 1. Sum of pending customer refund requests
        if ($this->refundRequestRepository) {
            $pendingRefundsAmount = $this->refundRequestRepository->getPendingRefundsAmountForAgency($agency);
            $blockedAmount += $pendingRefundsAmount;
        }

        // 2. Total value of ticket reservations where passengers have NOT been validated as embarked/boarded
        if ($this->ticketRepository) {
            $unvalidatedTicketsAmount = $this->ticketRepository->getUnvalidatedTicketsAmountForAgency($agency);
            $blockedAmount += $unvalidatedTicketsAmount;
        }

        return round($blockedAmount, 2);
    }

    /**
     * Manually credit an agency wallet with full audit trail.
     * 
     * @param Wallet $wallet The wallet to credit
     * @param float $amount The amount to credit
     * @param User $admin The admin performing the action
     * @param string $reason The justification/reason for the credit
     * @return WalletTransaction The created transaction
     */
    public function creditWalletManually(Wallet $wallet, float $amount, User $admin, string $reason): WalletTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        $oldBalance = (float) $wallet->getAvailableBalance();
        $newBalance = round($oldBalance + $amount, 2);
        
        $wallet->setAvailableBalance((string) $newBalance);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_CREDIT);
        $tx->setSource(WalletTransaction::SOURCE_ADMIN_CREDIT);
        $tx->setAmount((string) $amount);
        $tx->setBalanceAfter((string) $newBalance);
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason);
        $tx->setDescription(sprintf('Crédit manuel par admin: %s (ID: %d)', $reason, $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Manually debit an agency wallet with full audit trail.
     * 
     * @param Wallet $wallet The wallet to debit
     * @param float $amount The amount to debit
     * @param User $admin The admin performing the action
     * @param string $reason The justification/reason for the debit
     * @return WalletTransaction The created transaction
     * @throws \RuntimeException If insufficient funds
     */
    public function debitWalletManually(Wallet $wallet, float $amount, User $admin, string $reason): WalletTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        $availableBalance = (float) $wallet->getAvailableBalance();
        if ($amount > $availableBalance) {
            throw new \RuntimeException(sprintf(
                'Insufficient funds. Available: %.2f, Attempted debit: %.2f',
                $availableBalance,
                $amount
            ));
        }

        $newBalance = round($availableBalance - $amount, 2);
        
        $wallet->setAvailableBalance((string) $newBalance);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_ADMIN_DEBIT);
        $tx->setAmount((string) $amount);
        $tx->setBalanceAfter((string) $newBalance);
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason);
        $tx->setDescription(sprintf('Débit manuel par admin: %s (ID: %d)', $reason, $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Freeze an agency wallet.
     * 
     * @param Wallet $wallet The wallet to freeze
     * @param User $admin The admin performing the action
     * @param string $reason Optional reason for freezing
     */
    public function freezeWallet(Wallet $wallet, User $admin, ?string $reason = null): void
    {
        $wallet->freeze($admin);
        
        // Create audit transaction
        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_WALLET_FREEZE);
        $tx->setAmount('0.00');
        $tx->setBalanceAfter($wallet->getAvailableBalance());
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason ?? 'Portefeuille gelé par administrateur');
        $tx->setDescription(sprintf('Portefeuille gelé par admin ID: %d', $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);
    }

    /**
     * Unfreeze an agency wallet.
     * 
     * @param Wallet $wallet The wallet to unfreeze
     * @param User $admin The admin performing the action
     * @param string $reason Optional reason for unfreezing
     */
    public function unfreezeWallet(Wallet $wallet, User $admin, ?string $reason = null): void
    {
        $wallet->unfreeze();
        
        // Create audit transaction
        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_CREDIT);
        $tx->setSource(WalletTransaction::SOURCE_WALLET_UNFREEZE);
        $tx->setAmount('0.00');
        $tx->setBalanceAfter($wallet->getAvailableBalance());
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason ?? 'Portefeuille dégélé par administrateur');
        $tx->setDescription(sprintf('Portefeuille dégélé par admin ID: %d', $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);
    }

    /**
     * Get wallet summary with all three balances (available, reserved, blocked)
     * 
     * @param Wallet $wallet The wallet to get summary for
     * @return array{available: float, reserved: float, blocked: float, total: float}
     */
    public function getWalletBalanceSummary(Wallet $wallet): array
    {
        $available = (float) $wallet->getAvailableBalance();
        $reserved = (float) $wallet->getReservedBalance();
        $blocked = $this->calculateBlockedBalance($wallet);
        $total = round($available + $reserved, 2);

        return [
            'available' => $available,
            'reserved' => $reserved,
            'blocked' => $blocked,
            'total' => $total,
            'availableForWithdrawal' => max(0, $available - $blocked), // Available minus blocked
        ];
    }
}