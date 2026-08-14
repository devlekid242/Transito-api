<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\RefundRequest;
use App\Entity\Ticket;
use App\Entity\WalletTransaction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Audit métier transversal, en lecture seule.
 *
 * Contrairement à la réconciliation d'un wallet, ce service vérifie la chaîne
 * complète : réservation -> paiement -> ledger -> ticket -> embarquement /
 * no-show -> remboursement. Il ne corrige jamais automatiquement une anomalie.
 */
final class FinancialWorkflowAuditService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function audit(): array
    {
        $issues = [];
        $checkedReservations = 0;
        $checkedTickets = 0;
        $checkedPayments = 0;

        $reservations = $this->em->getRepository(Reservation::class)->findAll();
        $paymentRepo = $this->em->getRepository(PaymentLog::class);
        $txRepo = $this->em->getRepository(WalletTransaction::class);
        $refundRepo = $this->em->getRepository(RefundRequest::class);
        $ticketRepo = $this->em->getRepository(Ticket::class);

        foreach ($reservations as $reservation) {
            ++$checkedReservations;
            $reservationId = $reservation->getId();
            $payments = $paymentRepo->findBy(['reservation' => $reservation]);
            $successPayments = array_values(array_filter($payments, static fn(PaymentLog $p) => $p->getStatus() === 'SUCCESS'));
            ++$checkedPayments;

            if ($reservation->getPaymentStatus() === 'paye') {
                if (count($successPayments) !== 1) {
                    $issues[] = $this->issue('PAYMENT_CHAIN', $reservationId, sprintf(
                        'Réservation payée mais %d paiement(s) SUCCESS trouvé(s), 1 attendu.', count($successPayments)
                    ));
                } elseif (bccomp((string) $successPayments[0]->getAmount(), (string) $reservation->getTotalAmount(), 2) !== 0) {
                    $issues[] = $this->issue('PAYMENT_AMOUNT', $reservationId, sprintf(
                        'Montant SUCCESS %s différent du total réservation %s.',
                        $successPayments[0]->getAmount(), $reservation->getTotalAmount()
                    ));
                }
            }

            $creditRows = $txRepo->findBy([
                'reservation' => $reservation,
                'source' => WalletTransaction::SOURCE_RESERVATION_PAYMENT,
                'type' => WalletTransaction::TYPE_CREDIT,
            ]);
            $feeRows = $txRepo->findBy([
                'reservation' => $reservation,
                'source' => WalletTransaction::SOURCE_PLATFORM_FEE,
                'type' => WalletTransaction::TYPE_CREDIT,
            ]);

            if ($reservation->getPaymentStatus() === 'paye') {
                $expectedNet = bcsub((string) $reservation->getTotalAmount(), number_format(WalletService::PLATFORM_FEE, 2, '.', ''), 2);
                if (bccomp($expectedNet, '0.00', 2) < 0) {
                    $expectedNet = '0.00';
                }
                if (count($creditRows) !== 1) {
                    $issues[] = $this->issue('LEDGER_AGENCY_CREDIT', $reservationId, sprintf(
                        'Paiement confirmé : %d crédit(s) agence trouvé(s), 1 attendu.', count($creditRows)
                    ));
                } elseif (bccomp((string) $creditRows[0]->getAmount(), $expectedNet, 2) !== 0) {
                    $issues[] = $this->issue('LEDGER_AGENCY_AMOUNT', $reservationId, sprintf(
                        'Crédit agence %s différent du net attendu %s.', $creditRows[0]->getAmount(), $expectedNet
                    ));
                }
                if (count($feeRows) !== 1 || bccomp((string) ($feeRows[0]->getAmount() ?? '0.00'), number_format(WalletService::PLATFORM_FEE, 2, '.', ''), 2) !== 0) {
                    $issues[] = $this->issue('LEDGER_PLATFORM_FEE', $reservationId, 'La commission plateforme de 500 FCFA n\'est pas expliquée exactement une fois.');
                }
            }

            if ($reservation->getPaymentStatus() !== 'paye' && ($creditRows !== [] || $feeRows !== [])) {
                $issues[] = $this->issue('UNPAID_LEDGER', $reservationId, 'Une réservation non payée possède des écritures de paiement dans le ledger.');
            }

            if ($reservation->getPaymentStatus() === 'rembourse') {
                $refunds = $refundRepo->findBy(['reservation' => $reservation]);
                $completed = array_filter($refunds, static fn(RefundRequest $r) => $r->getStatus() === RefundRequest::STATUS_COMPLETED);
                if ($completed === [] && $refunds === []) {
                    $issues[] = $this->issue('REFUND_CHAIN', $reservationId, 'Réservation marquée remboursée sans demande de remboursement.');
                }
            }

            foreach ($ticketRepo->findBy(['reservation' => $reservation]) as $ticket) {
                ++$checkedTickets;
                $boardingRows = $txRepo->findBy([
                    'reservation' => $reservation,
                    'source' => WalletTransaction::SOURCE_TICKET_BOARDED,
                ]);
                $noShowRows = $txRepo->findBy([
                    'reservation' => $reservation,
                    'source' => WalletTransaction::SOURCE_TICKET_NO_SHOW,
                ]);

                $ticketBoarding = array_filter($boardingRows, static fn(WalletTransaction $tx) => str_contains((string) $tx->getDescription(), '#'.$ticket->getId()));
                $ticketNoShow = array_filter($noShowRows, static fn(WalletTransaction $tx) => str_contains((string) $tx->getDescription(), '#'.$ticket->getId()));

                if ($ticket->getStatus() === 'embarque' && count($ticketBoarding) !== 1) {
                    $issues[] = $this->issue('BOARDING_CHAIN', $reservationId, sprintf(
                        'Billet #%d embarqué mais %d mouvement(s) TICKET_BOARDED associé(s).', $ticket->getId(), count($ticketBoarding)
                    ));
                }
                if ($ticket->getStatus() === 'no_show' && count($ticketNoShow) !== 1) {
                    $issues[] = $this->issue('NO_SHOW_CHAIN', $reservationId, sprintf(
                        'Billet #%d NO_SHOW mais %d mouvement(s) TICKET_NO_SHOW associé(s).', $ticket->getId(), count($ticketNoShow)
                    ));
                }
                if ($ticket->getStatus() === 'embarque' && $ticket->getQrCodeToken() === null) {
                    // Le QR peut être invalidé après scan selon la politique de sécurité.
                    // Ce n'est donc pas une anomalie comptable.
                }
            }
        }

        $status = $issues === [] ? 'OK' : 'INCONSISTENT';
        return [
            'status' => $status,
            'checkedReservations' => $checkedReservations,
            'checkedTickets' => $checkedTickets,
            'checkedPayments' => $checkedPayments,
            'issueCount' => count($issues),
            'issues' => $issues,
            'auditedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    private function issue(string $code, ?int $reservationId, string $message): array
    {
        return [
            'code' => $code,
            'reservationId' => $reservationId,
            'message' => $message,
        ];
    }
}
