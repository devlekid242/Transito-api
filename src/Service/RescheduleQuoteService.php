<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class RescheduleQuoteService
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return array{fromTrip:Trip,toTrip:Trip,tickets:array,seatNumbers:array,oldTotal:string,newTotal:string,difference:string,direction:string} */
    public function quote(Reservation $reservation, int $newTripId, ?array $requestedSeats): array
    {
        if ($reservation->getPaymentStatus() !== 'paye') {
            throw new RuntimeException('Seule une réservation payée peut être reportée.');
        }
        if ($reservation->getRescheduleCount() >= 1) {
            throw new RuntimeException('Cette réservation a déjà été reportée une fois.');
        }
        $fromTrip = $reservation->getTrip();
        if (!$fromTrip || !$fromTrip->getDepartureTime()) {
            throw new RuntimeException('Voyage actuel invalide.');
        }
        if ($fromTrip->getDepartureTime()->getTimestamp() - time() < 24 * 3600) {
            throw new RuntimeException('Le report doit être demandé au moins 24h avant le départ.');
        }
        if ($newTripId <= 0 || $newTripId === $fromTrip->getId()) {
            throw new RuntimeException('Veuillez sélectionner un nouveau voyage.');
        }
        $newTrip = $this->em->getRepository(Trip::class)->find($newTripId);
        if (!$newTrip || $newTrip->getStatus() !== 'planifie') {
            throw new RuntimeException('Le nouveau voyage n’est plus disponible.');
        }
        if (!$newTrip->getDepartureTime() || $newTrip->getDepartureTime()->getTimestamp() < time() + 24 * 3600) {
            throw new RuntimeException('Le nouveau voyage doit également être prévu au moins 24h avant son départ.');
        }
        if ($newTrip->getAgency()?->getStatus() !== 'active') {
            throw new RuntimeException('L’agence du nouveau voyage n’est pas active.');
        }
        if ($newTrip->getAgency()?->getId() !== $fromTrip->getAgency()?->getId()) {
            throw new RuntimeException('Le report doit rester auprès de la même agence.');
        }

        $tickets = array_values(array_filter(
            $this->em->getRepository(Ticket::class)->findBy(['reservation' => $reservation], ['id' => 'ASC']),
            static fn (Ticket $t) => in_array($t->getStatus(), ['en_attente', 'embarque'], true)
        ));
        if ($tickets === []) {
            throw new RuntimeException('Cette réservation ne contient plus de billet actif à reporter.');
        }
        if ($requestedSeats !== null) {
            $requestedSeats = array_values(array_unique(array_map('intval', $requestedSeats)));
            if (count($requestedSeats) !== count($tickets)) {
                throw new RuntimeException(sprintf('Vous devez sélectionner exactement %d siège(s).', count($tickets)));
            }
        }

        $capacity = $newTrip->getBus()?->getCapacity() ?? 0;
        if ($capacity < count($tickets)) {
            throw new RuntimeException('Le véhicule du nouveau voyage ne peut pas accueillir la réservation.');
        }
        $occupied = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->join('t.reservation', 'r')
            ->andWhere('r.trip = :trip')
            ->andWhere('r.paymentStatus IN (:statuses)')
            ->andWhere('t.status IN (:ticketStatuses)')
            ->setParameter('trip', $newTrip)
            ->setParameter('statuses', ['en_attente', 'paye'])
            ->setParameter('ticketStatuses', ['en_attente', 'embarque'])
            ->getQuery()->getResult();
        $occupiedSeats = [];
        foreach ($occupied as $ticket) {
            if ($ticket->getSeatNumber() !== null) $occupiedSeats[(int) $ticket->getSeatNumber()] = true;
        }

        if ($requestedSeats !== null) {
            foreach ($requestedSeats as $seat) {
                if ($seat < 1 || $seat > $capacity) throw new RuntimeException(sprintf('Le siège %d n’existe pas.', $seat));
                if (isset($occupiedSeats[$seat])) throw new RuntimeException(sprintf('Le siège %d est déjà occupé.', $seat));
            }
            $seatNumbers = $requestedSeats;
        } else {
            $seatNumbers = [];
            for ($seat = 1; $seat <= $capacity && count($seatNumbers) < count($tickets); ++$seat) {
                if (!isset($occupiedSeats[$seat])) $seatNumbers[] = $seat;
            }
        }
        if (count($seatNumbers) !== count($tickets)) throw new RuntimeException('Impossible d’attribuer les sièges du nouveau voyage.');

        $oldTotal = (string) $reservation->getTotalAmount();
        $newTotal = bcmul((string) $newTrip->getPrice(), (string) count($tickets), 2);
        $difference = bcsub($newTotal, $oldTotal, 2);
        $direction = bccomp($difference, '0.00', 2) > 0 ? 'PAYMENT' : (bccomp($difference, '0.00', 2) < 0 ? 'REFUND' : 'NONE');

        return compact('fromTrip', 'newTrip', 'tickets', 'seatNumbers', 'oldTotal', 'newTotal', 'difference', 'direction');
    }
}
