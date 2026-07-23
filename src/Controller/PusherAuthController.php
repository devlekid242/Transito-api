<?php

namespace App\Controller;

use App\Entity\User;
use Pusher\Pusher;
use App\Repository\AgentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PusherAuthController extends AbstractController
{

    public function __construct(
        private AgentRepository $agentRepository,
    ) {}

    #[Route('/api/pusher/auth', name: 'api_pusher_auth', methods: ['POST'])]
    public function auth(Request $request, Pusher $pusher): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $socketId = $data['socket_id'] ?? null;
        $channelName = $data['channel_name'] ?? null;

        if (!$socketId || !$channelName) {
            return new JsonResponse(['error' => 'socket_id et channel_name sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isChannelAllowed($channelName, $user)) {
            return new JsonResponse(['error' => "Vous n'êtes pas autorisé à écouter ce canal."], Response::HTTP_FORBIDDEN);
        }

        $signature = $pusher->socket_auth($channelName, $socketId);

        return new Response($signature, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    /**
     * 👈 CORRIGÉ / COMPLÉTÉ :
     * - private-user-{id}  : uniquement soi-même (inchangé).
     * - private-global     : diffusions "vraiment globales" (annonces
     *   générales) — ouvertes à tout utilisateur authentifié.
     * - private-agency-{id} : NOUVEAU — réservé aux agents rattachés à CETTE
     *   agence (app partenaire), ou aux admins. Empêche un agent de l'agence
     *   B de s'abonner aux notifications internes de l'agence A.
     *
     * ⚠️ Adapte `$user->getAgent()?->getAgency()?->getId()` aux vrais noms
     * de méthodes de tes entités User/Agent/Agency si différents.
     */
    private function isChannelAllowed(string $channelName, User $user): bool
    {
        if ($channelName === 'private-user-' . $user->getId()) {
            return true;
        }

        if ($channelName === 'private-global') {
            return true;
        }

        if (str_starts_with($channelName, 'private-agency-')) {
            if ($this->isGranted('ROLE_ADMIN')) {
                return true;
            }

            // get agent
            $agent = $this->agentRepository->findOneBy(['user' => $user]) ;
            $agencyId = $agent?->getAgency()?->getId();
            return $agencyId !== null && $channelName === 'private-agency-' . $agencyId;
        }

        return false;
    }

}
