<?php

namespace App\Controller\Admin;

use App\Security\AdminRoleVoter;

use App\Entity\FAQ;
use App\Entity\SupportResponse;
use App\Entity\SupportTicket;
use App\Repository\FAQRepository;
use App\Repository\AgentRepository;
use App\Repository\SupportResponseRepository;
use App\Repository\SupportTicketRepository;
use App\Repository\UserRepository;
use App\Service\AdminNotificationHelper;
use App\Service\NotificationBroadcastService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Correction de sécurité majeure (déjà appliquée) : #[IsGranted('ROLE_ADMIN')]
 * réserve désormais l'ensemble des routes de ce contrôleur aux administrateurs.
 *
 * 👈 NOUVELLE CORRECTION (cette passe) : la quasi-totalité des routes
 * renvoyaient des entités Doctrine BRUTES dans le JsonResponse
 * (`'data' => $tickets`, `'ticket' => $ticket`, `'response' => $response`...).
 * Comme ces entités n'ont que des propriétés PRIVÉES et n'implémentent pas
 * JsonSerializable, `json_encode()` les sérialisait en objets vides `{}`.
 * Concrètement : la boîte de réception admin recevait des tickets sans
 * sujet, sans utilisateur, sans message — le front n'avait rien à afficher.
 * Toutes les routes utilisent maintenant `ticketToArray()` / `responseToArray()`
 * / `faqToArray()` pour renvoyer des tableaux explicites, comme le fait déjà
 * SupportController côté client.
 *
 * 👈 NOUVEAU : l'admin est le seul pivot entre client et agence — il n'y a
 * pas de conversation directe agence <-> client. Un ticket peut contenir
 * deux fils (SupportResponse::$channel = 'client' ou 'agency') ; ce
 * contrôleur est le seul à les voir tous les deux (voir getTicketDetail()
 * qui renvoie l'ensemble via ticketToArray($ticket, true), et
 * addAdminResponse() qui prend un `channel` en paramètre pour savoir à qui
 * répondre). Voir SupportController (fil client) et PartnerSupportController
 * (fil agence) pour le filtrage de chaque côté.
 */
