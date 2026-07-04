<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Agent;
use App\Repository\UserRepository;
use App\Repository\AgentRepository;
use App\Service\RefreshTokenService;
use App\Service\TwilioService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepository,
        AgentRepository $agentRepository,
        UserPasswordHasherInterface $passwordHasher,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenService $refreshTokenService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $phoneNumber = $payload['phoneNumber'] ?? null;
        $email = $payload['email'] ?? null;
        $password = $payload['password'] ?? null;

        // Accept either phoneNumber or email as identifier for login
        $identifier = $phoneNumber ?? $email;

        if (!$identifier || !$password) {
            return $this->json(['message' => 'Identifiant (phoneNumber ou email) et password sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = $userRepository->findOneBy(['email' => $identifier]);
        } else {
            $user = $userRepository->findOneBy(['phoneNumber' => $identifier]);
        }

        if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['message' => 'Identifiants invalides.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = $jwtManager->create($user);
        $refreshToken = $refreshTokenService->issueForUser($user);

        // Check if user is also an agent
        $agent = $agentRepository->findOneBy(['user' => $user]);

        $userData = [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'roles' => $user->getRoles(),
            'profilePhotoUrl' => $user->getProfilePhotoUrl(),
        ];

        if ($agent) {
            $userData['agent'] = [
                'agentRole' => $agent->getAgentRole(),
                'status' => $agent->getStatus(),
                'agency' => $agent->getAgency() ? [
                    'id' => $agent->getAgency()->getId(),
                    'name' => $agent->getAgency()->getName(),
                ] : null,
            ];
        }

        return $this->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refreshToken['plain'],
            'refresh_expires_at' => $refreshToken['entity']->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'user' => $userData,
        ]);
    }

    #[Route('/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(
        Request $request,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenService $refreshTokenService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $plainRefreshToken = $payload['refresh_token'] ?? null;
        if (!$plainRefreshToken) {
            return $this->json(['message' => 'refresh_token est requis.'], Response::HTTP_BAD_REQUEST);
        }

        $rotatedToken = $refreshTokenService->rotate($plainRefreshToken);

        if (!$rotatedToken) {
            return $this->json(['message' => 'Refresh token invalide ou expiré.'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $rotatedToken['entity']->getUser();
        $token = $jwtManager->create($user);

        return $this->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $rotatedToken['plain'],
            'refresh_expires_at' => $rotatedToken['entity']->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/request-reset', name: 'api_auth_request_reset', methods: ['POST'])]
    public function requestReset(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        TwilioService $twilioService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $phoneNumber = $payload['phoneNumber'] ?? null;
        if (!$phoneNumber) {
            return $this->json(['message' => 'phoneNumber est requis.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $userRepository->findOneBy(['phoneNumber' => $phoneNumber]);
        if (!$user) {
            // don't reveal existence
            return $this->json(['message' => 'Si ce numéro est associé à un compte, un code sera envoyé.']);
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = (new \DateTime())->modify('+15 minutes');

        $user->setPasswordResetCode($code);
        $user->setPasswordResetExpiresAt($expiresAt);
        $em->persist($user);
        $em->flush();

        $message = sprintf("Votre code de récupération Transito est : %s (valable 15 minutes)", $code);
        $sent = $twilioService->sendWhatsApp(str_contains($phoneNumber, "+242") ? $phoneNumber : "+242{$phoneNumber}", $message);

        if (!$sent) {

            return $this->json(['message' => 'Impossible d\'envoyer le code pour le moment.', 'data' => $sent], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['message' => 'Code envoyé si le numéro est actif.']);
    }

    #[Route('/verify-reset', name: 'api_auth_verify_reset', methods: ['POST'])]
    public function verifyReset(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $phoneNumber = $payload['phoneNumber'] ?? null;
        $code = $payload['code'] ?? null;
        $newPassword = $payload['newPassword'] ?? null;

        if (!$phoneNumber || !$code || !$newPassword) {
            return $this->json(['message' => 'phoneNumber, code et newPassword sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $userRepository->findOneBy(['phoneNumber' => $phoneNumber]);
        if (!$user) {
            return $this->json(['message' => 'Code invalide ou expiré.'], Response::HTTP_BAD_REQUEST);
        }

        if ($user->getPasswordResetCode() !== $code) {
            return $this->json(['message' => 'Code invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $expiresAt = $user->getPasswordResetExpiresAt();
        if (!$expiresAt || $expiresAt < new \DateTime()) {
            return $this->json(['message' => 'Code expiré.'], Response::HTTP_BAD_REQUEST);
        }

        $hashed = $passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashed);
        $user->setPasswordResetCode(null);
        $user->setPasswordResetExpiresAt(null);
        $em->persist($user);
        $em->flush();

        return $this->json(['message' => 'Mot de passe mis à jour.']);
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        AgentRepository $agentRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenService $refreshTokenService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $fullName = $payload['fullName'] ?? null;
        $email = $payload['email'] ?? null;
        $phoneNumber = $payload['phoneNumber'] ?? null;
        $password = $payload['password'] ?? null;

        if (!$fullName || !$phoneNumber || !$password) {
            return $this->json(['message' => 'fullName, phoneNumber et password sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        // Check if user already exists
        if ($userRepository->findOneBy(['phoneNumber' => $phoneNumber])) {
            return $this->json(['message' => 'Un utilisateur avec ce numéro existe déjà.'], Response::HTTP_CONFLICT);
        }

        // Create new user
        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setPhoneNumber($phoneNumber);
        $user->setRoles(['ROLE_USER']);

        $hashedPassword = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $em->persist($user);
        $em->flush();

        // Issue JWT and refresh token
        $token = $jwtManager->create($user);
        $refreshToken = $refreshTokenService->issueForUser($user);

        $userData = [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'roles' => $user->getRoles(),
        ];


        return $this->json(
            [
                'message' => 'Utilisateur créé avec succès.',
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => $refreshToken['plain'],
                'refresh_expires_at' => $refreshToken['entity']->getExpiresAt()?->format(\DateTimeInterface::ATOM),
                'user' => $userData,
            ],
            Response::HTTP_CREATED
        );
    }
}
