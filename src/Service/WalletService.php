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
use Doctrine\DBAL\LockMode;

/**
 * Centralise TOUTES les écritures sur les portefeuilles d'agence et de plateforme.
 *
 * RÈGLE D'OR : Le solde d'un Wallet ne doit JAMAIS être modifié directement dans
 * un contrôleur. Toute mutation passe EXCLUSIVEMENT par ce service qui garantit :
 *  1) L'utilisation de BCMath pour une précision au centime près sans erreur de flottant.
 *  2) La saisie immuable dans le Ledger (WalletTransaction).
 *  3) L'étanchéité du Solde Bloqué (crédité au paiement) et son déblocage vers le Solde
 *     Disponible lors de la validation à l'embarquement du billet.
 */
class WalletService
{
    public const PLATFORM_FEE = 500.00;

    public function __construct(
        private EntityManagerInterface $em,
        private ?RefundRequestRepository $refundRequestRepository = null,
        private ?TicketRepository $ticketRepository = null,
        private ?WithdrawalRequestRepository $withdrawalRequestRepository = null
    ) {}

    /**
     * Capture l'état complet du wallet dans le ledger. balanceAfter reste
     * compatible avec l'ancien schéma et représente le solde disponible.
     */
    /**
     * Affecte exactement le montant net de la réservation aux billets.
     *
     * La division est tronquée à 2 décimales pour tous les billets sauf le
     * dernier, qui reçoit le reliquat. La somme est donc toujours exacte.
     */
    private function ensureTicketSettlementAmounts(Reservation $reservation, string $netAmount): array
    {
        $tickets = $this->em->getRepository(Ticket::class)->findBy(
            ['reservation' => $reservation],
            ['id' => 'ASC']
        );
        if ($tickets === []) {
            return [];
        }

        $allAssigned = true;
        $assignedTotal = '0.00';
        foreach ($tickets as $ticket) {
            if ($ticket->getSettlementAmount() === null) {
                $allAssigned = false;
                break;
            }
            $assignedTotal = bcadd($assignedTotal, $ticket->getSettlementAmount(), 2);
        }

        if ($allAssigned && bccomp($assignedTotal, $netAmount, 2) === 0) {
            return $tickets;
        }

        $count = count($tickets);
        $base = bcdiv($netAmount, (string) $count, 2);
        $running = '0.00';
        foreach ($tickets as $index => $ticket) {
            if ($index === $count - 1) {
                $amount = bcsub($netAmount, $running, 2);
            } else {
                $amount = $base;
                $running = bcadd($running, $amount, 2);
            }
            $ticket->setSettlementAmount($amount);
            $this->em->persist($ticket);
        }

        return $tickets;
    }

    /** Normalise un montant décimal sans utiliser de float pour les calculs financiers. */
    private function money(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('Montant financier invalide.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        return ltrim($whole, '0') === '' ? '0.' . $fraction : ltrim($whole, '0') . '.' . $fraction;
    }

    /**
     * Normalise un montant signe. Utilisé uniquement pour les ajustements
     * financiers où une différence peut être positive (supplément) ou
     * négative (remboursement). Aucun calcul n'est effectué en float.
     */
    private function signedMoney(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('Montant financier signé invalide.');
        }

        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $normalized = $this->money($absolute);
        return ($negative && bccomp($normalized, '0.00', 2) !== 0) ? '-' . $normalized : $normalized;
    }

