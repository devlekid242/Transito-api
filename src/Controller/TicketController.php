<?php

namespace App\Controller;

use App\Entity\Agent;
use App\Entity\Notification;
use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\NotificationBroadcastService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TicketController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationBroadcastService $notificationBroadcaster,
    ) {}

    /**
     * Point d'entrée UNIQUE de validation d'un billet (scan QR ou saisie manuelle
     * du n° de billet). C'est la route appelée par PartnerApiService.validateTicket()
     * côté front (POST /api/tickets/validate), qui jusqu'ici n'existait pas côté
     * back — d'où l'erreur de synchronisation front/back.
     *
     * Règles de sécurité :
     *  - L'agent validateur n'est JAMAIS lu dans le corps de la requête. Il est
     *    déduit exclusivement de la session serveur ($this->getUser()), ce qui
     *    empêche un client d'usurper l'identité d'un autre agent en forgeant
     *    un "agentId" dans le payload (faille présente dans l'ancien code).
     *  - Le billet doit appartenir à l'agence de l'agent connecté (un agent ne
     *    peut valider que les billets de ses propres trajets).
     *  - Un billet annulé (remboursé) ou déjà embarqué n'est jamais re-validable.
     */
    #[Route('/api/tickets/validate', name: 'validate_ticket', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        $agent = $this->resolveAuthenticatedAgent();
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $qrCodeToken = trim((string)($body['qrCodeToken'] ?? ''));
        $ticketCode = trim((string)($body['ticketCode'] ?? ''));

        if ($qrCodeToken === '' && $ticketCode === '') {
            return new JsonResponse([
                'success' => false,
                'boardingStatus' => 'NOT_FOUND',
                'message' => 'Veuillez scanner un QR code ou saisir un numéro de billet.',
            ], 400);
        }

        $ticket = $this->findTicketByCode($qrCodeToken, $ticketCode);
        if (!$ticket) {
            return new JsonResponse([
                'success' => false,
                'boardingStatus' => 'NOT_FOUND',
                'message' => 'Billet introuvable.',
            ], 404);
        }

        if ($deny = $this->denyIfOutsideAgentAgency($ticket, $agent)) {
            return $deny;
        }

        if ($ticket->getStatus() === 'annule') {
            return new JsonResponse([
                'success' => false,
                'boardingStatus' => 'CANCELLED',
                'message' => "Ce billet a été annulé et n'est plus valide.",
            ], 400);
        }
        if ($ticket->getStatus() === 'embarque') {
            return new JsonResponse(array_merge($this->mapTicket($ticket), [
                'success' => false,
                'boardingStatus' => 'ALREADY_BOARDED',
                'message' => 'Ce billet a déjà été validé.',
            ]), 409);
        }

        $ticket->setStatus('embarque');
        $ticket->setValidatedByAgent($agent);
        $ticket->setValidatedAt(new \DateTime());
        $this->em->persist($ticket);

        $notification = $this->notifyPassengerOfBoarding($ticket);
        $this->em->flush();
        if ($notification) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        return new JsonResponse(array_merge($this->mapTicket($ticket), [
            'success' => true,
            'boardingStatus' => 'VALID',
            'message' => 'Billet validé avec succès.',
        ]), 200);
    }

    /**
     * Variante REST par identifiant, pour les écrans de back-office qui affichent
     * déjà un billet précis. Mêmes règles de sécurité que validate() ci-dessus :
     * l'agent vient de la session, jamais du corps de la requête.
     */
    #[Route('/api/tickets/{id}/validate', name: 'validate_ticket_by_id', methods: ['PATCH'])]
    public function validateById(int $id): JsonResponse
    {
        $agent = $this->resolveAuthenticatedAgent();
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $ticket = $this->em->getRepository(Ticket::class)->find($id);
        if (!$ticket) {
            return new JsonResponse(['error' => 'Billet introuvable.'], 404);
        }

        if ($deny = $this->denyIfOutsideAgentAgency($ticket, $agent)) {
            return $deny;
        }

        if ($ticket->getStatus() === 'annule') {
            return new JsonResponse(['error' => "Ce billet a été annulé et n'est plus valide."], 400);
        }
        if ($ticket->getStatus() === 'embarque') {
            return new JsonResponse(['error' => 'Ce billet a déjà été validé.'], 400);
        }

        $ticket->setStatus('embarque');
        $ticket->setValidatedByAgent($agent);
        $ticket->setValidatedAt(new \DateTime());

        $notification = $this->notifyPassengerOfBoarding($ticket);
        $this->em->flush();
        if ($notification) {
            $this->notificationBroadcaster->broadcast($notification);
        }

        return new JsonResponse(array_merge($this->mapTicket($ticket), ['ok' => true]), 200);
    }

    #[Route('/api/tickets/list', name: 'tickets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $agent = $this->resolveAuthenticatedAgent();
        if ($agent instanceof JsonResponse) {
            return $agent;
        }
        $agentAgency = method_exists($agent, 'getAgency') ? $agent->getAgency() : null;

        $reservationId = $request->query->get('reservation_id');
        $tripId = $request->query->get('trip_id');
        $repo = $this->em->getRepository(Ticket::class);

        $qb = $repo->createQueryBuilder('t')
            ->join('t.reservation', 'r')
            ->join('r.trip', 'tr');

        if ($reservationId) {
            $qb->andWhere('r.id = :reservationId')->setParameter('reservationId', $reservationId);
        }
        if ($tripId) {
            $qb->andWhere('tr.id = :tripId')->setParameter('tripId', $tripId);
        }
        // Un agent ne doit jamais pouvoir lister les billets d'une autre agence,
        // même en devinant un trip_id / reservation_id qui ne lui appartient pas.
        if ($agentAgency) {
            $qb->andWhere('tr.agency = :agency')->setParameter('agency', $agentAgency->getId());
        }

        $tickets = $qb->getQuery()->getResult();
        $out = array_map([$this, 'mapTicket'], $tickets);
        return new JsonResponse($out, 200);
    }

    #[Route('/api/tickets/{id}', name: 'get_ticket_by_id', methods: ['GET'])]
    public function getTicketById(int $id): JsonResponse
    {
        $agent = $this->resolveAuthenticatedAgent();
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $ticket = $this->em->getRepository(Ticket::class)->find($id);
        if (!$ticket) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        if ($deny = $this->denyIfOutsideAgentAgency($ticket, $agent)) {
            return $deny;
        }

        return new JsonResponse($this->mapTicket($ticket), 200);
    }

    /**
     * Récupère l'Agent lié à l'utilisateur authentifié côté serveur et vérifie
     * qu'il a le droit de valider des billets. Ne fait JAMAIS confiance à une
     * quelconque valeur envoyée par le client pour identifier l'agent.
     *
     * @return Agent|JsonResponse L'agent si tout est en ordre, sinon une réponse
     *                            d'erreur (401/403) déjà prête à être renvoyée.
     */
    private function resolveAuthenticatedAgent(): Agent|JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Authentification requise.'], 401);
        }

        $agent = $this->em->getRepository(Agent::class)->findOneBy(['user' => $user]);
        if (!$agent) {
            return new JsonResponse(['success' => false, 'message' => "Ce compte n'est pas rattaché à un agent d'agence."], 403);
        }

        // Vérification du rôle métier côté serveur : ne jamais se fier au flag
        // "canValidateTickets" calculé côté front (PartnerPermissionService), qui
        // ne sert qu'à l'affichage. Adapter le nom de la méthode ci-dessous à
        // votre entité Agent si elle diffère (ex: $agent->hasPermission('validate_tickets')).
        if (method_exists($agent, 'canValidateTickets') && !$agent->canValidateTickets()) {
            return new JsonResponse(['success' => false, 'message' => "Vous n'avez pas la permission de valider des billets."], 403);
        }

        return $agent;
    }

    /**
     * Vérifie que le billet appartient bien à l'agence de l'agent connecté.
     * Retourne une JsonResponse 403 si ce n'est pas le cas, ou null si c'est ok.
     */
    private function denyIfOutsideAgentAgency(Ticket $ticket, Agent $agent): ?JsonResponse
    {
        $ticketAgency = $ticket->getReservation()?->getTrip()?->getAgency();
        $agentAgency = method_exists($agent, 'getAgency') ? $agent->getAgency() : null;

        if (!$ticketAgency || !$agentAgency || $ticketAgency->getId() !== $agentAgency->getId()) {
            return new JsonResponse([
                'success' => false,
                'error' => "Ce billet n'appartient pas à votre agence.",
                'message' => "Ce billet n'appartient pas à votre agence.",
            ], 403);
        }

        return null;
    }

    /**
     * Recherche un billet d'abord par jeton QR (canal principal, scan caméra),
     * puis par numéro de billet au format "TKT-<id>" (saisie manuelle).
     */
    private function findTicketByCode(string $qrCodeToken, string $ticketCode): ?Ticket
    {
        $repo = $this->em->getRepository(Ticket::class);

        if ($qrCodeToken !== '') {
            $ticket = $repo->findOneBy(['qrCodeToken' => $qrCodeToken]);
            if ($ticket) {
                return $ticket;
            }
        }

        if ($ticketCode !== '') {
            $id = (int) preg_replace('/\D/', '', $ticketCode);
            if ($id > 0) {
                return $repo->find($id);
            }
        }

        return null;
    }

    private function notifyPassengerOfBoarding(Ticket $ticket): ?Notification
    {
        $user = $ticket->getReservation()?->getUser();
        if (!$user) {
            return null;
        }

        $notification = new Notification();
        $notification->setRecipientType('user')
            ->setRecipientId($user->getId())
            ->setTitle('Billet validé')
            ->setContent(sprintf(
                'Votre billet pour le trajet %s → %s a été validé.',
                $ticket->getReservation()->getTrip()?->getDepartureCity() ?? '',
                $ticket->getReservation()->getTrip()?->getArrivalCity() ?? '',
            ))
            ->setCategory('TICKET');
        $this->em->persist($notification);

        return $notification;
    }

    /**
     * Sérialise un billet avec toutes les informations essentielles pour un
     * reçu / écran de validation : agence, trajet, bus, siège, agent validateur...
     */
    private function mapTicket(Ticket $ticket): array
    {
        $reservation = $ticket->getReservation();
        $agency = $reservation?->getTrip()?->getAgency();
        $agencyName = $agency?->getName() ?? '';
        $trip = $reservation?->getTrip();
        $departureTime = $trip?->getDepartureTime();
        $arrivalTime = $trip?->getEstimatedArrivalTime();
        $price = $reservation?->getTotalAmount() ?? 0;
        $statusMap = [
            'en_attente' => 'Actif',
            'embarque' => 'Utilisé',
            'annule' => 'Annulé',
        ];
        $isCancelled = $ticket->getStatus() === 'annule';
        $departureCity = $trip?->getDepartureCity() ?? $trip?->getDeparturePoint()?->getCity() ?? '';
        $arrivalCity = $trip?->getArrivalCity() ?? $trip?->getArrivalPoint()?->getCity() ?? '';
        $validatedByAgent = $ticket->getValidatedByAgent();

        return [
            'id' => $ticket->getId(),
            'reservationId' => $reservation?->getId(),
            'ticketNumber' => 'TKT-' . $ticket->getId(),
            'passengerName' => $ticket->getPassengerName(),
            'passengerPhone' => $ticket->getPassengerPhone(),
            'agencyName' => $agencyName,
            'tripNumber' => $trip ? 'TRIP-' . $trip->getId() : null,
            'departureCity' => $departureCity,
            'arrivalCity' => $arrivalCity,
            // Alias "origin"/"destination" consommés par l'écran de validation partenaire.
            'origin' => $departureCity,
            'destination' => $arrivalCity,
            'departureTime' => $departureTime ? $departureTime->format('c') : null,
            'arrivalTime' => $arrivalTime ? $arrivalTime->format('c') : null,
            'departureDate' => $departureTime ? $departureTime->format('Y-m-d') : null,
            'seatNumber' => (string)$ticket->getSeatNumber(),
            'busLicensePlate' => $trip?->getBus()?->getRegistrationNumber() ?? '',
            // Un billet annulé ne doit plus jamais exposer de QR code exploitable.
            'qrCode' => $isCancelled ? null : $ticket->getQrCodeToken(),
            'price' => $price,
            'status' => $statusMap[$ticket->getStatus()] ?? 'Expiré',
            'isCancelled' => $isCancelled,
            'canDisplayDetails' => !$isCancelled,
            'validatedByAgentName' => ($validatedByAgent && method_exists($validatedByAgent, 'getFullName'))
                ? $validatedByAgent->getFullName()
                : null,
            'boardingTime' => $ticket->getValidatedAt()?->format('H:i'),
            'createdAt' => $ticket->getCreatedAt()?->format('c'),
            'updatedAt' => $ticket->getValidatedAt()?->format('c'),
        ];
    }
}