#[Route('/api/admin/support')]
#[IsGranted(AdminRoleVoter::SUPPORT)]
// #[IsGranted('ROLE_ADMIN')]
class AdminSupportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private SupportTicketRepository $ticketRepository,
        private SupportResponseRepository $responseRepository,
        private FAQRepository $faqRepository,
        private NotificationBroadcastService $notificationBroadcaster,
        private AdminNotificationHelper $adminNotifier,
        private UserRepository $userRepository,
        // 👈 NOUVEAU : pour retrouver les comptes admin_agence d'une agence
        // quand l'admin répond sur le canal 'agency' (voir addAdminResponse
        // / notifyAgencyPartners).
        private AgentRepository $agentRepository
    ) {}

    // ==================== SÉRIALISATION ====================

    /**
     * Sérialise un ticket en tableau. `$withResponses` embarque aussi les
     * réponses (utile pour la vue détail, évite un second appel front).
     * `lastResponse` est ajouté pour permettre à la liste admin d'afficher
     * un aperçu du dernier message sans requête supplémentaire.
     *
     * ⚠️ Note perf : sur une liste de tickets, ->getResponses()->last()
     * déclenche le chargement de la collection pour chaque ticket (N+1).
     * Acceptable pour un volume modéré ; si la liste grossit, remplacer par
     * une sous-requête dédiée (DQL) qui ne remonte que le dernier message.
     */
    private function ticketToArray(SupportTicket $t, bool $withResponses = false): array
    {
        $user = $t->getUser();
        $assignedTo = $t->getAssignedTo();
        /** @var SupportResponse|false $last */
        $last = $t->getResponses()->isEmpty() ? null : $t->getResponses()->last();

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
            'responseCount' => $t->getResponses()->count(),
            'reservationId' => $t->getReservation()?->getId(),
            'tripId' => $t->getTrip()?->getId(),
            'agencyId' => $t->getAgency()?->getId(),
            'user' => $user ? [
                'id' => $user->getId(),
                'fullName' => method_exists($user, 'getFullName') ? $user->getFullName() : null,
                'email' => method_exists($user, 'getEmail') ? $user->getEmail() : null,
                'phoneNumber' => method_exists($user, 'getPhoneNumber') ? $user->getPhoneNumber() : null,
            ] : null,
            'assignedTo' => $assignedTo ? [
                'id' => $assignedTo->getId(),
                'fullName' => method_exists($assignedTo, 'getFullName') ? $assignedTo->getFullName() : null,
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
                $t->getResponses()->toArray()
            );
        }

        return $data;
    }

    /**
     * `isFromSupport` remplace la relation `agent` (jamais renseignée en
     * pratique — voir addAdminResponse). On la déduit en comparant l'auteur
     * du message au propriétaire du ticket : si ce n'est pas le client,
     * c'est forcément un membre du support (seuls admin/support et le
     * propriétaire peuvent répondre, cf. SupportController::addResponse).
     *
     * 👈 NOUVEAU : `channel` distingue les deux fils désormais possibles sur
     * un même ticket — 'client' (client <-> admin) et 'agency' (agence <->
     * admin, jamais visible du client, voir PartnerSupportController et
     * SupportController::detail()). C'est le SEUL contrôleur qui a besoin
     * de voir les deux fils entremêlés, puisque l'admin est le pivot des
     * deux conversations.
     */
    private function responseToArray(SupportResponse $r): array
    {
        $ticket = $r->getTicket();
        $author = $r->getAuthor();
        $isFromSupport = $author !== null
            && (!$ticket?->getUser() || $ticket->getUser()->getId() !== $author->getId());

        return [
            'id' => $r->getId(),
            'ticketId' => $ticket?->getId(),
            'message' => $r->getMessage(),
            'createdAt' => $r->getCreatedAt()?->format(DATE_ATOM),
            'isFromSupport' => $isFromSupport,
            'channel' => $r->getChannel(),
            'author' => $author ? [
                'id' => $author->getId(),
                'fullName' => method_exists($author, 'getFullName') ? $author->getFullName() : null,
            ] : null,
        ];
    }

    private function faqToArray(FAQ $f): array
    {
        // NOTE : FAQ.php ne m'a pas été fourni. Le nom exact du getter pour
        // le booléen actif/inactif peut être isActive() ou getIsActive() —
        // les deux sont testés pour éviter une erreur fatale à l'exécution.
        $isActive = method_exists($f, 'isActive')
            ? $f->isActive()
            : (method_exists($f, 'getIsActive') ? $f->getIsActive() : null);

        return [
            'id' => $f->getId(),
            'question' => $f->getQuestion(),
            'answer' => $f->getAnswer(),
            'category' => $f->getCategory(),
            'orderPriority' => $f->getOrderPriority(),
            'isActive' => $isActive,
        ];
    }

    // ==================== SUPPORT TICKETS ====================

    #[Route('/tickets', name: 'admin_support_tickets', methods: ['GET'])]
    public function getAllTickets(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $category = $request->query->get('category');
        $search = $request->query->get('search');
        $limit = min($request->query->getInt('limit', 50), 200);
        $offset = $request->query->getInt('offset', 0);

        $queryBuilder = $this->ticketRepository->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC');

        if ($status) {
            $queryBuilder->andWhere('t.status = :status')->setParameter('status', $status);
        }

        if ($priority) {
            $queryBuilder->andWhere('t.priority = :priority')->setParameter('priority', $priority);
        }

        if ($category) {
            $queryBuilder->andWhere('t.category = :category')->setParameter('category', $category);
        }

        if ($search) {
            $queryBuilder->andWhere('(t.subject LIKE :search OR t.message LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        $total = (int) $queryBuilder->select('COUNT(t.id)')->resetDQLPart('orderBy')->setFirstResult(null)->setMaxResults(null)->getQuery()->getSingleScalarResult();
        $tickets = $queryBuilder->select('t')->orderBy('t.createdAt', 'DESC')->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();

        return new JsonResponse([
            'data' => array_map(fn (SupportTicket $t) => $this->ticketToArray($t), $tickets),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ], 200);
    }

    #[Route('/tickets/{id}', name: 'admin_support_ticket_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getTicketDetail(int $id): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);

        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        return new JsonResponse([
            'data' => $this->ticketToArray($ticket, true),
        ], 200);
    }

    #[Route('/tickets/{id}/assign', name: 'admin_support_ticket_assign', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function assignTicket(int $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);
        if (!$ticket) return new JsonResponse(['error' => 'Ticket not found'], 404);

        $data = json_decode($request->getContent(), true) ?: [];
        $assigneeId = $data['userId'] ?? null;
        if ($assigneeId === null) {
            $ticket->setAssignedTo(null)->touch();
            $this->em->flush();
            return new JsonResponse(['message' => 'Ticket unassigned', 'ticket' => $this->ticketToArray($ticket)], 200);
        }

        $assignee = $this->userRepository->find((int) $assigneeId);
        if (!$assignee) return new JsonResponse(['error' => 'Assignee not found'], 404);
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPPORT')) {
            return new JsonResponse(['error' => 'Invalid support assignee'], 403);
        }

        $ticket->setAssignedTo($assignee)->touch();
        $this->em->flush();

        $notification = new \App\Entity\Notification();
        $notification->setRecipientType('user')
            ->setRecipientId($assignee->getId())
            ->setTitle('Ticket de support assigné')
            ->setContent('Un ticket de support vous a été assigné : « ' . $ticket->getSubject() . ' ».')
            ->setCategory('SUPPORT');
        $this->em->persist($notification);
        $this->em->flush();
        $this->notificationBroadcaster->broadcast($notification);

        return new JsonResponse(['message' => 'Ticket assigned', 'ticket' => $this->ticketToArray($ticket)], 200);
    }

    #[Route('/tickets/{id}/status', name: 'admin_support_ticket_status', methods: ['PUT'])]
    public function updateTicketStatus(int $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);

        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $status = $data['status'] ?? null;
        $closeReason = trim((string) ($data['reason'] ?? ''));

        if (!in_array($status, ['open', 'answered', 'closed', 'pending'], true)) {
            return new JsonResponse(['error' => 'Invalid status'], 400);
        }

        $oldStatus = $ticket->getStatus();
        $ticket->setStatus($status);
        if ($status === 'closed') {
            $ticket->setClosedReason($closeReason !== '' ? $closeReason : $ticket->getClosedReason());
            if ($ticket->getClosedAt() === null) {
                $ticket->close();
                $ticket->setClosedReason($closeReason !== '' ? $closeReason : null);
            }
        } elseif ($oldStatus === 'closed') {
            $ticket->setClosedReason(null);
            $ticket->touch();
        }
        $this->em->flush();

        if ($ticket->getUser() && $oldStatus !== $status) {
            $notificationTitle = '';
            $notificationContent = '';

            switch ($status) {
                case 'answered':
                    $notificationTitle = 'Ticket répondu';
                    $notificationContent = 'Votre ticket de support a reçu une réponse.';
                    break;
                case 'closed':
                    $notificationTitle = 'Ticket fermé';
                    $notificationContent = 'Votre ticket de support a été fermé.';
                    break;
                case 'pending':
                    $notificationTitle = 'Ticket en attente';
                    $notificationContent = 'Votre ticket de support est en cours de traitement.';
                    break;
            }

            if ($notificationTitle) {
                $notification = new \App\Entity\Notification();
                $notification->setRecipientType('user')
                    ->setRecipientId($ticket->getUser()->getId())
                    ->setTitle($notificationTitle)
                    ->setContent($notificationContent)
                    ->setCategory('SUPPORT');
                $this->em->persist($notification);
                $this->em->flush();
                $this->notificationBroadcaster->broadcast($notification);
            }
        }

        return new JsonResponse(['message' => 'Ticket status updated', 'ticket' => $this->ticketToArray($ticket)], 200);
    }

    #[Route('/tickets/{id}/priority', name: 'admin_support_ticket_priority', methods: ['PUT'])]
    public function updateTicketPriority(int $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);

        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $priority = $data['priority'] ?? null;

        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            return new JsonResponse(['error' => 'Invalid priority'], 400);
        }

        $ticket->setPriority($priority);
        $this->em->flush();

        return new JsonResponse(['message' => 'Ticket priority updated', 'ticket' => $this->ticketToArray($ticket)], 200);
    }

    /**
     * Réponse admin à un ticket.
     * 👈 CORRIGÉ : setAgent() n'est plus appelé (Agent ≠ User, voir note
     * précédente) — seul setAuthor() est utilisé, et la distinction
     * agent/client se fait désormais via `isFromSupport` côté sérialisation.
     * 👈 CORRIGÉ (cette passe) : `markFirstResponse()` manquait ici, ce qui
     * faussait le calcul du SLA (`slaBreached` restait vrai même après une
     * réponse envoyée depuis le back-office admin).
     *
     * 👈 CORRECTION DE CONCEPTION (cette passe) : l'admin est maintenant le
     * SEUL pivot entre le client et l'agence — il n'y a plus de canal
     * agence <-> client direct. Ce endpoint accepte donc un `channel`
     * ('client' par défaut, ou 'agency') dans le body pour indiquer à qui
     * la réponse s'adresse :
     *   - 'client' : notifie le client, statut -> 'answered', déclenche le
     *     calcul du SLA (markFirstResponse), comme avant.
     *   - 'agency' : notifie les admin_agence de l'agence du ticket, ne
     *     touche ni le statut visible du client ni le SLA (ce n'est pas une
     *     réponse au client). Ignoré si le ticket n'a pas d'agence.
     */
    #[Route('/tickets/{id}/responses', name: 'admin_support_add_response', methods: ['POST'])]
    public function addAdminResponse(int $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);

        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $message = trim((string) ($data['message'] ?? ''));
        $channel = (string) ($data['channel'] ?? 'client');

        if ($message === '') {
            return new JsonResponse(['error' => 'Message cannot be empty'], 400);
        }
        if (!in_array($channel, ['client', 'agency'], true)) {
            return new JsonResponse(['error' => 'Invalid channel'], 400);
        }
        if ($channel === 'agency' && !$ticket->getAgency()) {
            return new JsonResponse(['error' => 'This ticket has no agency to reply to'], 400);
        }

        $response = new SupportResponse();
        $response->setTicket($ticket);
        $response->setMessage($message);
        $response->setChannel($channel);

        /** @var \App\Entity\User|null $admin */
        $admin = $this->getUser();
        if ($admin) {
            $response->setAuthor($admin);
        }

        $this->em->persist($response);

        if ($channel === 'client') {
            $ticket->setStatus('answered');
            $ticket->markFirstResponse();
        }
        $ticket->touch();
        $this->em->flush();

        if ($channel === 'client') {
            if ($ticket->getUser()) {
                $notification = new \App\Entity\Notification();
                $notification->setRecipientType('user')
                    ->setRecipientId($ticket->getUser()->getId())
                    ->setTitle('Réponse au ticket de support')
                    ->setContent('Une nouvelle réponse a été ajoutée à votre ticket : ' . $ticket->getSubject())
                    ->setCategory('SUPPORT');
                $this->em->persist($notification);
                $this->em->flush();
                $this->notificationBroadcaster->broadcast($notification);
            }
        } else {
            $this->notifyAgencyPartners($ticket, $message);
        }

        return new JsonResponse([
            'message' => 'Response added',
            'response' => $this->responseToArray($response),
        ], 201);
    }

    /**
     * Notifie tous les comptes admin_agence actifs de l'agence rattachée au
     * ticket qu'une réponse admin les concernant vient d'être postée.
     * Duplique volontairement SupportController::notifyAgencyPartners()
     * (même agence, même repository, autre contexte d'appel) plutôt que de
     * le factoriser tout de suite dans un service partagé.
     */
    private function notifyAgencyPartners(SupportTicket $ticket, string $message): void
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

            $notification = new \App\Entity\Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($recipient->getId())
                ->setTitle('Réponse de l\'administrateur')
                ->setContent(sprintf('L\'administrateur a répondu sur le ticket « %s ».', $ticket->getSubject()))
                ->setCategory('SUPPORT');
            $this->em->persist($notification);
            $this->em->flush();
            $this->notificationBroadcaster->broadcast($notification);
        }
    }

    #[Route('/tickets/stats', name: 'admin_support_tickets_stats', methods: ['GET'])]
    public function getTicketStats(): JsonResponse
    {
        $openCount = $this->ticketRepository->count(['status' => 'open']);
        $answeredCount = $this->ticketRepository->count(['status' => 'answered']);
        $closedCount = $this->ticketRepository->count(['status' => 'closed']);
        $pendingCount = $this->ticketRepository->count(['status' => 'pending']);

        $highPriorityCount = $this->ticketRepository->count(['priority' => 'high']);
        $criticalPriorityCount = $this->ticketRepository->count(['priority' => 'critical']);
        $slaBreachedCount = (int) $this->ticketRepository->createQueryBuilder('t')->select('COUNT(t.id)')->where('t.status != :closed')->andWhere('t.firstResponseAt IS NULL')->andWhere('t.slaDueAt IS NOT NULL')->andWhere('t.slaDueAt < :now')->setParameter('closed', 'closed')->setParameter('now', new \DateTime())->getQuery()->getSingleScalarResult();

        $recentTickets = $this->ticketRepository->findBy([], ['createdAt' => 'DESC'], 5);

        return new JsonResponse([
            'stats' => [
                'open' => $openCount,
                'answered' => $answeredCount,
                'closed' => $closedCount,
                'pending' => $pendingCount,
                'high_priority' => $highPriorityCount,
                'critical_priority' => $criticalPriorityCount,
                'sla_breached' => $slaBreachedCount,
            ],
            'recent_tickets' => array_map(fn (SupportTicket $t) => $this->ticketToArray($t), $recentTickets),
        ], 200);
    }

    // ==================== FAQ MANAGEMENT ====================

    #[Route('/faqs', name: 'admin_support_faqs', methods: ['GET'])]
    public function getAllFAQs(Request $request): JsonResponse
    {
        $category = $request->query->get('category');
        $activeOnly = $request->query->getBoolean('active_only', true);
        $search = $request->query->get('search');
        $limit = min($request->query->getInt('limit', 100), 500);
        $offset = $request->query->getInt('offset', 0);

        $queryBuilder = $this->faqRepository->createQueryBuilder('f')
            ->orderBy('f.category', 'ASC')
            ->addOrderBy('f.orderPriority', 'ASC');

        if ($activeOnly) {
            $queryBuilder->andWhere('f.isActive = :active')->setParameter('active', true);
        }

        if ($category) {
            $queryBuilder->andWhere('f.category = :category')->setParameter('category', $category);
        }

        if ($search) {
            $queryBuilder->andWhere('(f.question LIKE :search OR f.answer LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        $faqs = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        $total = $activeOnly ?
            $this->faqRepository->count(['isActive' => true]) :
            $this->faqRepository->count([]);

        return new JsonResponse([
            'data' => array_map(fn (FAQ $f) => $this->faqToArray($f), $faqs),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ], 200);
    }

    #[Route('/faqs', name: 'admin_support_create_faq', methods: ['POST'])]
    public function createFAQ(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['question']) || empty($data['answer'])) {
            return new JsonResponse(['error' => 'Question and answer are required'], 400);
        }

        $faq = new FAQ();
        $faq->setQuestion($data['question']);
        $faq->setAnswer($data['answer']);
        $faq->setCategory($data['category'] ?? 'general');
        $faq->setOrderPriority($data['orderPriority'] ?? 0);
        $faq->setIsActive($data['isActive'] ?? true);

        $this->em->persist($faq);
        $this->em->flush();

        return new JsonResponse(['message' => 'FAQ created', 'faq' => $this->faqToArray($faq)], 201);
    }

    #[Route('/faqs/{id}', name: 'admin_support_update_faq', methods: ['PUT'])]
    public function updateFAQ(int $id, Request $request): JsonResponse
    {
        $faq = $this->faqRepository->find($id);

        if (!$faq) {
            return new JsonResponse(['error' => 'FAQ not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['question'])) {
            $faq->setQuestion($data['question']);
        }
        if (isset($data['answer'])) {
            $faq->setAnswer($data['answer']);
        }
        if (isset($data['category'])) {
            $faq->setCategory($data['category']);
        }
        if (isset($data['orderPriority'])) {
            $faq->setOrderPriority($data['orderPriority']);
        }
        if (isset($data['isActive'])) {
            $faq->setIsActive($data['isActive']);
        }

        $this->em->flush();

        return new JsonResponse(['message' => 'FAQ updated', 'faq' => $this->faqToArray($faq)], 200);
    }

    #[Route('/faqs/{id}', name: 'admin_support_delete_faq', methods: ['DELETE'])]
    public function deleteFAQ(int $id): JsonResponse
    {
        $faq = $this->faqRepository->find($id);

        if (!$faq) {
            return new JsonResponse(['error' => 'FAQ not found'], 404);
        }

        $this->em->remove($faq);
        $this->em->flush();

        return new JsonResponse(['message' => 'FAQ deleted'], 200);
    }

    #[Route('/faqs/categories', name: 'admin_support_faq_categories', methods: ['GET'])]
    public function getFAQCategories(): JsonResponse
    {
        $faqs = $this->faqRepository->findAll();
        $categories = [];

        foreach ($faqs as $faq) {
            if (!in_array($faq->getCategory(), $categories, true)) {
                $categories[] = $faq->getCategory();
            }
        }

        sort($categories);

        return new JsonResponse(['categories' => $categories], 200);
    }

    #[Route('/faqs/reorder', name: 'admin_support_faq_reorder', methods: ['PUT'])]
    public function reorderFAQs(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $orderedFaqIds = $data['orderedIds'] ?? [];

        if (empty($orderedFaqIds)) {
            return new JsonResponse(['error' => 'No FAQ IDs provided'], 400);
        }

        foreach ($orderedFaqIds as $index => $faqId) {
            $faq = $this->faqRepository->find($faqId);
            if ($faq) {
                $faq->setOrderPriority($index + 1);
            }
        }

        $this->em->flush();

        return new JsonResponse(['message' => 'FAQs reordered'], 200);
    }

    #[Route('/faqs/{id}', name: 'admin_support_faq_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getFAQ(int $id): JsonResponse
    {
        $faq = $this->faqRepository->find($id);

        if (!$faq) {
            return new JsonResponse(['error' => 'FAQ not found'], 404);
        }

        return new JsonResponse(['faq' => $this->faqToArray($faq)], 200);
    }
}