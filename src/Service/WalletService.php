<?php

namespace App\Service;

use App\Entity\Agency;
use App\Entity\Reservation;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Entity\WithdrawalRequest;
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

    public function __construct(private EntityManagerInterface $em) {}

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
     * Si les fonds correspondants ont déjà été retirés (available insuffisant),
     * le débit est plafonné à ce qui reste disponible et le manque à gagner
     * est noté dans la description — à traiter manuellement par l'admin
     * (ex : compensation sur le prochain versement).
     */
    public function debitForRefund(Reservation $reservation, ?string $reason = null): ?WalletTransaction
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
        $debited = min($available, $netAmount);
        $shortfall = round($netAmount - $debited, 2);

        $newAvailable = round($available - $debited, 2);
        $wallet->setAvailableBalance((string) $newAvailable);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_REFUND);
        $tx->setAmount((string) $debited);
        $tx->setBalanceAfter((string) $newAvailable);
        $tx->setReservation($reservation);

        $description = sprintf('Remboursement réservation #%d (%s)', $reservation->getId(), $reason ?? 'non précisé');
        if ($shortfall > 0) {
            $description .= sprintf(' — manque à gagner %.2f XAF (fonds déjà retirés par l\'agence)', $shortfall);
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
}
