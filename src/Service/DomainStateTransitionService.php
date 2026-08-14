<?php

namespace App\Service;

use App\Entity\RefundRequest;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\WithdrawalRequest;
use DomainException;

/**
 * Centralise les transitions d'état des objets métier critiques.
 *
 * Les setters des entités restent volontairement permissifs afin de ne pas
 * casser l'hydratation Doctrine. Les changements d'état métier doivent passer
 * par ce service dans les workflows applicatifs.
 */
final class DomainStateTransitionService
{
    private const TRIP = [
        'planifie' => ['planifie', 'embarquement', 'en_route', 'annule'],
        'embarquement' => ['embarquement', 'en_route', 'termine', 'annule'],
        'en_route' => ['en_route', 'termine', 'annule'],
        'termine' => ['termine'],
        'annule' => ['annule'],
    ];

    private const TICKET = [
        'en_attente' => ['en_attente', 'embarque', 'annule', 'no_show'],
        'embarque' => ['embarque'],
        'annule' => ['annule'],
    ];

    private const RESERVATION_PAYMENT = [
        'en_attente' => ['en_attente', 'paye', 'echoue', 'annule'],
        'paye' => ['paye', 'annule', 'rembourse', 'no_show'],
        'echoue' => ['echoue', 'annule'],
        'annule' => ['annule', 'rembourse'],
        'rembourse' => ['rembourse'],
        'no_show' => ['no_show'],
    ];

    private const REFUND = [
        RefundRequest::STATUS_PENDING => [RefundRequest::STATUS_PENDING, RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_REJECTED, RefundRequest::STATUS_COMPLETED],
        RefundRequest::STATUS_APPROVED => [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_COMPLETED],
        RefundRequest::STATUS_REJECTED => [RefundRequest::STATUS_REJECTED],
        RefundRequest::STATUS_COMPLETED => [RefundRequest::STATUS_COMPLETED],
    ];

    private const WITHDRAWAL = [
        'pending' => ['pending', 'approved', 'rejected'],
        'approved' => ['approved'],
        'rejected' => ['rejected'],
    ];

    public function transitionTrip(Trip $trip, string $to): void
    {
        $this->assertTransition('trip', self::TRIP, $trip->getStatus(), $to);
        $trip->setStatus($to);
    }

    public function transitionTicket(Ticket $ticket, string $to): void
    {
        $this->assertTransition('ticket', self::TICKET, $ticket->getStatus(), $to);
        $ticket->setStatus($to);
    }

    public function transitionReservationPayment(Reservation $reservation, string $to): void
    {
        $this->assertTransition('reservation.payment_status', self::RESERVATION_PAYMENT, $reservation->getPaymentStatus(), $to);
        $reservation->setPaymentStatus($to);
    }

    public function transitionRefund(RefundRequest $refund, string $to): void
    {
        $this->assertTransition('refund', self::REFUND, $refund->getStatus(), $to);
        $refund->setStatus($to);
    }

    public function transitionWithdrawal(WithdrawalRequest $withdrawal, string $to): void
    {
        $this->assertTransition('withdrawal', self::WITHDRAWAL, $withdrawal->getStatus(), $to);
        $withdrawal->setStatus($to);
    }

    public function assertTransition(string $aggregate, array $graph, string $from, string $to): void
    {
        if (!isset($graph[$from])) {
            throw new DomainException(sprintf('Etat %s inconnu pour %s.', $from, $aggregate));
        }

        if (!in_array($to, $graph[$from], true)) {
            throw new DomainException(sprintf('Transition interdite pour %s : %s -> %s.', $aggregate, $from, $to));
        }
    }
}
