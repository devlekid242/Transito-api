<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\OtpChallenge;
use App\Entity\RegistrationToken;
use App\Entity\Agent;
use App\Entity\Admin;
use App\Repository\UserRepository;
use App\Repository\OtpChallengeRepository;
use App\Repository\RegistrationTokenRepository;
use App\Repository\AgentRepository;
use App\Repository\AdminRepository;
use App\Service\AdminNotificationService;
use App\Service\RefreshTokenService;
use App\Service\TwilioService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;


#[Route('/api/auth')]
class AuthController extends AbstractController
{
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepository,
        AgentRepository $agentRepository,
        AdminRepository $adminRepository,
        UserPasswordHasherInterface $passwordHasher,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenService $refreshTokenService,
        EntityManagerInterface $em,
        AdminNotificationService $adminNotificationService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $phoneNumber = $payload['phoneNumber'] ?? null;
        $email = $payload['email'] ?? null;
        $password = $payload['password'] ?? null;
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

        // check if user is not suspended
        if ($user->getStatus() !== 'active') {
            return $this->json(['message' => 'Compte utilisateur suspendu ou inactif.'], Response::HTTP_FORBIDDEN);
        }

        $agent = $agentRepository->findOneBy(['user' => $user]);
        $admin = $adminRepository->findByUser($user);

        if ($agent && $agent->getStatus() !== 'actif') {
            return $this->json(['message' => 'Compte agent inactif. Contactez l’administrateur de votre agence.'], Response::HTTP_FORBIDDEN);
        }
        if ($agent && $agent->getAgency()?->getStatus() !== 'active') {
            return $this->json(['message' => 'Cette agence est actuellement inactive.'], Response::HTTP_FORBIDDEN);
        }

        if ($admin) {
            if ($admin->getStatus() !== 'active') {
                return $this->json(['message' => 'Compte administrateur suspendu ou inactif.'], Response::HTTP_FORBIDDEN);
            }
            $admin->setLastLoginAt(new \DateTime());
            $em->flush();
        }

        $user->setLastLoginAt(new \DateTime());
        $em->flush();

        $token = $jwtManager->create($user);
        $refreshToken = $refreshTokenService->issueForUser($user);

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

        if ($admin) {
            $userData['admin'] = [
                'adminRole' => $admin->getAdminRole(),
                'status' => $admin->getStatus(),
                'permissions' => $admin->getPermissions() ?? [],
                'department' => $admin->getDepartment(),
            ];
        }

        $accountType = 'client';
        if ($admin) {
            $accountType = 'administrateur';
        } elseif ($agent) {
            $accountType = 'agent';
        }

        $adminNotificationService->notifyEvent(
            'Connexion utilisateur',
            sprintf('%s (%s) s\'est connecté à la plateforme.', $user->getFullName() ?? $user->getEmail() ?? 'Un utilisateur', $accountType),
            'INFO',
            ['userId' => $user->getId(), 'type' => 'user_login', 'accountType' => $accountType]
        );

