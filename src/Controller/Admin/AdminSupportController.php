<?php

namespace App\Controller\Admin;

use App\Entity\FAQ;
use App\Entity\SupportResponse;
use App\Entity\SupportTicket;
use App\Repository\FAQRepository;
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
 * Correction de sécurité majeure : ce contrôleur ne comportait AUCUN contrôle
 * d'accès. Selon la configuration du firewall (security.yaml), n'importe quel
 * appelant pouvait potentiellement lister/modifier/supprimer tous les tickets
 * de support et toutes les FAQ via /api/admin/support/*.
 * L'attribut #[IsGranted('ROLE_ADMIN')] ci-dessous force désormais un accès
 * réservé aux administrateurs sur l'ensemble des routes de ce contrôleur.
 */
#[Route('/api/admin/support')]
#[IsGranted('ROLE_ADMIN')]
class AdminSupportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private SupportTicketRepository $ticketRepository,
        private SupportResponseRepository $responseRepository,
        private FAQRepository $faqRepository,
        private UserRepository $userRepository,
        private NotificationBroadcastService $notificationBroadcaster,
        private AdminNotificationHelper $adminNotifier
    ) {}

    // ==================== SUPPORT TICKETS ====================

    /**
     * Get all support tickets with optional filtering
     */
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
            // Correction : la clause OR était parenthésée nulle part, donc
            // combinée avec AND status/priority/category elle cassait le
            // filtrage (un ticket pouvait remonter en ignorant les autres
            // filtres dès que "message" matchait la recherche).
            $queryBuilder->andWhere('(t.subject LIKE :search OR t.message LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        $tickets = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        $total = $this->ticketRepository->count([]);

        return new JsonResponse([
            'data' => $tickets,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ], 200);
    }

    /**
     * Get a single support ticket with its responses
     */
    #[Route('/tickets/{id}', name: 'admin_support_ticket_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getTicketDetail(int $id): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);

        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        $responses = $this->responseRepository->findBy(['ticket' => $ticket], ['createdAt' => 'ASC']);

        return new JsonResponse([
            'ticket' => $ticket,
            'responses' => $responses
        ], 200);
    }

    /**
     * Update a support ticket status
     */
    #[Route('/tickets/{id}/status', name: 'admin_support_ticket_status', methods: ['PUT'])]
    public function updateTicketStatus(int $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);

        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $status = $data['status'] ?? null;

        if (!in_array($status, ['open', 'answered', 'closed', 'pending'], true)) {
            return new JsonResponse(['error' => 'Invalid status'], 400);
        }

        $oldStatus = $ticket->getStatus();
        $ticket->setStatus($status);
        $this->em->flush();

        // Notify user when ticket is closed or answered
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

        return new JsonResponse(['message' => 'Ticket status updated', 'ticket' => $ticket], 200);
    }

    /**
     * Update a support ticket priority
     */
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

        return new JsonResponse(['message' => 'Ticket priority updated', 'ticket' => $ticket], 200);
    }

    /**
     * Admin adds a response to a support ticket
     */
    #[Route('/tickets/{id}/responses', name: 'admin_support_add_response', methods: ['POST'])]
    public function addAdminResponse(int $id, Request $request): JsonResponse
    {
        $ticket = $this->ticketRepository->find($id);

        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $message = $data['message'] ?? '';

        if (empty($message)) {
            return new JsonResponse(['error' => 'Message cannot be empty'], 400);
        }

        $response = new SupportResponse();
        $response->setTicket($ticket);
        $response->setMessage($message);

        // ATTENTION — bug non corrigé, à valider avec le fichier Agent.php :
        // setAgent() attend une instance de App\Entity\Agent, mais le code
        // original faisait $this->userRepository->find($admin->getId()),
        // ce qui renvoie une instance de App\Entity\User et non de Agent.
        // Si Agent et User sont deux entités distinctes (ce que suggère la
        // relation ManyToOne(targetEntity: Agent::class) dans SupportResponse),
        // cette ligne provoquera un TypeError à l'exécution.
        // Merci de fournir Agent.php et AgentRepository.php pour que je
        // puisse corriger correctement, par exemple via :
        //   $agent = $this->agentRepository->findOneBy(['user' => $admin]);
        /** @var \App\Entity\User $admin */
        $admin = $this->getUser();
        if ($admin) {
            $response->setAgent($this->userRepository->find($admin->getId())); // TODO: bug type Agent/User, voir commentaire ci-dessus
        }

        $this->em->persist($response);
        $this->em->flush();

        // Update ticket status to answered
        $ticket->setStatus('answered');
        $this->em->flush();

        // Notify the user
        if ($ticket->getUser()) {
            $notification = new \App\Entity\Notification();
            $notification->setRecipientType('user')
                ->setRecipientId($ticket->getUser()->getId())
                ->setTitle('Réponse au ticket de support')
                ->setContent('Une nouvelle réponse a été ajoutée à votre ticket: ' . $ticket->getSubject())
                ->setCategory('SUPPORT');
            $this->em->persist($notification);
            $this->em->flush();
            $this->notificationBroadcaster->broadcast($notification);
        }

        return new JsonResponse(['message' => 'Response added', 'response' => $response], 201);
    }

    /**
     * Get support ticket statistics for dashboard
     */
    #[Route('/tickets/stats', name: 'admin_support_tickets_stats', methods: ['GET'])]
    public function getTicketStats(): JsonResponse
    {
        $openCount = $this->ticketRepository->count(['status' => 'open']);
        $answeredCount = $this->ticketRepository->count(['status' => 'answered']);
        $closedCount = $this->ticketRepository->count(['status' => 'closed']);
        $pendingCount = $this->ticketRepository->count(['status' => 'pending']);

        $highPriorityCount = $this->ticketRepository->count(['priority' => 'high']);
        $criticalPriorityCount = $this->ticketRepository->count(['priority' => 'critical']);

        $recentTickets = $this->ticketRepository->findBy([], ['createdAt' => 'DESC'], 5);

        return new JsonResponse([
            'stats' => [
                'open' => $openCount,
                'answered' => $answeredCount,
                'closed' => $closedCount,
                'pending' => $pendingCount,
                'high_priority' => $highPriorityCount,
                'critical_priority' => $criticalPriorityCount,
            ],
            'recent_tickets' => $recentTickets
        ], 200);
    }

    // ==================== FAQ MANAGEMENT ====================

    /**
     * Get all FAQs with optional filtering
     */
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
            // Correction : même bug de précédence AND/OR que dans getAllTickets
            // ci-dessus — parenthèse ajoutée pour éviter qu'une FAQ inactive
            // ou d'une autre catégorie ne remonte via la recherche.
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
            'data' => $faqs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ], 200);
    }


    /**
     * Create a new FAQ
     */
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

        return new JsonResponse(['message' => 'FAQ created', 'faq' => $faq], 201);
    }

    /**
     * Update an existing FAQ
     */
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

        return new JsonResponse(['message' => 'FAQ updated', 'faq' => $faq], 200);
    }

    /**
     * Delete an FAQ
     */
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

    /**
     * Get FAQ categories
     */
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

    /**
     * Reorder FAQs
     */
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

    /**
     * Get a single FAQ
     */
    #[Route('/faqs/{id}', name: 'admin_support_faq_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getFAQ(int $id): JsonResponse
    {
        $faq = $this->faqRepository->find($id);

        if (!$faq) {
            return new JsonResponse(['error' => 'FAQ not found'], 404);
        }

        return new JsonResponse(['faq' => $faq], 200);
    }
}
