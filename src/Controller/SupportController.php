<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\SupportResponse;
use App\Entity\SupportTicket;
use App\Repository\UserRepository;
use App\Service\AdminNotificationHelper;
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
        private UserRepository $user_repository
    ) {}

    #[Route('/api/support', name: 'create_support', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) return new JsonResponse(['error' => 'Invalid'], 400);
        $ticket = new SupportTicket();
        $user = $this->getUser();
        if ($user) $ticket->setUser($user);
        $ticket->setSubject($data['subject'] ?? '');
        $ticket->setMessage($data['message'] ?? '');
        $ticket->setCategory($data['category'] ?? 'other');
        $ticket->setPriority($data['priority'] ?? 'medium');

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

        return new JsonResponse(['id' => $ticket->getId()], 201);
    }

    #[Route('/api/support/my-tickets', name: 'my_support', methods: ['GET'])]
    public function myTickets(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return new JsonResponse([], 200);
        $list = $this->em->getRepository(SupportTicket::class)->findBy(['user' => $user]);
        $out = array_map(fn($t) => ['id' => $t->getId(), 'subject' => $t->getSubject(), 'status' => $t->getStatus()], $list);
        return new JsonResponse($out, 200);
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
    #[Route('/api/support/{id}/responses', name: 'add_support_response', methods: ['POST'])]
    public function addResponse(int $id, Request $request): JsonResponse
    {
        $ticket = $this->em->getRepository(SupportTicket::class)->find($id);
        if (!$ticket) return new JsonResponse(['error' => 'Not found'], 404);
        $data = json_decode($request->getContent(), true);
        $resp = new SupportResponse();
        $resp->setTicket($ticket);
        $resp->setMessage($data['message'] ?? '');

        $authorId = $this->resolveResponseAuthorId($resp, $request);
        $isFromTicketOwner = $ticket->getUser() && $authorId !== null && $authorId === $ticket->getUser()->getId();

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