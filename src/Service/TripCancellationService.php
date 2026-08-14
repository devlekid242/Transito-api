<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\PaymentLog;
use App\Entity\RefundRequest;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralise l'annulation d'un voyage par l'agence.
 *
 * Règle métier : une annulation de voyage avant départ invalide les billets,
 * libère les places et crée une demande de remboursement NET (prix - 500 FCFA)
 * pour chaque réservation réellement payée. Le remboursement reste soumis à
 * validation administrative, conformément au modèle Transito.
 */
final class TripCancellationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private NotificationBroadcastService $notificationBroadcaster,
        private AdminNotificationService $adminNotificationService,
        private DomainStateTransitionService $stateTransitions,
    ) {}

    /**
     * @return array{trip: Trip, cancelledReservations: int, refundRequests: int, refundedAmount: string}
     */
    public function cancel(Trip $trip, User $actor, ?string $reason = null): array
    {
        $connection = $this->em->getConnection();
        $startedHere = !$connection->isTransactionActive();
        if ($startedHere) {
            $connection->beginTransaction();
        }

        try {
            /** @var Trip|null $lockedTrip */
            $lockedTrip = $this->em->getRepository(Trip::class)->find(
                $trip->getId(),
                LockMode::PESSIMISTIC_WRITE
            );
            if (!$lockedTrip) {
                throw new \RuntimeException('Voyage introuvable.');
            }

            if ($lockedTrip->getStatus() === 'annule') {
                throw new \RuntimeException('Ce voyage est déjà annulé.');
            }

            $now = new \DateTimeImmutable();
            if ($lockedTrip->getDepartureTime() && $lockedTrip->getDepartureTime() <= $now) {
                throw new \RuntimeException('Impossible d\'annuler un voyage dont le départ est déjà atteint.');
            }

            $reservations = $this->em->getRepository(Reservation::class)
                ->createQueryBuilder('r')
                ->andWhere('r.trip = :trip')
                ->setParameter('trip', $lockedTrip)
                ->getQuery()
                ->getResult();

            $paidReservations = [];
            $pendingNotifications = [];
            $activeTicketCount = 0;
            $refundRequests = 0;
            $refundableTotal = '0.00';

            foreach ($reservations as $reservation) {
                /** @var Reservation $reservation */
                $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
                foreach ($tickets as $ticket) {
                    if ($ticket->getStatus() === 'embarque') {
                        throw new \RuntimeException(sprintf(
                            'Impossible d\'annuler le voyage : le billet #%d a déjà été embarqué.',
                            $ticket->getId()
                        ));
                    }
                    if ($ticket->getStatus() !== 'annule') {
                        $activeTicketCount++;
                    }
                }

                if ($reservation->getPaymentStatus() === 'paye') {
                    $paidReservations[] = $reservation;
                    $net = bcsub(
                        (string) $reservation->getTotalAmount(),
                        number_format(WalletService::PLATFORM_FEE, 2, '.', ''),
                        2
                    );
                    if (bccomp($net, '0.00', 2) < 0) {
                        $net = '0.00';
                    }
                    $refundableTotal = bcadd($refundableTotal, $net, 2);
                }
            }

            $this->stateTransitions->transitionTrip($lockedTrip, 'annule');
            $lockedTrip->setSeatsReserved(0);
            $this->em->persist($lockedTrip);

            foreach ($reservations as $reservation) {
                /** @var Reservation $reservation */
                if ($reservation->getPaymentStatus() !== 'paye') {
                    // Une réservation non payée n'a pas de fonds à rembourser.
                    $this->stateTransitions->transitionReservationPayment($reservation, 'annule');
                }

                $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
                foreach ($tickets as $ticket) {
                    if ($ticket->getStatus() !== 'embarque') {
                        $this->stateTransitions->transitionTicket($ticket, 'annule');
                        $ticket->setQrCodeToken(null);
                        $this->em->persist($ticket);
                    }
                }

                if ($reservation->getPaymentStatus() === 'paye') {
                    $reasonText = trim((string)($reason ?: 'Voyage annulé par l\'agence'));
                    $existing = $this->em->getRepository(RefundRequest::class)->findOneBy([
                        'reservation' => $reservation,
                        'status' => RefundRequest::STATUS_PENDING,
                    ]);

                    if (!$existing) {
                        $refundAmount = bcsub(
                            (string) $reservation->getTotalAmount(),
                            number_format(WalletService::PLATFORM_FEE, 2, '.', ''),
                            2
                        );
                        if (bccomp($refundAmount, '0.00', 2) < 0) {
                            $refundAmount = '0.00';
                        }

                        $refund = new RefundRequest();
                        $refund->setAgency($lockedTrip->getAgency());
                        $refund->setReservation($reservation);
                        $refund->setRequestedBy($reservation->getUser() ?? $actor);
                        $refund->setRequestedAmount($refundAmount);
                        $refund->setReason($reasonText);
                        $this->em->persist($refund);
                        $refundRequests++;

                        $paymentLog = new PaymentLog();
                        $paymentLog->setReservation($reservation);
                        $paymentLog->setOperator($reservation->getPaymentMethod() ?: 'N/A');
                        $paymentLog->setReference(uniqid('trip_cancel_refund_', true));
                        $paymentLog->setAmount($refundAmount);
                        $paymentLog->setStatus('REFUND_PENDING');
                        $paymentLog->setRawResponse(json_encode([
                            'type' => 'trip_cancellation_refund_request',
                            'trip_id' => $lockedTrip->getId(),
                            'reason' => $reasonText,
                            'requested_at' => $now->format('c'),
                            'requested_by_user_id' => $actor->getId(),
                        ], JSON_THROW_ON_ERROR));
                        $this->em->persist($paymentLog);
                    }

                    $this->stateTransitions->transitionReservationPayment($reservation, 'annule');
                }

                $this->em->persist($reservation);

                if ($reservation->getUser()) {
                    $notification = new Notification();
                    $notification->setRecipientType('user')
                        ->setRecipientId($reservation->getUser()->getId())
                        ->setTitle('Voyage annulé')
                        ->setContent(sprintf(
                            'Votre voyage %s → %s du %s a été annulé par l\'agence. Votre demande de remboursement de %s FCFA est transmise à notre équipe financière.',
                            $lockedTrip->getDepartureCity() ?? '',
                            $lockedTrip->getArrivalCity() ?? '',
                            $lockedTrip->getDepartureTime()?->format('d/m/Y à H:i') ?? '',
                            $this->netReservationAmount($reservation)
                        ))
                        ->setCategory('TRIP');
                    $this->em->persist($notification);
                    $pendingNotifications[] = $notification;
                }
            }

            $this->em->flush();

            $adminNotificationPayload = [
                'title' => 'Voyage annulé par une agence',
                'content' => sprintf(
                    'Le voyage #%d %s → %s a été annulé. %d demande(s) de remboursement nécessitent un traitement administratif.',
                    $lockedTrip->getId(),
                    $lockedTrip->getDepartureCity() ?? '',
                    $lockedTrip->getArrivalCity() ?? '',
                    $refundRequests
                ),
                'category' => 'TRIP',
                'data' => [
                    'tripId' => $lockedTrip->getId(),
                    'agencyId' => $lockedTrip->getAgency()?->getId(),
                    'refundRequests' => $refundRequests,
                ],
            ];

            if ($startedHere) {
                $connection->commit();

                foreach ($pendingNotifications as $notification) {
                    $this->notificationBroadcaster->broadcast($notification);
                }

                // Les notifications admin ne sont persistées/broadcastées qu'après
                // le commit métier, afin qu'une erreur de transaction ne puisse
                // annoncer une annulation qui n'a finalement pas été enregistrée.
                $this->adminNotificationService->notifyEvent(
                    $adminNotificationPayload['title'],
                    $adminNotificationPayload['content'],
                    $adminNotificationPayload['category'],
                    $adminNotificationPayload['data']
                );
            }

            return [
                'trip' => $lockedTrip,
                'cancelledReservations' => count($reservations),
                'refundRequests' => $refundRequests,
                'refundedAmount' => $refundableTotal,
            ];
        } catch (\Throwable $e) {
            if ($startedHere && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    private function netReservationAmount(Reservation $reservation): string
    {
        $gross = number_format((float) $reservation->getTotalAmount(), 2, '.', '');
        $fee = number_format(WalletService::PLATFORM_FEE, 2, '.', '');
        $net = bcsub($gross, $fee, 2);
        return bccomp($net, '0.00', 2) < 0 ? '0.00' : $net;
    }
}