        return $this->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refreshToken['plain'],
            'refresh_expires_at' => $refreshToken['entity']->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'user' => $userData,
        ]);
    }

    #[Route('/request-otp', name: 'api_auth_request_otp', methods: ['POST'])]
    public function requestOtp(
        Request $request,
        UserRepository $userRepository,
        OtpChallengeRepository $otpRepository,
        EntityManagerInterface $em,
        TwilioService $twilioService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $phoneNumber = $this->normalizePhone($payload['phoneNumber'] ?? null);
        if (!$phoneNumber) {
            return $this->json(['message' => 'Numéro de téléphone invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $userRepository->findOneBy(['phoneNumber' => $phoneNumber]);
        // OTP public = authentification des clients uniquement. Les comptes
        // Agent/Partner/Admin restent sur leur authentification staff.
        if ($user && ($user->getAgent() || $user->getAdmin() || $this->hasRole($user, 'ROLE_PARTNER'))) {
            return $this->json(['message' => 'Utilisez l’accès réservé à votre espace professionnel.'], Response::HTTP_FORBIDDEN);
        }
        if ($user && $user->getStatus() !== 'active') {
            return $this->json(['message' => 'Compte inactif ou suspendu.'], Response::HTTP_FORBIDDEN);
        }

        $now = new \DateTimeImmutable();
        $latest = $otpRepository->findLatestForPhone($phoneNumber);
        if ($latest && $latest->getRequestedAt() > $now->modify('-45 seconds') && !$latest->isConsumed()) {
            return $this->json(['message' => 'Un code a déjà été envoyé. Veuillez patienter.', 'retryAfter' => 45], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = new OtpChallenge(
            $phoneNumber,
            password_hash($code, PASSWORD_DEFAULT),
            $now,
            $now->modify('+5 minutes')
        );
        $em->persist($challenge);
        $em->flush();

        // return $this->json($phoneNumber, Response::HTTP_BAD_REQUEST);

        // $sent = $twilioService->sendWhatsApp($phoneNumber, sprintf('Votre code Transito est : %s. Il expire dans 5 minutes.', $code));
        $tmp_phone = "+242065804642";
        $sent = $twilioService->sendWhatsApp($tmp_phone, sprintf('Votre code Transito est : %s. Il expire dans 5 minutes.', $code));
        if (!$sent) {
            $em->remove($challenge);
            $em->flush();
            return $this->json(['message' => 'Impossible d’envoyer le code pour le moment.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'message' => 'Code envoyé.',
            'expiresIn' => 300,
            'isNewUser' => $user === null,
        ]);
    }

    #[Route('/verify-otp', name: 'api_auth_verify_otp', methods: ['POST'])]
    public function verifyOtp(
        Request $request,
        UserRepository $userRepository,
        OtpChallengeRepository $otpRepository,
        RegistrationTokenRepository $registrationTokenRepository,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenService $refreshTokenService,
        EntityManagerInterface $em
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $phoneNumber = $this->normalizePhone($payload['phoneNumber'] ?? null);
        $code = trim((string) ($payload['code'] ?? ''));
        if (!$phoneNumber || !preg_match('/^\d{6}$/', $code)) {
            return $this->json(['message' => 'Numéro et code OTP sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            // Un challenge OTP est consommable une seule fois, y compris sous
            // deux requêtes concurrentes. Le verrou DB est volontairement placé
            // avant password_verify()/consume().
            $challenge = $em->createQueryBuilder()
                ->select('o')
                ->from(OtpChallenge::class, 'o')
                ->andWhere('o.phoneNumber = :phone')
                ->setParameter('phone', $phoneNumber)
                ->orderBy('o.requestedAt', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if (!$challenge || $challenge->isConsumed() || $challenge->isExpired(new \DateTimeImmutable())) {
                $connection->rollBack();
                return $this->json(['message' => 'Code invalide ou expiré.'], Response::HTTP_BAD_REQUEST);
            }
            if ($challenge->getAttempts() >= 5) {
                $connection->rollBack();
                return $this->json(['message' => 'Trop de tentatives. Demandez un nouveau code.'], Response::HTTP_TOO_MANY_REQUESTS);
            }
            if (!password_verify($code, $challenge->getCodeHash())) {
                $challenge->incrementAttempts();
                $em->flush();
                $connection->commit();
                return $this->json(['message' => 'Code incorrect.'], Response::HTTP_BAD_REQUEST);
            }

            $user = $userRepository->findOneBy(['phoneNumber' => $phoneNumber]);
            if ($user && ($user->getAgent() || $user->getAdmin() || $this->hasRole($user, 'ROLE_PARTNER'))) {
                $connection->rollBack();
                return $this->json(['message' => 'Utilisez l’accès réservé à votre espace professionnel.'], Response::HTTP_FORBIDDEN);
            }
            if ($user && $user->getStatus() !== 'active') {
                $connection->rollBack();
                return $this->json(['message' => 'Compte inactif ou suspendu.'], Response::HTTP_FORBIDDEN);
            }

            $challenge->consume(new \DateTimeImmutable());

            // Compte existant : le code suffit, on connecte immédiatement.
            // Pas d'étape "nom" côté front dans ce cas (voir verify-login.page.html).
            if ($user) {
                $user->setPhoneVerified(true);
                $user->setLastLoginAt(new \DateTime());
                $em->flush();
                $connection->commit();

                $token = $jwtManager->create($user);
                $refreshToken = $refreshTokenService->issueForUser($user);

                return $this->json([
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                    'refresh_token' => $refreshToken['plain'],
                    'refresh_expires_at' => $refreshToken['entity']->getExpiresAt()?->format(\DateTimeInterface::ATOM),
                    'requiresProfile' => false,
                    'user' => [
                        'id' => $user->getId(),
                        'fullName' => $user->getFullName(),
                        'phoneNumber' => $user->getPhoneNumber(),
                        'email' => $user->getEmail(),
                        'roles' => $user->getRoles(),
                        'profilePhotoUrl' => $user->getProfilePhotoUrl(),
                    ],
                ]);
            }

            // Nouveau numéro : le code est validé mais aucun compte n'existe
            // encore. On émet un jeton de courte durée qui prouve que ce
            // numéro vient d'être vérifié ; il sera présenté avec le nom
            // complet sur /auth/complete-profile pour créer le compte.
            $plainToken = bin2hex(random_bytes(32));
            $now = new \DateTimeImmutable();
            $registrationToken = new RegistrationToken(
                $phoneNumber,
                hash('sha256', $plainToken),
                $now,
                $now->modify('+15 minutes')
            );
            $em->persist($registrationToken);
            $em->flush();
            $connection->commit();

            return $this->json([
                'requiresProfile' => true,
                'registrationToken' => $plainToken,
            ]);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    private function hasRole(User $user, string $role): bool
    {
        return in_array($role, $user->getRoles(), true);
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
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenService $refreshTokenService,
        AdminNotificationService $adminNotificationService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $fullName = $payload['fullName'] ?? null;
        $email = $payload['email'] ?? null;
        $phoneNumber = $payload['phoneNumber'] ?? null;
        $password = $payload['password'] ?? null;
        $villeResidence  = $payload['villeResidence'] ?? null;
        $quartier = $payload['quartier'] ?? null;
        $emergencyContactName = $payload['emergencyContactName'] ?? null;
        $emergencyContactPhone = $payload['emergencyContactPhone'] ?? null;

        if (!$fullName || !$phoneNumber || !$password) {
            return $this->json(['message' => 'fullName, phoneNumber et password sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        // Check if user already exists
        if ($userRepository->findOneBy(['phoneNumber' => $phoneNumber])) {
            return $this->json(['message' => 'Un utilisateur avec ce numéro existe déjà.'], Response::HTTP_CONFLICT);
        }

        // Create new user
        $user = new User();
        if ($fullName !== '') {
            $user->setFullName($fullName);
        } elseif (!$user->getFullName()) {
            return $this->json(['message' => 'Le nom complet est requis pour créer ce compte.'], Response::HTTP_BAD_REQUEST);
        }
        $user->setEmail($email);
        $user->setPhoneNumber($phoneNumber);
        $user->setVilleResidence($villeResidence);
        $user->setQuartier($quartier);
        $user->setEmergencyContactName($emergencyContactName);
        $user->setEmergencyContactPhone($emergencyContactPhone);
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

        // 👈 Notifier les admins du nouvel utilisateur enregistré
        $adminNotificationService->notifyEvent(
            'Nouvel utilisateur enregistré',
            sprintf('Un nouvel utilisateur "%s" (téléphone: %s) s\'est enregistré.', $user->getFullName(), $user->getPhoneNumber()),
            'USER_REGISTERED',
            ['userId' => $user->getId(), 'userName' => $user->getFullName(), 'phoneNumber' => $user->getPhoneNumber()]
        );

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

    #[Route('/complete-profile', name: 'api_auth_complete_profile', methods: ['POST'])]
    public function completeProfile(
        Request $request,
        UserRepository $userRepository,
        RegistrationTokenRepository $registrationTokenRepository,
        UserPasswordHasherInterface $passwordHasher,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenService $refreshTokenService,
        EntityManagerInterface $em,
        AdminNotificationService $adminNotificationService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $plainToken = trim((string) ($payload['registrationToken'] ?? ''));
        $fullName = trim((string) ($payload['fullName'] ?? ''));

        if ($plainToken === '') {
            return $this->json(['message' => 'La session de création du compte a expiré. Veuillez recommencer.'], Response::HTTP_BAD_REQUEST);
        }
        if ($fullName === '' || mb_strlen($fullName) > 100) {
            return $this->json(['message' => 'Le nom complet est requis pour créer le compte.'], Response::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        $registrationToken = $registrationTokenRepository->findByTokenHash(hash('sha256', $plainToken));

        if (!$registrationToken || $registrationToken->isConsumed() || $registrationToken->isExpired($now)) {
            return $this->json(['message' => 'La session de création du compte a expiré. Veuillez recommencer.'], Response::HTTP_BAD_REQUEST);
        }

        $phoneNumber = $registrationToken->getPhoneNumber();

        // Le numéro a pu être enregistré entretemps (double soumission, autre appareil...).
        if ($userRepository->findOneBy(['phoneNumber' => $phoneNumber])) {
            return $this->json(['message' => 'Un compte existe déjà pour ce numéro.'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setPhoneNumber($phoneNumber);
        $user->setFullName($fullName);
        $user->setRoles(['ROLE_USER']);
        $user->setStatus('active');
        $user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $user->setPhoneVerified(true);
        $user->setLastLoginAt(new \DateTime());

        $registrationToken->consume($now);

        $em->persist($user);
        $em->flush();

        $token = $jwtManager->create($user);
        $refreshToken = $refreshTokenService->issueForUser($user);

        try {
            $adminNotificationService->notifyEvent(
                'Nouveau compte client',
                sprintf('%s a créé son compte avec son numéro de téléphone.', $user->getFullName()),
                'INFO',
                ['userId' => $user->getId(), 'type' => 'user_otp_verified']
            );
        } catch (\Throwable) {
            // Le login ne dépend pas de la disponibilité du canal temps réel.
        }

        return $this->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refreshToken['plain'],
            'refresh_expires_at' => $refreshToken['entity']->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'user' => [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'phoneNumber' => $user->getPhoneNumber(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
                'profilePhotoUrl' => $user->getProfilePhotoUrl(),
            ],
        ]);
    }


    /**
     * Normalise un numéro de téléphone congolais en format E.164.
     *
     * Exemples acceptés :
     * +242060000001
     * 00242060000001
     * 242060000001
     * 060000001
     * 060 000 001
     *
     * Retourne toujours +242XXXXXXXXX ou null si le numéro est invalide.
     */
    private function normalizePhone(?string $phoneNumber): ?string
    {
        if ($phoneNumber === null) {
            return null;
        }

        $phone = trim($phoneNumber);

        if ($phone === '') {
            return null;
        }

        // Supprime les séparateurs courants.
        $phone = preg_replace('/[\s().-]+/', '', $phone) ?? '';

        // 00242XXXXXXXXX -> +242XXXXXXXXX
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        // 242XXXXXXXXX -> +242XXXXXXXXX
        if (str_starts_with($phone, '242')) {
            $phone = '+' . $phone;
        }

        // Format national : 0XXXXXXXX -> +242XXXXXXXXX
        if (preg_match('/^0([0-9]{8})$/', $phone, $matches)) {
            $phone = '+242' . $matches[1];
        }

        // Format final attendu.
        if (!preg_match('/^\+242[0-9]{9}$/', $phone)) {
            return null;
        }

        return $phone;
    }
}