    private function snapshotTransaction(WalletTransaction $transaction, Wallet $wallet): void
    {
        $transaction->setBalanceAfter($wallet->getAvailableBalance());
        $transaction->setAvailableAfter($wallet->getAvailableBalance());
        $transaction->setBlockedAfter($wallet->getBlockedBalance());
        $transaction->setReservedAfter($wallet->getReservedBalance());
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
     * Crédite le portefeuille suite au paiement CONFIRMÉ d'une réservation.
     *  - La commission plateforme (ex: 500 FCFA) est immédiatement créditée au Solde Disponible de la Plateforme.
     *  - Le montant net de la course est crédité au SOLDE BLOQUÉ (blockedBalance) de l'agence.
     *  - L'argent reste indisponible au retrait jusqu'à la validation du billet à l'embarquement.
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
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;

        $grossAmount = $this->money($reservation->getTotalAmount());
        $platformFee = number_format(self::PLATFORM_FEE, 2, '.', '');
        
        $netAmount = bcsub($grossAmount, $platformFee, 2);
        if (bccomp($netAmount, '0.00', 2) === -1) {
            $netAmount = '0.00';
        }

        // Verrouille la répartition nette au niveau de chaque billet afin que
        // boarding/no-show utilisent exactement les mêmes montants.
        $this->ensureTicketSettlementAmounts($reservation, $netAmount);

        // 1) Crédit au Solde Bloqué de l'agence (fonds indisponibles en attente d'embarquement)
        $newBlocked = bcadd($wallet->getBlockedBalance(), $netAmount, 2);
        $wallet->setBlockedBalance($newBlocked);
        $wallet->touch();

        $creditTx = new WalletTransaction();
        $creditTx->setWallet($wallet);
        $creditTx->setType(WalletTransaction::TYPE_CREDIT);
        $creditTx->setSource(WalletTransaction::SOURCE_RESERVATION_PAYMENT);
        $creditTx->setAmount($netAmount);
        $creditTx->setFeeAmount($platformFee);
        $this->snapshotTransaction($creditTx, $wallet);
        $creditTx->setReservation($reservation);
        $creditTx->setDescription(sprintf('Paiement réservation #%d (net crédité au solde bloqué agence en attente embarquement)', $reservation->getId()));
        $this->em->persist($creditTx);

        // 2) Commission plateforme créditée immédiatement dans le portefeuille de la plateforme
        $platformWallet = $this->getOrCreatePlatformWallet();
        $platformWallet = $this->em->getRepository(Wallet::class)->find($platformWallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $platformWallet;
        $platformBalanceAfter = bcadd($platformWallet->getAvailableBalance(), $platformFee, 2);
        $platformWallet->setAvailableBalance($platformBalanceAfter);
        $platformWallet->touch();

        $platformFeeTx = new WalletTransaction();
        $platformFeeTx->setWallet($platformWallet);
        $platformFeeTx->setType(WalletTransaction::TYPE_CREDIT);
        $platformFeeTx->setSource(WalletTransaction::SOURCE_PLATFORM_FEE);
        $platformFeeTx->setAmount($platformFee);
        $this->snapshotTransaction($platformFeeTx, $platformWallet);
        $platformFeeTx->setReservation($reservation);
        $platformFeeTx->setDescription(sprintf('Commission plateforme réservation #%d', $reservation->getId()));
        $this->em->persist($platformFeeTx);

        $this->em->persist($wallet);
        $this->em->persist($platformWallet);

        return ['credit' => $creditTx, 'fee' => $platformFeeTx];
    }

    /**
     * Valide l'embarquement d'un billet : transfère la valeur nette du billet
     * du Solde Bloqué (blockedBalance) vers le Solde Disponible (availableBalance)
     * et incrémente totalEarned.
     */
    public function processTicketBoarding(Ticket $ticket): ?WalletTransaction
    {
        $reservation = $ticket->getReservation();
        if (!$reservation) {
            return null;
        }

        $trip = $reservation->getTrip();
        $agency = $trip?->getAgency();
        if (!$agency) {
            return null;
        }

        $wallet = $this->getOrCreateWallet($agency);
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;

        // Vérification d'idempotence pour ce billet précis via description/reservation/source
        $existingBoarding = $this->em->getRepository(WalletTransaction::class)->findOneBy([
            'reservation' => $reservation,
            'source' => WalletTransaction::SOURCE_TICKET_BOARDED,
            'description' => sprintf('Embarquement validé billet #%d', $ticket->getId()),
        ]);
        if ($existingBoarding) {
            return $existingBoarding;
        }

        // Le montant du billet est déterminé une seule fois lors de la
        // confirmation du paiement. Cela évite les reliquats de 0.01 FCFA.
        $grossAmount = $this->money($reservation->getTotalAmount());
        $platformFee = number_format(self::PLATFORM_FEE, 2, '.', '');
        $netReservationAmount = bcsub($grossAmount, $platformFee, 2);
        if (bccomp($netReservationAmount, '0.00', 2) === -1) {
            $netReservationAmount = '0.00';
        }
        $this->ensureTicketSettlementAmounts($reservation, $netReservationAmount);
        $ticketNetAmount = $ticket->getSettlementAmount() ?? '0.00';

        // 1. Débit du Solde Bloqué
        $currentBlocked = $wallet->getBlockedBalance();
        // Le billet ne peut être embarqué que si ses fonds sont encore
        // intégralement bloqués. On ne masque jamais une incohérence en
        // ramenant artificiellement le solde à zéro.
        if (bccomp($currentBlocked, $ticketNetAmount, 2) < 0) {
            throw new \RuntimeException(sprintf(
                'Solde bloqué insuffisant pour le billet #%d : %s FCFA disponibles, %s FCFA requis.',
                $ticket->getId(),
                $currentBlocked,
                $ticketNetAmount,
            ));
        }
        $newBlocked = bcsub($currentBlocked, $ticketNetAmount, 2);
        $wallet->setBlockedBalance($newBlocked);

        // 2. Crédit du Solde Disponible
        $currentAvailable = $wallet->getAvailableBalance();
        $newAvailable = bcadd($currentAvailable, $ticketNetAmount, 2);
        $wallet->setAvailableBalance($newAvailable);

        // 3. Incrémentation de totalEarned
        $currentEarned = $wallet->getTotalEarned();
        $newEarned = bcadd($currentEarned, $ticketNetAmount, 2);
        $wallet->setTotalEarned($newEarned);

        $wallet->touch();

        // 4. Ledger Transaction
        // Type comptable = DEBIT : ce mouvement représente un débit du Solde
        // Bloqué de l'agence (même si sa contrepartie crédite le Solde
        // Disponible). C'est la même convention que TICKET_NO_SHOW,
        // WITHDRAWAL_HOLD et REFUND. Un CREDIT ici cassait la réconciliation
        // (transito:finance:reconcile) qui attend systématiquement DEBIT
        // pour cette source.
        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_TICKET_BOARDED);
        $tx->setAmount($ticketNetAmount);
        $this->snapshotTransaction($tx, $wallet);
        $tx->setReservation($reservation);
        $tx->setDescription(sprintf('Embarquement validé billet #%d', $ticket->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Débite le portefeuille suite au remboursement d'une réservation.
     * En cas d'annulation avant embarquement, le montant net est débité prioritairement
     * du Solde Bloqué (blockedBalance).
     * Les frais de plateforme conservés par Transito ne sont JAMAIS remboursés du wallet agence.
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
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;
        $netAmount = $this->money($creditTx->getAmount());

        $blocked = $wallet->getBlockedBalance();
        $available = $wallet->getAvailableBalance();

        // Si la réservation était encore en Solde Bloqué (non encore embarquée)
        if (bccomp($blocked, $netAmount, 2) >= 0) {
            $newBlocked = bcsub($blocked, $netAmount, 2);
            $wallet->setBlockedBalance($newBlocked);
            $balanceAfter = $wallet->getAvailableBalance();
        } else {
            // Si les fonds avaient déjà été débloqués ou solde insuffisant
            $remainingToDebit = bcsub($netAmount, $blocked, 2);
            $wallet->setBlockedBalance('0.00');

            if ($allowNegative) {
                $newAvailable = bcsub($available, $remainingToDebit, 2);
            } else {
                if (bccomp($available, $remainingToDebit, 2) === -1) {
                    throw new \RuntimeException('Solde agence insuffisant pour effectuer ce remboursement.');
                }
                $newAvailable = bcsub($available, $remainingToDebit, 2);
            }
            $wallet->setAvailableBalance($newAvailable);
            $balanceAfter = $newAvailable;
        }

        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_REFUND);
        $tx->setAmount($netAmount);
        $this->snapshotTransaction($tx, $wallet);
        $tx->setReservation($reservation);
        $tx->setDescription(sprintf('Remboursement réservation #%d (%s)', $reservation->getId(), $reason ?? 'Annulation'));

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Applique la différence de prix d'un report au wallet agence.
     * Positive = supplément payé par le client -> solde bloqué.
     * Negative = différence à rembourser -> débit du solde bloqué.
     * Aucun frais plateforme supplémentaire n'est créé : les 500 FCFA ont déjà
     * été encaissés sur la réservation initiale.
     */
    public function applyRescheduleAdjustment(Reservation $reservation, string $difference): ?WalletTransaction
    {
        $difference = $this->signedMoney($difference);
        if (bccomp($difference, '0.00', 2) === 0) {
            return null;
        }

        $trip = $reservation->getTrip();
        $agency = $trip?->getAgency();
        if (!$agency) {
            throw new \RuntimeException('Agence introuvable pour le report.');
        }

        $wallet = $this->getOrCreateWallet($agency);
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;

        $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation], ['id' => 'ASC']);
        $oldNet = $this->money(bcsub((string) $reservation->getTotalAmount(), number_format(self::PLATFORM_FEE, 2, '.', ''), 2));
        $newNet = $this->money(bcadd($oldNet, $difference, 2));
        $this->ensureTicketSettlementAmounts($reservation, $newNet);

        if (bccomp($difference, '0.00', 2) === 1) {
            $wallet->setBlockedBalance(bcadd($wallet->getBlockedBalance(), $difference, 2));
            $tx = new WalletTransaction();
            $tx->setType(WalletTransaction::TYPE_CREDIT);
        } else {
            $debit = bcsub('0.00', $difference, 2);
            if (bccomp($wallet->getBlockedBalance(), $debit, 2) < 0) {
                throw new \RuntimeException('Solde bloqué insuffisant pour le remboursement de la différence de report.');
            }
            $wallet->setBlockedBalance(bcsub($wallet->getBlockedBalance(), $debit, 2));
            $tx = new WalletTransaction();
            $tx->setType(WalletTransaction::TYPE_DEBIT);
        }

        $wallet->touch();
        $ledgerAmount = bccomp($difference, '0.00', 2) < 0 ? bcsub('0.00', $difference, 2) : $difference;
        $tx->setWallet($wallet)
            ->setSource(WalletTransaction::SOURCE_RESCHEDULE_ADJUSTMENT)
            ->setAmount($ledgerAmount)
            ->setReservation($reservation)
            ->setDescription(sprintf('Ajustement financier report réservation #%d (%s)', $reservation->getId(), bccomp($difference, '0.00', 2) > 0 ? 'supplément' : 'remboursement différence'));
        $this->snapshotTransaction($tx, $wallet);
        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Réserve le montant d'un retrait : passe le montant du Solde Disponible au Solde Réservé.
     */
    /**
     * Finalise un billet non présenté après le départ du voyage.
     *
     * Le montant net agence, auparavant bloqué, est définitivement retiré du
     * wallet agence et crédité au wallet plateforme. Les frais de plateforme
     * déjà encaissés ne sont jamais recrédités une seconde fois.
     */
    public function processTicketNoShow(Ticket $ticket): ?WalletTransaction
    {
        $reservation = $ticket->getReservation();
        $trip = $reservation?->getTrip();
        $agency = $trip?->getAgency();
        if (!$reservation || !$trip || !$agency) {
            return null;
        }

        $existing = $this->em->getRepository(WalletTransaction::class)->findOneBy([
            'reservation' => $reservation,
            'source' => WalletTransaction::SOURCE_TICKET_NO_SHOW,
            'description' => sprintf('No-show billet #%d', $ticket->getId()),
        ]);
        if ($existing) {
            return $existing;
        }

        $creditTx = $this->em->getRepository(WalletTransaction::class)->findOneBy([
            'reservation' => $reservation,
            'type' => WalletTransaction::TYPE_CREDIT,
            'source' => WalletTransaction::SOURCE_RESERVATION_PAYMENT,
        ]);
        if (!$creditTx) {
            return null;
        }

        $this->ensureTicketSettlementAmounts($reservation, $creditTx->getAmount());
        $ticketNetAmount = $ticket->getSettlementAmount() ?? '0.00';

        $wallet = $this->getOrCreateWallet($agency);
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;
        if (bccomp($wallet->getBlockedBalance(), $ticketNetAmount, 2) < 0) {
            throw new \RuntimeException(sprintf(
                'Incohérence financière : solde bloqué insuffisant pour le no-show du billet #%d.',
                $ticket->getId()
            ));
        }

        $wallet->setBlockedBalance(bcsub($wallet->getBlockedBalance(), $ticketNetAmount, 2));
        $wallet->touch();

        $agencyTx = new WalletTransaction();
        $agencyTx->setWallet($wallet);
        $agencyTx->setType(WalletTransaction::TYPE_DEBIT);
        $agencyTx->setSource(WalletTransaction::SOURCE_TICKET_NO_SHOW);
        $agencyTx->setAmount($ticketNetAmount);
        $this->snapshotTransaction($agencyTx, $wallet);
        $agencyTx->setReservation($reservation);
        $agencyTx->setDescription(sprintf('No-show billet #%d', $ticket->getId()));
        $this->em->persist($agencyTx);
        $this->em->persist($wallet);

        $platformWallet = $this->getOrCreatePlatformWallet();
        $platformWallet = $this->em->getRepository(Wallet::class)->find($platformWallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $platformWallet;
        $platformAvailable = bcadd($platformWallet->getAvailableBalance(), $ticketNetAmount, 2);
        $platformWallet->setAvailableBalance($platformAvailable);
        $platformWallet->touch();

        $platformTx = new WalletTransaction();
        $platformTx->setWallet($platformWallet);
        $platformTx->setType(WalletTransaction::TYPE_CREDIT);
        $platformTx->setSource(WalletTransaction::SOURCE_NO_SHOW_REVENUE);
        $platformTx->setAmount($ticketNetAmount);
        $this->snapshotTransaction($platformTx, $platformWallet);
        $platformTx->setReservation($reservation);
        $platformTx->setDescription(sprintf('Revenu no-show billet #%d', $ticket->getId()));
        $this->em->persist($platformTx);
        $this->em->persist($platformWallet);

        return $agencyTx;
    }

    public function reserveForWithdrawal(WithdrawalRequest $withdrawal): WalletTransaction
    {
        $agency = $withdrawal->getAgency();
        $wallet = $this->getOrCreateWallet($agency);
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;
        $amount = $this->money($withdrawal->getAmount());
        $available = $wallet->getAvailableBalance();

        if (bccomp($amount, $available, 2) === 1) {
            throw new \RuntimeException('Solde disponible insuffisant pour cette demande de retrait.');
        }

        $newAvailable = bcsub($available, $amount, 2);
        $newReserved = bcadd($wallet->getReservedBalance(), $amount, 2);

        $wallet->setAvailableBalance($newAvailable);
        $wallet->setReservedBalance($newReserved);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_WITHDRAWAL_HOLD);
        $tx->setAmount($amount);
        $this->snapshotTransaction($tx, $wallet);
        $tx->setWithdrawalRequest($withdrawal);
        $tx->setDescription('Fonds réservés pour une demande de retrait');

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Retrait APPROUVÉ par l'admin : déduit le montant du Solde Réservé et incrémente totalWithdrawn.
     */
    public function completeWithdrawal(WithdrawalRequest $withdrawal): WalletTransaction
    {
        $agency = $withdrawal->getAgency();
        $wallet = $this->getOrCreateWallet($agency);
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;
        $amount = $this->money($withdrawal->getAmount());

        $reserved = $wallet->getReservedBalance();
        $newReserved = bcsub($reserved, $amount, 2);
        if (bccomp($newReserved, '0.00', 2) === -1) {
            $newReserved = '0.00';
        }

        $newTotalWithdrawn = bcadd($wallet->getTotalWithdrawn(), $amount, 2);

        $wallet->setReservedBalance($newReserved);
        $wallet->setTotalWithdrawn($newTotalWithdrawn);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED);
        $tx->setAmount($amount);
        $this->snapshotTransaction($tx, $wallet);
        $tx->setWithdrawalRequest($withdrawal);
        $tx->setDescription('Retrait approuvé et versé à l\'agence');

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Retrait REJETÉ par l'admin : remet les fonds du Solde Réservé dans le Solde Disponible.
     */
    public function releaseWithdrawal(WithdrawalRequest $withdrawal): WalletTransaction
    {
        $agency = $withdrawal->getAgency();
        $wallet = $this->getOrCreateWallet($agency);
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;
        $amount = $this->money($withdrawal->getAmount());

        $reserved = $wallet->getReservedBalance();
        $newReserved = bcsub($reserved, $amount, 2);
        if (bccomp($newReserved, '0.00', 2) === -1) {
            $newReserved = '0.00';
        }

        $newAvailable = bcadd($wallet->getAvailableBalance(), $amount, 2);

        $wallet->setReservedBalance($newReserved);
        $wallet->setAvailableBalance($newAvailable);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_CREDIT);
        $tx->setSource(WalletTransaction::SOURCE_WITHDRAWAL_RELEASED);
        $tx->setAmount($amount);
        $this->snapshotTransaction($tx, $wallet);
        $tx->setWithdrawalRequest($withdrawal);
        $tx->setDescription('Demande de retrait rejetée — fonds libérés');

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }


    /**
     * Vérifie qu'un retrait peut être payé sans mettre en péril les
     * remboursements déjà en attente pour l'agence.
     *
     * Le retrait est déjà réservé dans reservedBalance : on ne retire donc
     * pas ce montant une seconde fois du disponible. La réserve de sécurité
     * porte sur le disponible restant après le retrait.
     */
    public function checkWithdrawalSolvency(WithdrawalRequest $withdrawal): array
    {
        $agency = $withdrawal->getAgency();
        if (!$agency) {
            throw new \RuntimeException('Agence introuvable.');
        }

        $wallet = $this->getOrCreateWallet($agency);
        $wallet = $this->em->getRepository(Wallet::class)->find($wallet->getId(), LockMode::PESSIMISTIC_WRITE) ?? $wallet;
        $amount = $this->money($withdrawal->getAmount());

        $pendingRefunds = '0.00';
        if ($this->refundRequestRepository) {
            $pendingRefunds = $this->money($this->refundRequestRepository->getPendingRefundsAmountForAgency($agency));
        }

        $available = $wallet->getAvailableBalance();
        $effective = bcsub($available, $pendingRefunds, 2);
        $solvent = bccomp($effective, $amount, 2) >= 0;

        return [
            'solvent' => $solvent,
            'availableBalance' => $available,
            'pendingRefunds' => $pendingRefunds,
            'withdrawalAmount' => $amount,
            'remainingBalance' => bcsub($effective, $amount, 2),
            'totalPendingRefunds' => $pendingRefunds,
            'message' => $solvent
                ? 'La réserve financière de l’agence reste suffisante.'
                : 'Retrait refusé : les fonds disponibles ne couvrent pas suffisamment les remboursements en attente.',
        ];
    }

    /**
     * Calcule le solde bloqué pour une agence.
     */
    public function calculateBlockedBalance(Wallet $wallet): float
    {
        return (float) $wallet->getBlockedBalance();
    }

    /**
     * Crédit manuel par administrateur.
     */
    public function creditWalletManually(Wallet $wallet, float $amount, User $admin, string $reason): WalletTransaction
    {
        $formattedAmount = number_format($amount, 2, '.', '');
        if (bccomp($formattedAmount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Le montant du crédit doit être positif.');
        }

        $oldBalance = $wallet->getAvailableBalance();
        $newBalance = bcadd($oldBalance, $formattedAmount, 2);
        
        $wallet->setAvailableBalance($newBalance);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_CREDIT);
        $tx->setSource(WalletTransaction::SOURCE_ADMIN_CREDIT);
        $tx->setAmount($formattedAmount);
        $this->snapshotTransaction($tx, $wallet);
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason);
        $tx->setDescription(sprintf('Crédit manuel par admin: %s (ID: %d)', $reason, $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Débit manuel par administrateur.
     */
    public function debitWalletManually(Wallet $wallet, float $amount, User $admin, string $reason): WalletTransaction
    {
        $formattedAmount = number_format($amount, 2, '.', '');
        if (bccomp($formattedAmount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Le montant du débit doit être positif.');
        }

        $availableBalance = $wallet->getAvailableBalance();
        if (bccomp($formattedAmount, $availableBalance, 2) === 1) {
            throw new \RuntimeException(sprintf('Fonds insuffisants. Disponible: %s, Tentative: %s', $availableBalance, $formattedAmount));
        }

        $newBalance = bcsub($availableBalance, $formattedAmount, 2);
        $wallet->setAvailableBalance($newBalance);
        $wallet->touch();

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_ADMIN_DEBIT);
        $tx->setAmount($formattedAmount);
        $this->snapshotTransaction($tx, $wallet);
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason);
        $tx->setDescription(sprintf('Débit manuel par admin: %s (ID: %d)', $reason, $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);

        return $tx;
    }

    /**
     * Gel du portefeuille.
     */
    public function freezeWallet(Wallet $wallet, User $admin, ?string $reason = null): void
    {
        $wallet->freeze($admin);
        
        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_DEBIT);
        $tx->setSource(WalletTransaction::SOURCE_WALLET_FREEZE);
        $tx->setAmount('0.00');
        $this->snapshotTransaction($tx, $wallet);
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason ?? 'Portefeuille gelé par administrateur');
        $tx->setDescription(sprintf('Portefeuille gelé par admin ID: %d', $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);
    }

    /**
     * Dégel du portefeuille.
     */
    public function unfreezeWallet(Wallet $wallet, User $admin, ?string $reason = null): void
    {
        $wallet->unfreeze();
        
        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType(WalletTransaction::TYPE_CREDIT);
        $tx->setSource(WalletTransaction::SOURCE_WALLET_UNFREEZE);
        $tx->setAmount('0.00');
        $this->snapshotTransaction($tx, $wallet);
        $tx->setAdmin($admin);
        $tx->setAdminReason($reason ?? 'Portefeuille dégelé par administrateur');
        $tx->setDescription(sprintf('Portefeuille dégelé par admin ID: %d', $admin->getId()));

        $this->em->persist($wallet);
        $this->em->persist($tx);
    }


    /**
     * Synthèse complète des soldes du portefeuille.
     */
    public function getWalletBalanceSummary(Wallet $wallet): array
    {
        $available = (float) $wallet->getAvailableBalance();
        $reserved = (float) $wallet->getReservedBalance();
        $blocked = (float) $wallet->getBlockedBalance();
        $total = (float) $wallet->getTotalBalance();

        return [
            'available' => $available,
            'reserved' => $reserved,
            'blocked' => $blocked,
            'total' => $total,
            'availableForWithdrawal' => $available, // <-- ADDED KEY
            'totalEarned' => (float) $wallet->getTotalEarned(),
            'totalWithdrawn' => (float) $wallet->getTotalWithdrawn(),
        ];
    }

    public function setRefundRequestRepository(RefundRequestRepository $refundRequestRepository): self
    {
        $this->refundRequestRepository = $refundRequestRepository;

        return $this;
    }
}