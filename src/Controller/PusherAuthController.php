<?php

namespace App\Controller;

use App\Entity\User;
use Pusher\Pusher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PusherAuthController extends AbstractController
{
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

    private function isChannelAllowed(string $channelName, User $user): bool
    {
        return $channelName === 'private-user-' . $user->getId() || $channelName === 'private-global';
    }
}