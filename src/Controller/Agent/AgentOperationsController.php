<?php

namespace App\Controller\Agent;

use App\Entity\Agent;
use App\Entity\Notification;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agent')]
final class AgentOperationsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agentRepository,
        private TicketRepository $ticketRepository,
    ) {}

    #[Route('/dashboard', name: 'api_agent_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        $agent = $this->currentAgent();
        if (!$agent) return $this->forbidden();

        $agency = $agent->getAgency();
        $todayStart = new \DateTime('today');
        $tomorrow = (clone $todayStart)->modify('+1 day');

        $trips = $this->em->getRepository(Trip::class)->createQueryBuilder('tr')
            ->andWhere('tr.agency = :agency')
            ->andWhere('tr.departureTime >= :start')
            ->andWhere('tr.departureTime < :end')
            ->andWhere('tr.status != :cancelled')
            ->setParameter('agency', $agency)
            ->setParameter('start', $todayStart)
            ->setParameter('end', $tomorrow)
            ->setParameter('cancelled', 'annule')
            ->orderBy('tr.departureTime', 'ASC')
            ->getQuery()->getResult();

        $validated = $this->ticketRepository->countValidatedByAgent($agent, $todayStart, $tomorrow);
        $pending = $this->ticketRepository->countPendingByTrip($trips[0] ?? new Trip());

        return $this->json([
            'agent' => $this->agentPayload($agent),
            'today' => [
                'trips' => count($trips),
                'validatedByMe' => $validated,
                'pendingOnFirstTrip' => $trips ? $pending : 0,
            ],
            'trips' => array_map([$this, 'tripPayload'], $trips),
        ]);
    }

    #[Route('/trips', name: 'api_agent_trips', methods: ['GET'])]
    public function trips(Request $request): JsonResponse
    {
        $agent = $this->currentAgent();
        if (!$agent) return $this->forbidden();

        $agency = $agent->getAgency();
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        $qb = $this->em->getRepository(Trip::class)->createQueryBuilder('tr')
            ->andWhere('tr.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('tr.departureTime', 'ASC');

        if ($from) {
            $qb->andWhere('tr.departureTime >= :from')->setParameter('from', new \DateTime($from));
        }
        if ($to) {
            $qb->andWhere('tr.departureTime <= :to')->setParameter('to', new \DateTime($to));
        }

        return $this->json(array_map([$this, 'tripPayload'], $qb->getQuery()->getResult()));
    }

    #[Route('/trips/{id}/manifest', name: 'api_agent_trip_manifest', methods: ['GET'])]
    public function manifest(int $id): JsonResponse
    {
        $agent = $this->currentAgent();
        if (!$agent) return $this->forbidden();

        $trip = $this->em->getRepository(Trip::class)->find($id);
        if (!$trip || $trip->getAgency()?->getId() !== $agent->getAgency()?->getId()) {
            return $this->json(['message' => 'Voyage introuvable.'], 404);
        }

        $tickets = $this->ticketRepository->findTicketsByTrip($trip);
        $counts = ['total' => 0, 'pending' => 0, 'boarded' => 0, 'cancelled' => 0, 'noShow' => 0];
        $rows = [];

        foreach ($tickets as $ticket) {
            $status = $ticket->getStatus();
            $counts['total']++;
            if ($status === 'en_attente') $counts['pending']++;
            elseif ($status === 'embarque') $counts['boarded']++;
            elseif ($status === 'annule') $counts['cancelled']++;
            elseif ($status === 'no_show') $counts['noShow']++;

            $rows[] = $this->ticketPayload($ticket);
        }

        return $this->json([
            'trip' => $this->tripPayload($trip),
            'summary' => $counts,
            'tickets' => $rows,
        ]);
    }

    #[Route('/boarding/history', name: 'api_agent_boarding_history', methods: ['GET'])]
    public function boardingHistory(Request $request): JsonResponse
    {
        $agent = $this->currentAgent();
        if (!$agent) return $this->forbidden();

        $days = max(1, min(90, (int)$request->query->get('days', 7)));
        $end = new \DateTime();
        $start = (clone $end)->modify(sprintf('-%d days', $days));
        $tickets = $this->ticketRepository->findValidatedByAgentWithinPeriod($agent, $start, $end);

        return $this->json([
            'from' => $start->format('c'),
            'to' => $end->format('c'),
            'count' => count($tickets),
            'tickets' => array_map([$this, 'ticketPayload'], $tickets),
        ]);
    }

    #[Route('/notifications', name: 'api_agent_notifications', methods: ['GET'])]
    public function notifications(): JsonResponse
    {
        $agent = $this->currentAgent();
        if (!$agent) return $this->forbidden();

        $items = $this->em->getRepository(Notification::class)->createQueryBuilder('n')
            ->andWhere('(n.recipientType = :agent AND n.recipientId = :id)')
            ->setParameter('agent', 'agent')
            ->setParameter('id', $agent->getId())
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()->getResult();

        return $this->json(array_map(static fn(Notification $n) => [
            'id' => $n->getId(),
            'title' => $n->getTitle(),
            'content' => $n->getContent(),
            'category' => $n->getCategory(),
            'isRead' => $n->getIsRead() === 1,
            'payload' => $n->getPayload(),
            'createdAt' => $n->getCreatedAt()?->format('c'),
        ], $items));
    }

    #[Route('/profile', name: 'api_agent_profile_update', methods: ['PATCH'])]
    public function profile(Request $request): JsonResponse
    {
        $agent = $this->currentAgent();
        if (!$agent) return $this->forbidden();

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) return $this->json(['message' => 'Payload JSON invalide.'], 400);

        /** @var User $user */
        $user = $agent->getUser();
        if (array_key_exists('fullName', $data)) {
            $name = trim((string)$data['fullName']);
            if ($name === '') return $this->json(['message' => 'Le nom complet est obligatoire.'], 422);
            $user->setFullName($name);
        }
        if (array_key_exists('email', $data)) {
            $user->setEmail($data['email'] === null || $data['email'] === '' ? null : trim((string)$data['email']));
        }
        $this->em->flush();

        return $this->json($this->agentPayload($agent));
    }

    private function currentAgent(): ?Agent
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        return $agent && $agent->getStatus() === 'active' ? $agent : null;
    }

    private function forbidden(): JsonResponse
    {
        return $this->json(['message' => 'Compte agent requis.'], 403);
    }

    private function agentPayload(Agent $agent): array
    {
        return [
            'id' => $agent->getId(),
            'fullName' => $agent->getUser()?->getFullName(),
            'phoneNumber' => $agent->getUser()?->getPhoneNumber(),
            'profilePhotoUrl' => $agent->getUser()?->getProfilePhotoUrl(),
            'role' => $agent->getAgentRole(),
            'status' => $agent->getStatus(),
            'agency' => [
                'id' => $agent->getAgency()?->getId(),
                'name' => $agent->getAgency()?->getName(),
            ],
        ];
    }

    private function tripPayload(Trip $trip): array
    {
        return [
            'id' => $trip->getId(),
            'route' => $trip->getRoute(),
            'departureCity' => $trip->getDepartureCity(),
            'arrivalCity' => $trip->getArrivalCity(),
            'departureTime' => $trip->getDepartureTime()?->format('c'),
            'estimatedArrivalTime' => $trip->getEstimatedArrivalTime()?->format('c'),
            'status' => $trip->getStatus(),
            'price' => $trip->getPrice(),
            'seatsReserved' => $trip->getSeatsReserved(),
            'bus' => [
                'id' => $trip->getBus()?->getId(),
                'name' => method_exists($trip->getBus(), 'getName') ? $trip->getBus()?->getName() : null,
                'registration' => method_exists($trip->getBus(), 'getRegistrationNumber') ? $trip->getBus()?->getRegistrationNumber() : null,
            ],
        ];
    }

    private function ticketPayload(Ticket $ticket): array
    {
        return [
            'id' => $ticket->getId(),
            'code' => 'TKT-' . $ticket->getId(),
            'passengerName' => $ticket->getPassengerName(),
            'passengerPhone' => $ticket->getPassengerPhone(),
            'seatNumber' => $ticket->getSeatNumber(),
            'status' => $ticket->getStatus(),
            'settlementAmount' => $ticket->getSettlementAmount(),
            'validatedAt' => $ticket->getValidatedAt()?->format('c'),
            'validatedByAgentId' => $ticket->getValidatedByAgent()?->getId(),
        ];
    }
}
