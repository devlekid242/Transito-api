<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\SupportResponse;
use App\Entity\SupportTicket;
use App\Repository\AgentRepository;
use App\Repository\UserRepository;
use App\Repository\ReservationRepository;
use App\Repository\TripRepository;
use App\Repository\AgencyRepository;
use App\Service\AdminNotificationHelper;
use App\Service\AdminNotificationService;
use App\Service\NotificationBroadcastService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SupportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationBroadcastService $notificationBroadcaster,
        private AdminNotificationHelper $adminNotifier,
        private AdminNotificationService $adminNotificationService,
        private UserRepository $user_repository,
        private ReservationRepository $reservationRepository,
        private TripRepository $tripRepository,
        private AgencyRepository $agencyRepository,
        // 👈 NOUVEAU : nécessaire pour retrouver les comptes admin_agence
        // d'une agence donnée et les notifier des nouveaux tickets qui la
        // concernent (voir notifyAgencyPartners() plus bas).
        private AgentRepository $agentRepository
    ) {}

    #[Route('/api/support', name: 'create_support', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) return new JsonResponse(['error' => 'Invalid'], 400);
        $ticket = new SupportTicket();

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        if ($user) $ticket->setUser($user);
        $ticket->setSubject(trim((string) ($data['subject'] ?? '')));
        $ticket->setMessage(trim((string) ($data['message'] ?? '')));
        $ticket->setCategory((string) ($data['category'] ?? 'other'));
        $priority = (string) ($data['priority'] ?? 'medium');
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            return new JsonResponse(['error' => 'Invalid priority'], 400);
        }
        $ticket->setPriority($priority);

        if ($ticket->getSubject() === '' || $ticket->getMessage() === '') {
            return new JsonResponse(['error' => 'Subject and message are required'], 400);
        }

        // Optional business context. A client can only attach a reservation
        // that belongs to them; trip/agency can be attached independently.
        if (!empty($data['reservationId'])) {
            $reservation = $this->reservationRepository->find((int) $data['reservationId']);
            if (!$reservation) return new JsonResponse(['error' => 'Reservation not found'], 404);
            if (!$user || $reservation->getUser()?->getId() !== $user->getId()) {
                return new JsonResponse(['error' => 'Reservation access denied'], 403);
            }
            $ticket->setReservation($reservation);
            $ticket->setTrip($reservation->getTrip());
            $ticket->setAgency($reservation->getTrip()?->getAgency());
        } else {
            if (!empty($data['tripId'])) {
                $trip = $this->tripRepository->find((int) $data['tripId']);
                if (!$trip) return new JsonResponse(['error' => 'Trip not found'], 404);
                $ticket->setTrip($trip);
                $ticket->setAgency($trip->getAgency());
            }
            if (!empty($data['agencyId'])) {
                $agency = $this->agencyRepository->find((int) $data['agencyId']);
                if (!$agency) return new JsonResponse(['error' => 'Agency not found'], 404);
                $ticket->setAgency($agency);
            }
        }

        // SLA is based on priority and starts when the ticket is created.
        $slaHours = match ($priority) {
            'critical' => 1,
            'high' => 4,
            'medium' => 12,
            default => 24,
        };
        $ticket->setSlaDueAt(new \DateTime(sprintf('+%d hours', $slaHours)));

        $this->em->persist($ticket);

        if ($ticket->getUser()) {
            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($ticket->getUser()->getId())
                ->setTitle('Ticket de support créé')
                ->setContent(sprintf('Votre demande de support «%s» a bien été enregistrée.', $ticket->getSubject()))
                ->setCategory('SUPPORT');
            $this->em->persist($notification);
        }

        $this->em->flush();

        if (isset($notification)) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        // Notifie les admins de la plateforme, comme avant.
        $this->adminNotifier->notifyAdmins(
            'Nouveau ticket de support',
            sprintf(
                '%s a ouvert un ticket : « %s »',
                $ticket->getUser()?->getFullName() ?? 'Un utilisateur',
                $ticket->getSubject(),
            ),
            'SUPPORT',
            ['ticketId' => $ticket->getId()],
        );
        $this->adminNotificationService->notifyEvent(
            'Nouveau ticket de support',
            sprintf('Un nouveau ticket a été soumis : « %s ».', $ticket->getSubject()),
            'SUPPORT',
            ['ticketId' => $ticket->getId()]
        );

        // 👈 NOUVEAU : si le ticket est rattaché à une agence (via
        // reservationId/tripId/agencyId ci-dessus), notifie aussi les
        // admin_agence de cette agence. Sans ça, PartnerSupportController a
        // beau scoper correctement les tickets par agence, un partenaire ne
        // découvrirait un nouveau ticket qui le concerne qu'en rafraîchissant
        // sa liste manuellement — le même problème que celui déjà corrigé
        // côté admin plus haut.
        $this->notifyAgencyPartners($ticket);

        return new JsonResponse(['id' => $ticket->getId()], 201);
    }

    /**
     * Notifie tous les comptes admin_agence actifs de l'agence rattachée au
     * ticket, s'il y en a une. Ne fait rien pour un ticket sans agence
     * (catégorie générale, non lié à un voyage/réservation précis).
     */
    private function notifyAgencyPartners(SupportTicket $ticket): void
    {
        $agency = $ticket->getAgency();
        if (!$agency) {
            return;
        }

        $partnerAgents = $this->agentRepository->findBy([
            'agency' => $agency,
            'agentRole' => 'admin_agence',
            'status' => 'active',
        ]);

        foreach ($partnerAgents as $agent) {
            $recipient = $agent->getUser();
            if (!$recipient) {
                continue;
            }

            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($recipient->getId())
                ->setTitle('Nouveau ticket de support')
                ->setContent(sprintf(
                    '%s a ouvert un ticket concernant votre agence : « %s »',
                    $ticket->getUser()?->getFullName() ?? 'Un client',
                    $ticket->getSubject(),
                ))
                ->setCategory('SUPPORT');
            $this->em->persist($notification);
            $this->em->flush();
            $this->notificationBroadcaster->broadcast($notification);
        }
    }

    #[Route('/api/support/my-tickets', name: 'my_support', methods: ['GET'])]
    public function myTickets(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return new JsonResponse([], 200);
        $list = $this->em->getRepository(SupportTicket::class)->findBy(['user' => $user], ['updatedAt' => 'DESC', 'id' => 'DESC']);
        $out = array_map(fn($t) => [
            'id' => $t->getId(),
            'subject' => $t->getSubject(),
            'category' => $t->getCategory(),
            'priority' => $t->getPriority(),
            'status' => $t->getStatus(),
            'createdAt' => $t->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $t->getUpdatedAt()?->format(DATE_ATOM),
            'closedAt' => $t->getClosedAt()?->format(DATE_ATOM),
            'responseCount' => $t->getResponses()->count(),
            'slaDueAt' => $t->getSlaDueAt()?->format(DATE_ATOM),
            'slaBreached' => $t->isSlaBreached(),
            'reservationId' => $t->getReservation()?->getId(),
            'tripId' => $t->getTrip()?->getId(),
            'agencyId' => $t->getAgency()?->getId(),
        ], $list);
        return new JsonResponse(['data' => $out, 'count' => count($out)], 200);
    }

    #[Route('/api/support/{id}', name: 'support_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $ticket = $this->em->getRepository(SupportTicket::class)->find($id);
        if (!$ticket) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        /** @var \App\Entity\User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return new JsonResponse(['error' => 'Authentication required'], 401);
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPPORT');
        $isOwner = $ticket->getUser()?->getId() === $currentUser->getId();
        if (!$isAdmin && !$isOwner) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $responses = [];
        foreach ($ticket->getResponses() as $response) {
            // 👈 NOUVEAU : un ticket peut aussi contenir des échanges
            // internes agence <-> admin (channel = 'agency', voir
            // PartnerSupportController). Le client ne doit jamais les voir :
            // on ne lui montre que son propre fil (channel = 'client').
            if ($response->getChannel() !== 'client') {
                continue;
            }
            $author = $response->getAuthor();
            $responses[] = [
                'id' => $response->getId(),
                'message' => $response->getMessage(),
                'createdAt' => $response->getCreatedAt()?->format(DATE_ATOM),
                'author' => $author ? [
                    'id' => $author->getId(),
                    'fullName' => $author->getFullName(),
                    'isCurrentUser' => $author->getId() === $currentUser->getId(),
                ] : null,
            ];
        }

        return new JsonResponse([
            'id' => $ticket->getId(),
            'subject' => $ticket->getSubject(),
            'message' => $ticket->getMessage(),
            'category' => $ticket->getCategory(),
            'priority' => $ticket->getPriority(),
            'status' => $ticket->getStatus(),
            'createdAt' => $ticket->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $ticket->getUpdatedAt()?->format(DATE_ATOM),
            'closedAt' => $ticket->getClosedAt()?->format(DATE_ATOM),
            'slaDueAt' => $ticket->getSlaDueAt()?->format(DATE_ATOM),
            'firstResponseAt' => $ticket->getFirstResponseAt()?->format(DATE_ATOM),
            'slaBreached' => $ticket->isSlaBreached(),
            'reservationId' => $ticket->getReservation()?->getId(),
            'tripId' => $ticket->getTrip()?->getId(),
            'agencyId' => $ticket->getAgency()?->getId(),
            'responses' => $responses,
        ]);
    }

    #[Route('/api/support/{id}/responses', name: 'add_support_response', methods: ['POST'])]
    public function addResponse(int $id, Request $request): JsonResponse
    {
        $ticket = $this->em->getRepository(SupportTicket::class)->find($id);
        if (!$ticket) return new JsonResponse(['error' => 'Not found'], 404);

        /** @var \App\Entity\User|null $currentUser */
        $currentUser = $this->getUser();

        if (!$currentUser) return new JsonResponse(['error' => 'Authentication required'], 401);

        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPPORT');
        $isOwner = $ticket->getUser() && $ticket->getUser()->getId() === $currentUser->getId();

        if (!$isAdmin && !$isOwner) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        if ($ticket->getStatus() === 'closed') {
            return new JsonResponse(['error' => 'This ticket is closed'], 409);
        }

        $data = json_decode($request->getContent(), true);
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '') return new JsonResponse(['error' => 'Message is required'], 400);

        $resp = new SupportResponse();
        $resp->setTicket($ticket);
        $resp->setAuthor($currentUser);
        $resp->setMessage($message);
        // Ce endpoint ne sert qu'au fil client <-> admin (l'agence répond
        // via PartnerSupportController::addResponse(), channel = 'agency').
        $resp->setChannel('client');

        $isFromTicketOwner = $isOwner;

        $notification = null;
        if ($ticket->getUser() && !$isFromTicketOwner) {
            // Réponse d'un admin/agent : on notifie le client, comme avant.
            $notification = new Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($ticket->getUser()->getId())
                ->setTitle('Réponse au support')
                ->setContent('Une réponse a été ajoutée à votre ticket de support. Consultez la conversation pour plus de détails.')
                ->setCategory('SUPPORT');
            $this->em->persist($notification);
        }

        $this->em->persist($resp);
        $ticket->touch();
        if ($isFromTicketOwner) {
            $ticket->setStatus('open');
        } else {
            $ticket->setStatus('answered');
            $ticket->markFirstResponse();
        }
        $this->em->flush();

        if ($notification) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        if ($isFromTicketOwner) {
            // Le client a relancé son propre ticket → ce sont les admins
            // qu'il faut prévenir, pas le client lui-même.
            $this->adminNotifier->notifyAdmins(
                'Relance sur un ticket de support',
                sprintf('%s a répondu sur le ticket « %s ».', $ticket->getUser()?->getFullName() ?? 'Un client', $ticket->getSubject()),
                'SUPPORT',
                ['ticketId' => $ticket->getId()],
            );
            // 👈 NOUVEAU : si le ticket est rattaché à une agence, la relance
            // du client doit aussi remonter au partenaire, exactement comme
            // pour la création du ticket (notifyAgencyPartners réutilisée).
            $this->notifyAgencyPartners($ticket);
        }

        return new JsonResponse(['id' => $resp->getId()], 201);
    }

    #[Route('/api/support/{id}/close', name: 'support_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(int $id, Request $request): JsonResponse
    {
        $ticket = $this->em->getRepository(SupportTicket::class)->find($id);


        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();

        if (!$ticket || !$user) return new JsonResponse(['error' => 'Not found'], 404);
        if ($ticket->getUser()?->getId() !== $user->getId() && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPPORT')) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }
        $data = json_decode($request->getContent(), true) ?: [];
        $reason = trim((string) ($data['reason'] ?? ''));
        $ticket->close();
        $ticket->setClosedReason($reason !== '' ? $reason : null);
        $this->em->flush();
        return new JsonResponse(['message' => 'Ticket closed', 'id' => $ticket->getId(), 'status' => $ticket->getStatus()], 200);
    }

    #[Route('/api/support/{id}/reopen', name: 'support_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reopen(int $id): JsonResponse
    {
        $ticket = $this->em->getRepository(SupportTicket::class)->find($id);

        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        
        if (!$ticket || !$user) return new JsonResponse(['error' => 'Not found'], 404);
        if ($ticket->getUser()?->getId() !== $user->getId() && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPPORT')) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }
        $ticket->setStatus('open')->setClosedReason(null)->touch();
        $this->em->flush();
        return new JsonResponse(['message' => 'Ticket reopened', 'id' => $ticket->getId(), 'status' => $ticket->getStatus()], 200);
    }
}