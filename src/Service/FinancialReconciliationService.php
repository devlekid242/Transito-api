<?php

namespace App\Service;

use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Repository\WalletRepository;
use App\Repository\WalletTransactionRepository;
use App\Repository\PaymentLogRepository;

/**
 * Contrôle en lecture seule de l'intégrité des wallets.
 *
 * Les anciens mouvements peuvent ne pas avoir les snapshots des trois poches;
 * dans ce cas ils restent vérifiables sur le solde disponible historique, mais
 * ne sont pas présentés comme une preuve de cohérence blocked/reserved.
 */
final class FinancialReconciliationService
{
    public function __construct(
        private WalletRepository $walletRepository,
        private WalletTransactionRepository $walletTransactionRepository,
        private PaymentLogRepository $paymentLogRepository,
    ) {}

    public function reconcile(?Wallet $wallet = null): array
    {
        $wallets = $wallet ? [$wallet] : $this->walletRepository->findAll();
        $items = [];
        $globalDiscrepancies = [];

        foreach ($wallets as $wallet) {
            // Idem : la dernière transaction est celle avec le plus grand id,
            // pas la plus récente selon createdAt (voir note plus bas).
            $latest = $this->walletTransactionRepository->findOneBy(
                ['wallet' => $wallet],
                ['id' => 'DESC']
            );

            $available = $wallet->getAvailableBalance();
            $blocked = $wallet->getBlockedBalance();
            $reserved = $wallet->getReservedBalance();
            $total = bcadd(bcadd($available, $blocked, 2), $reserved, 2);
            $issues = [];

            // Contrôle de continuité : pour chaque source connue, le delta entre
            // deux snapshots doit correspondre exactement au mouvement attendu.
            // Tri par id, pas par createdAt : l'id auto-incrémenté reflète
            // toujours l'ordre réel d'insertion en base, contrairement à
            // createdAt qui dépend de l'horloge/fuseau horaire du serveur au
            // moment de l'écriture. Un changement de fuseau horaire entre
            // deux transactions (ex: Europe/Paris -> Africa/Brazzaville en
            // cours de test) peut faire apparaître des createdAt dans le
            // désordre alors que l'ordre réel des mouvements est correct —
            // ce qui provoquait de faux positifs sur ce contrôle.
            $snapshotRows = $this->walletTransactionRepository->createQueryBuilder('wt')
                ->andWhere('wt.wallet = :wallet')
                ->andWhere('wt.availableAfter IS NOT NULL')
                ->andWhere('wt.blockedAfter IS NOT NULL')
                ->andWhere('wt.reservedAfter IS NOT NULL')
                ->setParameter('wallet', $wallet)
                ->orderBy('wt.id', 'ASC')
                ->getQuery()
                ->getResult();

            $previous = null;
            foreach ($snapshotRows as $row) {
                $current = [
                    'available' => $row->getAvailableAfter(),
                    'blocked' => $row->getBlockedAfter(),
                    'reserved' => $row->getReservedAfter(),
                ];

                if ($previous !== null && $row->getAmount() !== null) {
                    $amount = $row->getAmount();
                    $actual = [
                        'available' => bcsub($current['available'], $previous['available'], 2),
                        'blocked' => bcsub($current['blocked'], $previous['blocked'], 2),
                        'reserved' => bcsub($current['reserved'], $previous['reserved'], 2),
                    ];
                    $expected = null;
                    $zero = '0.00';
                    switch ($row->getSource()) {
                        case WalletTransaction::SOURCE_RESERVATION_PAYMENT:
                        case WalletTransaction::SOURCE_TICKET_NO_SHOW:
                        case WalletTransaction::SOURCE_REFUND:
                        case WalletTransaction::SOURCE_PLATFORM_FEE:
                        case WalletTransaction::SOURCE_NO_SHOW_REVENUE:
                        case WalletTransaction::SOURCE_TICKET_BOARDED:
                        case WalletTransaction::SOURCE_WITHDRAWAL_HOLD:
                        case WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED:
                        case WalletTransaction::SOURCE_WITHDRAWAL_RELEASED:
                        case WalletTransaction::SOURCE_ADMIN_CREDIT:
                        case WalletTransaction::SOURCE_ADMIN_DEBIT:
                        case WalletTransaction::SOURCE_ADJUSTMENT:
                        case WalletTransaction::SOURCE_WALLET_FREEZE:
                        case WalletTransaction::SOURCE_WALLET_UNFREEZE:
                            $expected = ['available' => $zero, 'blocked' => $zero, 'reserved' => $zero];
                            break;
                    }

                    if ($expected !== null) {
                        switch ($row->getSource()) {
                            case WalletTransaction::SOURCE_RESERVATION_PAYMENT:
                                $expected['blocked'] = $amount;
                                break;
                            case WalletTransaction::SOURCE_PLATFORM_FEE:
                            case WalletTransaction::SOURCE_NO_SHOW_REVENUE:
                                // Ces sources représentent un revenu de la plateforme :
                                // elles créditent le wallet plateforme disponible.
                                if ($wallet->getType() !== Wallet::TYPE_PLATFORM) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (%s) présente sur un wallet agence alors qu\'elle doit appartenir au wallet plateforme.',
                                        $row->getId(), $row->getSource()
                                    );
                                    $expected = null;
                                } else {
                                    $expected['available'] = $amount;
                                }
                                break;
                            case WalletTransaction::SOURCE_ADMIN_CREDIT:
                                $expected['available'] = $amount;
                                break;
                            case WalletTransaction::SOURCE_TICKET_BOARDED:
                                if ($wallet->getType() !== Wallet::TYPE_AGENCY) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (TICKET_BOARDED) présente sur un wallet non agence.',
                                        $row->getId()
                                    );
                                    $expected = null;
                                } else {
                                    $expected['blocked'] = bcsub('0.00', $amount, 2);
                                    $expected['available'] = $amount;
                                }
                                break;
                            case WalletTransaction::SOURCE_TICKET_NO_SHOW:
                                if ($wallet->getType() !== Wallet::TYPE_AGENCY) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (TICKET_NO_SHOW) présente sur un wallet non agence.',
                                        $row->getId()
                                    );
                                    $expected = null;
                                } else {
                                    $expected['blocked'] = bcsub('0.00', $amount, 2);
                                }
                                break;
                            case WalletTransaction::SOURCE_REFUND:
                                // Le remboursement peut débiter blocked ou available.
                                $expected = null;
                                $sum = bcadd(bcadd($actual['available'], $actual['blocked'], 2), $actual['reserved'], 2);
                                if (bccomp($sum, bcsub('0.00', $amount, 2), 2) !== 0) {
                                    $issues[] = sprintf('Transaction #%d : delta global incompatible avec un remboursement de %s.', $row->getId(), $amount);
                                }
                                break;
                            case WalletTransaction::SOURCE_WITHDRAWAL_HOLD:
                                if ($wallet->getType() !== Wallet::TYPE_AGENCY) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (WITHDRAWAL_HOLD) présente sur un wallet non agence.',
                                        $row->getId()
                                    );
                                    $expected = null;
                                } else {
                                    $expected['available'] = bcsub('0.00', $amount, 2);
                                    $expected['reserved'] = $amount;
                                }
                                break;
                            case WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED:
                                if ($wallet->getType() !== Wallet::TYPE_AGENCY) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (WITHDRAWAL_COMPLETED) présente sur un wallet non agence.',
                                        $row->getId()
                                    );
                                    $expected = null;
                                } else {
                                    $expected['reserved'] = bcsub('0.00', $amount, 2);
                                }
                                break;
                            case WalletTransaction::SOURCE_WITHDRAWAL_RELEASED:
                                if ($wallet->getType() !== Wallet::TYPE_AGENCY) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (WITHDRAWAL_RELEASED) présente sur un wallet non agence.',
                                        $row->getId()
                                    );
                                    $expected = null;
                                } else {
                                    $expected['available'] = $amount;
                                    $expected['reserved'] = bcsub('0.00', $amount, 2);
                                }
                                break;
                            case WalletTransaction::SOURCE_ADMIN_DEBIT:
                                $expected['available'] = bcsub('0.00', $amount, 2);
                                break;
                            case WalletTransaction::SOURCE_ADJUSTMENT:
                                if ($row->getAdmin() === null || trim((string) $row->getAdminReason()) === '') {
                                    $issues[] = sprintf('Transaction #%d (ADJUSTMENT) sans administrateur ou justification.', $row->getId());
                                    $expected = null;
                                } else {
                                    $expected['available'] = $row->getType() === WalletTransaction::TYPE_CREDIT
                                        ? $amount
                                        : bcsub('0.00', $amount, 2);
                                }
                                break;
                            case WalletTransaction::SOURCE_RESCHEDULE_ADJUSTMENT:
                                // Un supplément de report crédite le solde bloqué ;
                                // une différence remboursée le débite. Le ledger
                                // stocke toujours un montant positif, le type porte
                                // le sens comptable. Ce mouvement doit toujours
                                // appartenir à un wallet agence.
                                if ($wallet->getType() !== Wallet::TYPE_AGENCY) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (RESCHEDULE_ADJUSTMENT) présente sur un wallet non agence.',
                                        $row->getId()
                                    );
                                    $expected = null;
                                } elseif ($row->getType() === WalletTransaction::TYPE_CREDIT) {
                                    $expected['blocked'] = $amount;
                                } elseif ($row->getType() === WalletTransaction::TYPE_DEBIT) {
                                    $expected['blocked'] = bcsub('0.00', $amount, 2);
                                } else {
                                    $issues[] = sprintf(
                                        'Transaction #%d (RESCHEDULE_ADJUSTMENT) : type financier invalide %s.',
                                        $row->getId(), $row->getType()
                                    );
                                    $expected = null;
                                }
                                break;
                            case WalletTransaction::SOURCE_WALLET_FREEZE:
                            case WalletTransaction::SOURCE_WALLET_UNFREEZE:
                                $expected = ['available' => $zero, 'blocked' => $zero, 'reserved' => $zero];
                                break;
                        }

                        if ($expected !== null) {
                            foreach ($expected as $bucket => $delta) {
                                if (bccomp($actual[$bucket], $delta, 2) !== 0) {
                                    $issues[] = sprintf(
                                        'Transaction #%d (%s) : delta %s attendu %s, constaté %s.',
                                        $row->getId(), $row->getSource(), $bucket, $delta, $actual[$bucket]
                                    );
                                }
                            }
                        }
                    }
                }
                // Chaque mouvement financier non nul doit être explicable par une
                // opération métier identifiable. Les relations reservation/withdrawal
                // et la justification admin constituent la provenance minimale.
                if ($row->getAmount() !== null && bccomp($row->getAmount(), '0.00', 2) !== 0) {
                    $hasBusinessReference = $row->getReservation() !== null
                        || $row->getWithdrawalRequest() !== null
                        || ($row->getAdmin() !== null && trim((string) $row->getAdminReason()) !== '');
                    if (!$hasBusinessReference) {
                        $issues[] = sprintf(
                            'Transaction #%d (%s) sans provenance métier explicite.',
                            $row->getId(), $row->getSource()
                        );
                    }
                }

                // Le type comptable doit être compatible avec la nature du mouvement.
                $expectedType = match ($row->getSource()) {
                    WalletTransaction::SOURCE_RESERVATION_PAYMENT,
                    WalletTransaction::SOURCE_PLATFORM_FEE,
                    WalletTransaction::SOURCE_NO_SHOW_REVENUE,
                    WalletTransaction::SOURCE_WITHDRAWAL_RELEASED => WalletTransaction::TYPE_CREDIT,
                    WalletTransaction::SOURCE_REFUND,
                    WalletTransaction::SOURCE_TICKET_BOARDED,
                    WalletTransaction::SOURCE_TICKET_NO_SHOW,
                    WalletTransaction::SOURCE_WITHDRAWAL_HOLD,
                    WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED => WalletTransaction::TYPE_DEBIT,
                    default => null,
                };
                if ($row->getSource() === WalletTransaction::SOURCE_RESCHEDULE_ADJUSTMENT &&
                    !in_array($row->getType(), [WalletTransaction::TYPE_CREDIT, WalletTransaction::TYPE_DEBIT], true)) {
                    $issues[] = sprintf(
                        'Transaction #%d (RESCHEDULE_ADJUSTMENT) : type financier invalide %s.',
                        $row->getId(), $row->getType()
                    );
                } elseif ($expectedType !== null && $row->getType() !== $expectedType) {
                    $issues[] = sprintf(
                        'Transaction #%d (%s) : type %s inattendu, %s attendu.',
                        $row->getId(), $row->getSource(), $row->getType(), $expectedType
                    );
                }

                $previous = $current;
            }

            foreach ([
                'availableBalance' => $available,
                'blockedBalance' => $blocked,
                'reservedBalance' => $reserved,
                'totalEarned' => $wallet->getTotalEarned(),
                'totalWithdrawn' => $wallet->getTotalWithdrawn(),
            ] as $field => $value) {
                if (bccomp($value, '0.00', 2) < 0) {
                    $issues[] = sprintf('%s est négatif (%s).', $field, $value);
                }
            }

            $snapshotComplete = $latest
                && $latest->getAvailableAfter() !== null
                && $latest->getBlockedAfter() !== null
                && $latest->getReservedAfter() !== null;

            $deltas = [
                'available' => null,
                'blocked' => null,
                'reserved' => null,
            ];

            if ($snapshotComplete) {
                $deltas['available'] = bcsub($available, $latest->getAvailableAfter(), 2);
                $deltas['blocked'] = bcsub($blocked, $latest->getBlockedAfter(), 2);
                $deltas['reserved'] = bcsub($reserved, $latest->getReservedAfter(), 2);
                foreach ($deltas as $bucket => $delta) {
                    if (bccomp($delta, '0.00', 2) !== 0) {
                        $issues[] = sprintf(
                            'Écart %s entre le wallet et le dernier snapshot ledger: %s FCFA.',
                            $bucket, $delta
                        );
                    }
                }
            } elseif ($latest) {
                // Compatibilité avec les écritures historiques.
                $delta = bcsub($available, $latest->getBalanceAfter() ?? '0.00', 2);
                if (bccomp($delta, '0.00', 2) !== 0) {
                    $issues[] = sprintf(
                        'Solde disponible différent du dernier snapshot legacy ledger (%s FCFA).',
                        $delta
                    );
                }
            }

            // Contrôle des cumuls historiques : ils doivent être explicables
            // par le ledger et ne jamais dépasser les mouvements correspondants.
            $earnedFromLedger = '0.00';
            $withdrawnFromLedger = '0.00';
            foreach ($snapshotRows as $row) {
                $amount = $row->getAmount() ?? '0.00';
                if ($row->getSource() === WalletTransaction::SOURCE_TICKET_BOARDED) {
                    $earnedFromLedger = bcadd($earnedFromLedger, $amount, 2);
                }
                if ($row->getSource() === WalletTransaction::SOURCE_WITHDRAWAL_COMPLETED) {
                    $withdrawnFromLedger = bcadd($withdrawnFromLedger, $amount, 2);
                }
                if ($row->getSource() === WalletTransaction::SOURCE_ADMIN_CREDIT) {
                    $earnedFromLedger = bcadd($earnedFromLedger, $amount, 2);
                }
            }

            if (bccomp($wallet->getTotalWithdrawn(), $withdrawnFromLedger, 2) !== 0) {
                $issues[] = sprintf(
                    'totalWithdrawn (%s) différent du cumul des retraits terminés du ledger (%s).',
                    $wallet->getTotalWithdrawn(), $withdrawnFromLedger
                );
            }

            // totalEarned est un indicateur historique ; pour une agence il doit
            // au minimum expliquer les embarquements enregistrés. Il ne doit pas
            // être inférieur à ce cumul.
            if ($wallet->getType() === Wallet::TYPE_AGENCY &&
                bccomp($wallet->getTotalEarned(), $earnedFromLedger, 2) < 0) {
                $issues[] = sprintf(
                    'totalEarned (%s) inférieur au cumul des revenus enregistrés dans le ledger (%s).',
                    $wallet->getTotalEarned(), $earnedFromLedger
                );
            }

            // Réconciliation du paiement client : un paiement SUCCESS de réservation
            // doit expliquer exactement le crédit agence net et la commission plateforme.
            // Les PaymentIntent de report sont exclus : ils suivent RESCHEDULE_ADJUSTMENT.
            $successfulPayments = $this->paymentLogRepository->createQueryBuilder('p')
                ->andWhere('p.status = :status')
                ->andWhere('p.reservation IS NOT NULL')
                ->setParameter('status', 'SUCCESS')
                ->getQuery()
                ->getResult();

            foreach ($successfulPayments as $payment) {
                $reservation = $payment->getReservation();
                if (!$reservation) {
                    continue;
                }

                $paymentAmount = $payment->getAmount() ?? '0.00';
                $expectedNet = bcsub($paymentAmount, number_format(WalletService::PLATFORM_FEE, 2, '.', ''), 2);
                if (bccomp($expectedNet, '0.00', 2) < 0) {
                    $issues[] = sprintf('Paiement #%d : montant inférieur aux frais plateforme.', $payment->getId());
                    continue;
                }

                $agencyPaymentRows = $this->walletTransactionRepository->findBy([
                    'reservation' => $reservation,
                    'source' => WalletTransaction::SOURCE_RESERVATION_PAYMENT,
                ]);
                $feeRows = $this->walletTransactionRepository->findBy([
                    'reservation' => $reservation,
                    'source' => WalletTransaction::SOURCE_PLATFORM_FEE,
                ]);

                if ($wallet->getType() === Wallet::TYPE_AGENCY) {
                    $agencyRowsForWallet = array_filter($agencyPaymentRows, static fn(WalletTransaction $tx) => $tx->getWallet()?->getId() === $wallet->getId());
                    $agencyRowsForWallet = array_values($agencyRowsForWallet);
                    if (count($agencyRowsForWallet) > 1) {
                        $issues[] = sprintf('Paiement #%d / réservation #%d : double crédit agence détecté.', $payment->getId(), $reservation->getId());
                    } elseif (count($agencyRowsForWallet) === 0 && $reservation->getTrip()?->getAgency()?->getId() === $wallet->getAgency()?->getId()) {
                        $issues[] = sprintf('Paiement #%d / réservation #%d : crédit agence net absent.', $payment->getId(), $reservation->getId());
                    } elseif (count($agencyRowsForWallet) === 1 && bccomp($agencyRowsForWallet[0]->getAmount() ?? '0.00', $expectedNet, 2) !== 0) {
                        $issues[] = sprintf('Paiement #%d / réservation #%d : crédit agence %s, attendu %s.', $payment->getId(), $reservation->getId(), $agencyRowsForWallet[0]->getAmount(), $expectedNet);
                    }
                }

                if ($wallet->getType() === Wallet::TYPE_PLATFORM) {
                    $feeRowsForWallet = array_filter($feeRows, static fn(WalletTransaction $tx) => $tx->getWallet()?->getId() === $wallet->getId());
                    $feeRowsForWallet = array_values($feeRowsForWallet);
                    if (count($feeRowsForWallet) > 1) {
                        $issues[] = sprintf('Paiement #%d / réservation #%d : double commission plateforme détectée.', $payment->getId(), $reservation->getId());
                    } elseif (count($feeRowsForWallet) === 0) {
                        $issues[] = sprintf('Paiement #%d / réservation #%d : commission plateforme absente.', $payment->getId(), $reservation->getId());
                    } elseif (bccomp($feeRowsForWallet[0]->getAmount() ?? '0.00', number_format(WalletService::PLATFORM_FEE, 2, '.', ''), 2) !== 0) {
                        $issues[] = sprintf('Paiement #%d / réservation #%d : commission %s, attendu %s.', $payment->getId(), $reservation->getId(), $feeRowsForWallet[0]->getAmount(), number_format(WalletService::PLATFORM_FEE, 2, '.', ''));
                    }
                }
            }

            $status = $issues === [] ? 'OK' : 'INCONSISTENT';
            if ($status !== 'OK') {
                $globalDiscrepancies[] = [
                    'walletId' => $wallet->getId(),
                    'agencyId' => $wallet->getAgency()?->getId(),
                    'issues' => $issues,
                ];
            }

            $items[] = [
                'walletId' => $wallet->getId(),
                'type' => $wallet->getType(),
                'agencyId' => $wallet->getAgency()?->getId(),
                'agencyName' => $wallet->getAgency()?->getName(),
                'balances' => [
                    'available' => $available,
                    'blocked' => $blocked,
                    'reserved' => $reserved,
                    'total' => $total,
                    'totalEarned' => $wallet->getTotalEarned(),
                    'totalWithdrawn' => $wallet->getTotalWithdrawn(),
                ],
                'ledger' => [
                    'lastTransactionId' => $latest?->getId(),
                    'snapshotComplete' => (bool) $snapshotComplete,
                    'lastAvailableAfter' => $latest?->getAvailableAfter(),
                    'lastBlockedAfter' => $latest?->getBlockedAfter(),
                    'lastReservedAfter' => $latest?->getReservedAfter(),
                    'legacyBalanceAfter' => $latest?->getBalanceAfter(),
                    'deltas' => $deltas,
                ],
                'status' => $status,
                'issues' => $issues,
            ];
        }

        return [
            'status' => $globalDiscrepancies === [] ? 'OK' : 'INCONSISTENT',
            'checkedWallets' => count($items),
            'inconsistentWallets' => count($globalDiscrepancies),
            'wallets' => $items,
            'discrepancies' => $globalDiscrepancies,
            'checkedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}