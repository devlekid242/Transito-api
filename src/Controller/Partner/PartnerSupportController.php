<?php

namespace App\Controller\Partner;

use App\Entity\Agency;
use App\Entity\Notification;
use App\Entity\SupportResponse;
use App\Entity\SupportTicket;
use App\Repository\SupportResponseRepository;
use App\Repository\SupportTicketRepository;
use App\Service\AdminNotificationHelper;
use App\Service\NotificationBroadcastService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Support côté back-office partenaire (agence).
 *
 * Calqué sur AdminSupportController (même style de sérialisation en
 * tableaux explicites, cf. son en-tête pour le pourquoi), mais avec une
 * différence structurante : TOUTE requête est filtrée sur l'agence de
 * l'utilisateur connecté. Un partenaire ne doit jamais pouvoir lister,
 * consulter ou répondre à un ticket qui n'appartient pas à sa propre
 * agence — y compris en devinant un ID de ticket dans l'URL.
 *
 * ⚠️ Prérequis obligatoire : User::getRoles() doit dériver ROLE_AGENCY_ADMIN
 * depuis l'entité Agent (voir le patch User.php fourni séparément). Sans ce
 * patch, #[IsGranted('ROLE_AGENCY_ADMIN')] ci-dessous rejette tout le monde
 * avec un 403, y compris les vrais comptes admin_agence.
 *
 * 👈 CORRECTION DE CONCEPTION (cette passe) : il n'y a PAS de conversation
 * directe agence <-> client. Un ticket a maintenant deux fils logiques,
 * distingués par le champ `channel` de SupportResponse :
 *   - channel = 'client' : échanges client <-> admin (voir SupportController
 *     et AdminSupportController).
 *   - channel = 'agency' : échanges agence <-> admin (ce contrôleur).
 * Une réponse d'agence n'est donc JAMAIS envoyée/visible au client : elle
 * notifie les admins de la plateforme, pas le client. Symétriquement,
 * ticketToArray() ici ne renvoie que les réponses `channel = 'agency'`,
 * pour ne jamais exposer au partenaire les échanges privés admin <-> client.
 * Voir SupportResponse::$channel — nécessite la migration fournie à part
 * (ce champ n'existait pas avant, je ne peux pas éditer l'entité moi-même
 * car elle n'a pas été fournie dans cette conversation).
 *
 * Volontairement absent de ce contrôleur (contrairement à l'admin) :
 * - La gestion des FAQ : reste une prérogative admin uniquement.
 * - L'assignation de ticket à un agent : un ticket appartient à l'agence
 *   dans son ensemble, pas à un agent nommément. À ajouter facilement si
 *   plusieurs comptes admin_agence par agence doivent se répartir la charge.
 * - Le changement de priorité : la priorité/SLA reste pilotée côté admin
 *   pour garder une politique de SLA cohérente sur toute la plateforme.
 *   Si tu veux l'ouvrir aux partenaires, il suffit de dupliquer
 *   updateTicketPriority() d'AdminSupportController ici avec le même filtre
 *   findOwnedTicket().
 */
#[Route('/api/partner/support')]
// #[IsGranted('ROLE_AGENCY_ADMIN')]
class PartnerSupportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SupportTicketRepository $ticketRepository,
        private SupportResponseRepository $responseRepository,
        private NotificationBroadcastService $notificationBroadcaster,
        // 👈 NOUVEAU : pour notifier les admins de la plateforme quand une
        // agence répond, au lieu de notifier le client (voir addResponse()).
        private AdminNotificationHelper $adminNotifier,
    ) {}

    // ==================== ISOLATION MULTI-TENANT ====================

    /**
     * Résout l'agence de l'utilisateur connecté. On ne fait jamais confiance
     * au seul rôle Symfony pour l'isolation : on revérifie ici que l'Agent
     * est actif, que son rôle métier est bien admin_agence, et qu'il est
     * rattaché à une agence — au cas où le rôle serait un jour porté
     * autrement (ex. rôle ajouté manuellement en base).
     */
    private function resolveAgency(): Agency
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        $agent = $user?->getAgent();
        $agency = $agent?->getAgency();

        if (!$agent || !$agency || $agent->getStatus() !== 'active' || $agent->getAgentRole() !== 'admin_agence') {
            throw $this->createAccessDeniedException('Aucune agence associée à ce compte.');
        }

        return $agency;
    }

    /**
     * Charge un ticket en vérifiant qu'il appartient à l'agence courante.
     * Renvoie un 404 (pas un 403) sur un ticket d'une autre agence, pour ne
     * pas confirmer à un partenaire qu'un ID donné existe chez un
     * concurrent.
     */
    private function findOwnedTicket(int $id, Agency $agency): SupportTicket
    {
        $ticket = $this->ticketRepository->find($id);
        if (!$ticket || $ticket->getAgency()?->getId() !== $agency->getId()) {
            throw $this->createNotFoundException('Ticket not found');
        }
        return $ticket;
    }

    // ==================== SÉRIALISATION ====================
    // Même logique que AdminSupportController::ticketToArray() /
    // responseToArray(), volontairement dupliquée ici plutôt que factorisée
    // dans un service partagé pour l'instant : ça garde ce contrôleur
    // autonome et lisible, et évite qu'une modification future de la vue
    // admin (ex. exposer l'email du client) ne fuite silencieusement côté
    // partenaire. `email` du client est d'ailleurs volontairement omis
    // ci-dessous, contrairement à la version admin.

    private function ticketToArray(SupportTicket $t, bool $withResponses = false): array
    {
        $user = $t->getUser();
        // 👈 CORRIGÉ : le "dernier message" affiché dans la liste des
        // tickets côté agence doit être le dernier message du fil AGENCE
        // (channel = 'agency'), jamais un message client <-> admin — sinon
        // l'aperçu de la liste fuite déjà une partie de la conversation
        // privée du client.
        $agencyResponses = $this->agencyChannelResponses($t);
        $last = $agencyResponses === [] ? null : $agencyResponses[array_key_last($agencyResponses)];

        $data = [
            'id' => $t->getId(),
            'subject' => $t->getSubject(),
            'message' => $t->getMessage(),
            'category' => $t->getCategory(),
            'status' => $t->getStatus(),
            'priority' => $t->getPriority(),
            'createdAt' => $t->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt' => $t->getUpdatedAt()?->format(DATE_ATOM),
            'closedAt' => $t->getClosedAt()?->format(DATE_ATOM),
            'closedReason' => $t->getClosedReason(),
            'firstResponseAt' => $t->getFirstResponseAt()?->format(DATE_ATOM),
            'slaDueAt' => $t->getSlaDueAt()?->format(DATE_ATOM),
            'slaBreached' => $t->isSlaBreached(),
            'responseCount' => count($agencyResponses),
            'reservationId' => $t->getReservation()?->getId(),
            'tripId' => $t->getTrip()?->getId(),
            // Le client reste affiché pour contexte ("de quel client parle
            // ce ticket ?"), mais l'agence ne peut plus lui écrire.
            'user' => $user ? [
                'id' => $user->getId(),
                'fullName' => method_exists($user, 'getFullName') ? $user->getFullName() : null,
                'phoneNumber' => method_exists($user, 'getPhoneNumber') ? $user->getPhoneNumber() : null,
            ] : null,
            'lastResponse' => $last ? [
                'id' => $last->getId(),
                'message' => $last->getMessage(),
                'createdAt' => $last->getCreatedAt()?->format(DATE_ATOM),
            ] : null,
        ];

        if ($withResponses) {
            $data['responses'] = array_map(
                fn (SupportResponse $r) => $this->responseToArray($r),
                $agencyResponses
            );
        }

        return $data;
    }

    /**
     * Ne renvoie que les réponses du fil agence <-> admin (channel =
     * 'agency'). C'est la barrière qui empêche un admin_agence de lire les
     * échanges privés admin <-> client sur le même ticket.
     *
     * @return SupportResponse[]
     */
    private function agencyChannelResponses(SupportTicket $t): array
    {
        return array_values(array_filter(
            $t->getResponses()->toArray(),
            fn (SupportResponse $r) => $r->getChannel() === 'agency',
        ));
    }

    private function responseToArray(SupportResponse $r): array
    {
        $author = $r->getAuthor();
        // Ici, l'auteur n'est jamais le client (ce fil ne contient que des
        // messages channel = 'agency'). isFromSupport = true signifie donc
        // "message de l'administrateur", false = "message de votre agence".
        $isFromSupport = $author !== null && $author->getAgent()?->getAgentRole() !== 'admin_agence';

        return [
            'id' => $r->getId(),
            'ticketId' => $r->getTicket()?->getId(),
            'message' => $r->getMessage(),
            'createdAt' => $r->getCreatedAt()?->format(DATE_ATOM),
            'isFromSupport' => $isFromSupport,
            'author' => $author ? [
                'id' => $author->getId(),
                'fullName' => method_exists($author, 'getFullName') ? $author->getFullName() : null,
            ] : null,
        ];
    }

    // ==================== TICKETS ====================

    #[Route('/tickets', name: 'partner_support_tickets', methods: ['GET'])]
    public function getAgencyTickets(Request $request): JsonResponse
    {
        $agency = $this->resolveAgency();

        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $search = $request->query->get('search');
        $limit = min($request->query->getInt('limit', 50), 200);
        $offset = $request->query->getInt('offset', 0);

        $qb = $this->ticketRepository->createQueryBuilder('t')
            ->andWhere('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('t.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }
        if ($priority) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $priority);
        }
        if ($search) {
            $qb->andWhere('(t.subject LIKE :search OR t.message LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        $total = (int) $qb->select('COUNT(t.id)')
            ->resetDQLPart('orderBy')
            ->setFirstResult(null)->setMaxResults(null)
            ->getQuery()->getSingleScalarResult();

        $tickets = $qb->select('t')->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)->setFirstResult($offset)
            ->getQuery()->getResult();

        return new JsonResponse([
            'data' => array_map(fn (SupportTicket $t) => $this->ticketToArray($t), $tickets),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ], 200);
    }

    #[Route('/tickets/{id}', name: 'partner_support_ticket_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getTicketDetail(int $id): JsonResponse
    {
        $agency = $this->resolveAgency();
        $ticket = $this->findOwnedTicket($id, $agency);

        return new JsonResponse([
            'data' => $this->ticketToArray($ticket, true),
        ], 200);
    }

    #[Route('/tickets/{id}/status', name: 'partner_support_ticket_status', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function updateTicketStatus(int $id, Request $request): JsonResponse
    {
        $agency = $this->resolveAgency();
        $ticket = $this->findOwnedTicket($id, $agency);

        $data = json_decode($request->getContent(), true) ?: [];
        $status = $data['status'] ?? null;
        $closeReason = trim((string) ($data['reason'] ?? ''));

        if (!in_array($status, ['open', 'answered', 'closed', 'pending'], true)) {
            return new JsonResponse(['error' => 'Invalid status'], 400);
        }

        $oldStatus = $ticket->getStatus();
        $ticket->setStatus($status);
        if ($status === 'closed') {
            if ($ticket->getClosedAt() === null) {
                $ticket->close();
            }
            $ticket->setClosedReason($closeReason !== '' ? $closeReason : $ticket->getClosedReason());
        } elseif ($oldStatus === 'closed') {
            $ticket->setClosedReason(null);
            $ticket->touch();
        }
        $this->em->flush();

        // 👈 CORRIGÉ : un changement de statut fait par l'agence ne
        // notifiait auparavant que le client — plus maintenant. Le client
        // ne discute pas avec l'agence, donc c'est l'admin qui doit être
        // tenu au courant, pas lui.
        if ($oldStatus !== $status) {
            $this->adminNotifier->notifyAdmins(
                'Statut de ticket modifié par une agence',
                sprintf(
                    '%s a mis à jour le statut du ticket « %s » : %s → %s.',
                    $agency->getName() ?? 'Une agence',
                    $ticket->getSubject(),
                    $oldStatus,
                    $status,
                ),
                'SUPPORT',
                ['ticketId' => $ticket->getId()],
            );
        }

        return new JsonResponse(['message' => 'Ticket status updated', 'ticket' => $this->ticketToArray($ticket)], 200);
    }

    /**
     * Réponse d'un agent d'agence (admin_agence) à un ticket de sa propre
     * agence.
     *
     * 👈 CORRIGÉ (correction de conception) : ce message ne va plus JAMAIS
     * au client. Il est marqué channel = 'agency' et notifie les admins de
     * la plateforme, qui décideront eux-mêmes s'il faut relayer l'info au
     * client via SupportController / AdminSupportController (channel =
     * 'client'). Le statut global du ticket passe à 'pending' ("en cours
     * de traitement") plutôt qu'à 'answered', qui impliquait auparavant à
     * tort que le CLIENT avait reçu une réponse. markFirstResponse() n'est
     * plus appelé ici : le SLA mesure le temps de première réponse au
     * client, pas à l'agence.
     */
    #[Route('/tickets/{id}/responses', name: 'partner_support_add_response', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addResponse(int $id, Request $request): JsonResponse
    {
        $agency = $this->resolveAgency();
        $ticket = $this->findOwnedTicket($id, $agency);

        if ($ticket->getStatus() === 'closed') {
            return new JsonResponse(['error' => 'This ticket is closed'], 409);
        }

        $data = json_decode($request->getContent(), true);
        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            return new JsonResponse(['error' => 'Message cannot be empty'], 400);
        }

        $response = new SupportResponse();
        $response->setTicket($ticket);
        $response->setMessage($message);
        $response->setChannel('agency');

        /** @var \App\Entity\User $partner */
        $partner = $this->getUser();
        $response->setAuthor($partner);

        $this->em->persist($response);

        // 'pending' : le ticket est "en cours de traitement" côté agence,
        // en attente d'un relais éventuel de l'admin vers le client. On ne
        // touche jamais au statut de manière à faire croire que le client
        // a été répondu.
        $ticket->setStatus('pending');
        $ticket->touch();
        $this->em->flush();

        $this->adminNotifier->notifyAdmins(
            'Réponse d\'une agence sur un ticket de support',
            sprintf(
                '%s (%s) a répondu sur le ticket « %s ». Ce message n\'a pas été envoyé au client.',
                $partner->getFullName() ?? 'Un agent',
                $agency->getName() ?? 'agence',
                $ticket->getSubject(),
            ),
            'SUPPORT',
            ['ticketId' => $ticket->getId()],
        );

        return new JsonResponse([
            'message' => 'Response added',
            'response' => $this->responseToArray($response),
        ], 201);
    }

    #[Route('/tickets/stats', name: 'partner_support_tickets_stats', methods: ['GET'])]
    public function getTicketStats(): JsonResponse
    {
        $agency = $this->resolveAgency();

        $base = $this->ticketRepository->createQueryBuilder('t')
            ->andWhere('t.agency = :agency')->setParameter('agency', $agency);

        $countByStatus = function (string $status) use ($base): int {
            return (int) (clone $base)->select('COUNT(t.id)')
                ->andWhere('t.status = :status')->setParameter('status', $status)
                ->getQuery()->getSingleScalarResult();
        };

        $slaBreachedCount = (int) (clone $base)->select('COUNT(t.id)')
            ->andWhere('t.status != :closed')->setParameter('closed', 'closed')
            ->andWhere('t.firstResponseAt IS NULL')
            ->andWhere('t.slaDueAt IS NOT NULL')
            ->andWhere('t.slaDueAt < :now')->setParameter('now', new \DateTime())
            ->getQuery()->getSingleScalarResult();

        $recentTickets = (clone $base)->select('t')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()->getResult();

        return new JsonResponse([
            'stats' => [
                'open' => $countByStatus('open'),
                'answered' => $countByStatus('answered'),
                'closed' => $countByStatus('closed'),
                'pending' => $countByStatus('pending'),
                'high_priority' => (int) (clone $base)->select('COUNT(t.id)')
                    ->andWhere('t.priority = :p')->setParameter('p', 'high')
                    ->getQuery()->getSingleScalarResult(),
                'critical_priority' => (int) (clone $base)->select('COUNT(t.id)')
                    ->andWhere('t.priority = :p')->setParameter('p', 'critical')
                    ->getQuery()->getSingleScalarResult(),
                'sla_breached' => $slaBreachedCount,
            ],
            'recent_tickets' => array_map(fn (SupportTicket $t) => $this->ticketToArray($t), $recentTickets),
        ], 200);
    }
}