<?php

namespace App\Controller;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enregistrement / suppression des tokens d'appareil (FCM) utilisés pour le
 * push natif (barre de notification du téléphone), en complément du canal
 * Pusher qui gère le temps réel pendant que l'app est ouverte.
 */
class DeviceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DeviceTokenRepository $deviceTokenRepository,
    ) {}

    #[Route('/api/devices/register', name: 'api_devices_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $token = trim((string)($data['token'] ?? ''));
        $platform = $data['platform'] ?? 'android';

        if (!$token) {
            return new JsonResponse(['error' => 'Le token est requis.'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($platform, ['ios', 'android', 'web'], true)) {
            $platform = 'android';
        }

        // Un même token peut revenir (changement de compte sur le même
        // appareil, réinstallation...) : on le réassigne plutôt que de créer
        // un doublon, sinon la contrainte d'unicité échouerait de toute façon.
        $deviceToken = $this->deviceTokenRepository->findByToken($token);
        if (!$deviceToken) {
            $deviceToken = new DeviceToken();
            $deviceToken->setToken($token);
        }

        $deviceToken->setUser($user);
        $deviceToken->setPlatform($platform);
        $deviceToken->setUpdatedAt(new \DateTime());

        $this->em->persist($deviceToken);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'id' => $deviceToken->getId()], Response::HTTP_OK);
    }

    #[Route('/api/devices/unregister', name: 'api_devices_unregister', methods: ['POST'])]
    public function unregister(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $token = trim((string)($data['token'] ?? ''));
        if (!$token) {
            return new JsonResponse(['error' => 'Le token est requis.'], Response::HTTP_BAD_REQUEST);
        }

        $deviceToken = $this->deviceTokenRepository->findByToken($token);
        // On ne supprime que si le token appartient bien à l'utilisateur courant.
        if ($deviceToken && $deviceToken->getUser()?->getId() === $user->getId()) {
            $this->em->remove($deviceToken);
            $this->em->flush();
        }

        return new JsonResponse(['ok' => true], Response::HTTP_OK);
    }
}