<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\ReservationReschedule;
use App\Entity\Ticket;
use App\Entity\Trip;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class RescheduleService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WalletService $walletService,
        private AuditLogger $auditLogger,
    ) {}

    /** Finalise un report dont le paiement/ajustement financier est déjà sécurisé. */
    public function finalize(ReservationReschedule $adjustment): void
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($adjustment->getReservation()?->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$reservation || $reservation->getPaymentStatus() !== 'paye') {
            throw new RuntimeException('La réservation n’est plus éligible au report.');
        }
        if ($reservation->getRescheduleCount() >= 1) {
            throw new RuntimeException('Cette réservation a déjà été reportée.');
        }

        $fromTrip = $this->em->getRepository(Trip::class)->find($adjustment->getFromTrip()?->getId(), LockMode::PESSIMISTIC_WRITE);
        $toTrip = $this->em->getRepository(Trip::class)->find($adjustment->getToTrip()?->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$fromTrip || !$toTrip || $toTrip->getStatus() !== 'planifie' || $toTrip->getAgency()?->getStatus() !== 'active') {
            throw new RuntimeException('Le voyage cible n’est plus disponible.');
        }

        $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation], ['id' => 'ASC']);
        $tickets = array_values(array_filter($tickets, static fn (Ticket $t) => in_array($t->getStatus(), ['en_attente', 'embarque'], true)));
        if ($tickets === []) {
            throw new RuntimeException('Aucun billet actif à reporter.');
        }

        $seats = $adjustment->getRequestedSeats();
        if (count($seats) !== count($tickets)) {
            throw new RuntimeException('Le nombre de sièges du report est incohérent.');
        }

        $capacity = $toTrip->getBus()?->getCapacity() ?? 0;
        $occupied = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->join('t.reservation', 'r')
            ->andWhere('r.trip = :trip')
            ->andWhere('r.id != :reservation')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->andWhere('t.status IN (:ticketStatuses)')
            ->setParameter('trip', $toTrip)
            ->setParameter('reservation', $reservation->getId())
            ->setParameter('statuses', ['en_attente', 'paye'])
            ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
            ->getQuery()->getResult();
        $occupiedSeats = [];
        foreach ($occupied as $ticket) {
            if ($ticket->getSeatNumber() !== null) {
                $occupiedSeats[(int) $ticket->getSeatNumber()] = true;
            }
        }
        foreach ($seats as $seat) {
            if ($seat < 1 || $seat > $capacity || isset($occupiedSeats[$seat])) {
                throw new RuntimeException(sprintf('Le siège %d n’est plus disponible.', $seat));
            }
        }

        foreach ($tickets as $i => $ticket) {
            $ticket->setSeatNumber((int) $seats[$i]);
            $this->em->persist($ticket);
        }

        $remaining = (int) $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.reservation', 'r')
            ->andWhere('r.trip = :trip')
            ->andWhere('r.id != :reservation')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->andWhere('t.status IN (:ticketStatuses)')
            ->setParameter('trip', $fromTrip)
            ->setParameter('reservation', $reservation->getId())
            ->setParameter('statuses', ['en_attente', 'paye'])
            ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
            ->getQuery()->getSingleScalarResult();
        $fromTrip->setSeatsReserved($remaining);
        $toTrip->setSeatsReserved(count($occupiedSeats) + count($tickets));

        $oldTripId = $fromTrip->getId();
        $reservation->setTrip($toTrip)->incrementRescheduleCount()->setLastRescheduledAt(new \DateTimeImmutable());
        // Le montant de la réservation doit suivre le voyage cible. Sans cette
        // mise à jour, un report moins cher/plus cher aurait un ancien total
        // persistant et les futurs remboursements/boarding utiliseraient une
        // mauvaise valeur. Le montant est déjà sécurisé par l'ajustement
        // financier exécuté avant finalize().
        $reservation->setTotalAmount($adjustment->getNewTotal());

        $history = (new ReservationReschedule())
            ->setReservation($reservation)
            ->setFromTrip($fromTrip)
            ->setToTrip($toTrip)
            ->setOldTotal($adjustment->getOldTotal())
            ->setNewTotal($adjustment->getNewTotal())
            ->setDifference($adjustment->getDifference())
            ->setDirection($adjustment->getDirection())
            ->setStatus(ReservationReschedule::STATUS_COMPLETED)
            ->setRequestedSeats($seats)
            ->setQuoteExpiresAt($adjustment->getQuoteExpiresAt());

        // L'ajustement financier a déjà été appliqué au wallet avant l'appel.
        $adjustment->setStatus(ReservationReschedule::STATUS_COMPLETED);
        $this->em->persist($adjustment);
        $this->em->persist($reservation);
        $this->em->persist($fromTrip);
        $this->em->persist($toTrip);
        $this->em->persist($history);
        $this->em->flush();

        $this->auditLogger->record('BOOKING_RESCHEDULED', 'Reservation', (string) $reservation->getId(),
            ['tripId' => $oldTripId, 'rescheduleCount' => $reservation->getRescheduleCount() - 1],
            ['tripId' => $toTrip->getId(), 'rescheduleCount' => $reservation->getRescheduleCount()],
            ['source' => 'reschedule.financial.finalize', 'rescheduleAdjustmentId' => $adjustment->getId(), 'difference' => $adjustment->getDifference()]
        );
        $this->em->flush();
    }
}
