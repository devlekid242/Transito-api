<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agency;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes the operational impact of changing an agency's lifecycle state.
 * Read-only: it never cancels trips, refunds clients or moves money.
 */
final class AgencyOperationalImpactService
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return array<string,mixed> */
    public function preview(Agency $agency): array
    {
        $now = new \DateTimeImmutable();
        $trips = $this->em->getRepository(Trip::class)->createQueryBuilder('t')
            ->andWhere('t.agency = :agency')
            ->andWhere('t.departureTime > :now')
            ->andWhere('t.status NOT IN (:closed)')
            ->setParameter('agency', $agency)
            ->setParameter('now', $now)
            ->setParameter('closed', ['annule', 'termine'])
            ->orderBy('t.departureTime', 'ASC')
            ->getQuery()->getResult();

        $tripRows = [];
        $paidReservations = 0;
        $pendingReservations = 0;
        $paidAmount = '0.00';
        $activeTickets = 0;
        $platformFees = '0.00';

        foreach ($trips as $trip) {
            $reservations = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->andWhere('r.trip = :trip')
                ->setParameter('trip', $trip)
                ->getQuery()->getResult();

            $tripPaid = 0;
            $tripPending = 0;
            $tripAmount = '0.00';
            $tripTickets = 0;

            foreach ($reservations as $reservation) {
                if ($reservation->getPaymentStatus() === 'paye') {
                    $tripPaid++;
                    $paidReservations++;
                    $gross = (string) $reservation->getTotalAmount();
                    $fee = number_format(WalletService::PLATFORM_FEE, 2, '.', '');
                    $net = bcsub($gross, $fee, 2);
                    if (bccomp($net, '0.00', 2) < 0) {
                        $net = '0.00';
                    }
                    $tripAmount = bcadd($tripAmount, $net, 2);
                    $paidAmount = bcadd($paidAmount, $net, 2);
                    $platformFees = bcadd($platformFees, bccomp($gross, $fee, 2) >= 0 ? $fee : $gross, 2);
                } elseif ($reservation->getPaymentStatus() === 'en_attente') {
                    $tripPending++;
                    $pendingReservations++;
                }

                $tickets = $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation]);
                foreach ($tickets as $ticket) {
                    if ($ticket->getStatus() !== 'annule') {
                        $tripTickets++;
                        $activeTickets++;
                    }
                }
            }

            $tripRows[] = [
                'id' => $trip->getId(),
                'departureCity' => $trip->getDepartureCity(),
                'arrivalCity' => $trip->getArrivalCity(),
                'departureTime' => $trip->getDepartureTime()?->format(\DateTimeInterface::ATOM),
                'status' => $trip->getStatus(),
                'paidReservations' => $tripPaid,
                'pendingReservations' => $tripPending,
                'activeTickets' => $tripTickets,
                'netAgencyExposure' => $tripAmount,
            ];
        }

        return [
            'agencyId' => $agency->getId(),
            'agencyStatus' => $agency->getStatus(),
            'bookingBlockedIfSuspended' => true,
            'futureTrips' => count($tripRows),
            'paidReservations' => $paidReservations,
            'pendingReservations' => $pendingReservations,
            'activeTickets' => $activeTickets,
            'netAgencyExposure' => $paidAmount,
            'platformFeesIncludedInClientPayments' => $platformFees,
            'trips' => $tripRows,
            'requiresTripDecision' => count($tripRows) > 0 && $paidReservations > 0,
        ];
    }
}
