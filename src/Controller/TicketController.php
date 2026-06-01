<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\Agent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TicketController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/api/tickets/{id}/validate', name: 'validate_ticket', methods: ['PATCH'])]
    public function validate(int $id, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $agentId = $body['agentId'] ?? null;
        $repo = $this->em->getRepository(Ticket::class);
        $ticket = $repo->find($id);
        if (!$ticket) return new JsonResponse(['error' => 'Not found'], 404);
        $ticket->setStatus('embarque');
        $ticket->setValidatedAt(new \DateTime());
        if ($agentId) {
            $agent = $this->em->getRepository(Agent::class)->find($agentId);
            if ($agent) $ticket->setValidatedByAgent($agent);
        }
        $this->em->flush();
        return new JsonResponse(['ok' => true], 200);
    }

    #[Route('/api/tickets', name: 'tickets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $reservationId = $request->query->get('reservation_id');
        $tripId = $request->query->get('trip_id');
        $repo = $this->em->getRepository(Ticket::class);
        if ($reservationId) {
            $tickets = $repo->findBy(['reservation' => $reservationId]);
        } elseif ($tripId) {
            // simple query through reservation->trip
            $qb = $repo->createQueryBuilder('t')
                ->join('t.reservation', 'r')
                ->join('r.trip', 'tr')
                ->where('tr.id = :trip')
                ->setParameter('trip', $tripId);
            $tickets = $qb->getQuery()->getResult();
        } else {
            $tickets = $repo->findAll();
        }
        $out = array_map([$this, 'mapTicket'], $tickets);
        return new JsonResponse($out, 200);
    }

    private function mapTicket(Ticket $ticket): array
    {
        $reservation = $ticket->getReservation();
        $trip = $reservation?->getTrip();
        $departureTime = $trip?->getDepartureTime();
        $statusMap = [
            'en_attente' => 'Actif',
            'embarque' => 'Utilisé',
            'annule' => 'Annulé',
        ];
        return [
            'id' => $ticket->getId(),
            'reservationId' => $reservation?->getId(),
            'ticketNumber' => 'TKT-' . $ticket->getId(),
            'passengerName' => $ticket->getPassengerName(),
            'tripNumber' => $trip ? 'TRIP-' . $trip->getId() : null,
            'departureCity' => $trip?->getDeparturePoint()?->getCity() ?? '',
            'arrivalCity' => $trip?->getArrivalPoint()?->getCity() ?? '',
            'departureTime' => $departureTime ? $departureTime->format('c') : null,
            'departureDate' => $departureTime ? $departureTime->format('Y-m-d') : null,
            'seatNumber' => (string)$ticket->getSeatNumber(),
            'busLicensePlate' => $trip?->getBus()?->getRegistrationNumber() ?? '',
            'qrCode' => $ticket->getQrCodeToken(),
            'status' => $statusMap[$ticket->getStatus()] ?? 'Expiré',
            'createdAt' => $ticket->getCreatedAt()?->format('c'),
            'updatedAt' => $ticket->getValidatedAt()?->format('c'),
        ];
    }
}
