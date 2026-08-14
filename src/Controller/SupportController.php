<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\SupportResponse;
use App\Entity\SupportTicket;
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
        private AgencyRepository $agencyRepository
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

        // 👈 NOUVEAU : jusqu'ici, seul le client était notifié — aucun admin
        // n'était informé qu'un nouveau ticket venait d'arriver. Résultat :
        // un ticket pouvait rester sans réponse indéfiniment, personne côté
        // support n'étant alerté en dehors d'un rafraîchissement manuel de
        // la liste.
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

        return new JsonResponse(['id' => $ticket->getId()], 201);
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

    /**
     * 👈 CORRIGÉ : cette route servait aussi bien à un admin qui répond au
     * client qu'à un client qui relance son propre ticket, mais notifiait
     * TOUJOURS le client (donc parfois de son propre message) et JAMAIS les
     * admins quand c'est le client qui relance.
     *
     * ⚠️ Le code ci-dessous suppose que `SupportResponse` expose l'auteur via
     * `getAuthor()` (retournant un `User`). Si ta méthode s'appelle
     * différemment (`getUser()`, `getCreatedBy()`...), adapte
     * `resolveResponseAuthorId()` en conséquence. Si aucune méthode de ce
     * type n'existe encore sur l'entité, il faut l'ajouter pour distinguer
     * "réponse de l'admin" vs "relance du client" — sans ça, ce contrôleur
     * ne peut pas savoir qui a écrit le message.
     */
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

        $authorId = $currentUser->getId();
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
            // 👈 NOUVEAU : le client a relancé son propre ticket → ce sont
            // les admins qu'il faut prévenir, pas le client lui-même.
            $this->adminNotifier->notifyAdmins(
                'Relance sur un ticket de support',
                sprintf('%s a répondu sur le ticket « %s ».', $ticket->getUser()?->getFullName() ?? 'Un client', $ticket->getSubject()),
                'SUPPORT',
                ['ticketId' => $ticket->getId()],
            );
        }

        return new JsonResponse(['id' => $resp->getId()], 201);
    }

    /**
     * Tente de déterminer l'auteur de la réponse. Retourne null si
     * l'information n'est pas disponible (voir note ci-dessus) — dans ce
     * cas $isFromTicketOwner sera toujours false et le comportement reste
     * celui d'avant (notifie le client à chaque réponse).
     */
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

    private function resolveResponseAuthorId(SupportResponse $resp, Request $request): ?int
    {
        if (method_exists($resp, 'getAuthor') && $resp->getAuthor()) {
            return $resp->getAuthor()->getId();
        }
        if (method_exists($resp, 'getUser') && $resp->getUser()) {
            return $resp->getUser()->getId();
        }
        // Fallback : si l'appelant authentifié courant est un client (pas un
        // admin/agent), on considère que c'est lui l'auteur.
        /** @var User */
        $currentUser = $this->getUser();
        if ($currentUser && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_AGENT')) {
            // $user = $this->user_repository->findOneBy([])
            return $currentUser->getId();
        }
        return null;
    }
}