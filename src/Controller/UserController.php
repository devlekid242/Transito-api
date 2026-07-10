<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Agent;
use App\Entity\Agency;
use App\Repository\AgencyRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    public function __construct(private AgentRepository $agentRepository) {}


    public function currentUser(AgentRepository $agentRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $profile = $this->serializeUser($user);
        $agent = $agentRepository->findOneBy(['user' => $user]);
        if ($agent) {
            $profile['agent'] = [
                'id' => $agent->getId(),
                'agentRole' => $agent->getAgentRole(),
                'status' => $agent->getStatus(),
                'agency' => $agent->getAgency() ? $this->serializeAgency($agent->getAgency()) : null,
            ];
        }

        return $this->json($profile);
    }


    public function update(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // Champs obligatoires côté entité (Assert\NotBlank) : on refuse une valeur vide explicite
        $requiredFields = ['fullName', 'phoneNumber', 'villeResidence', 'quartier'];
        foreach ($requiredFields as $field) {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') {
                return $this->json(
                    ['message' => "Le champ \"{$field}\" ne peut pas être vide."],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        if (array_key_exists('fullName', $data)) {
            $user->setFullName((string) $data['fullName']);
        }
        if (array_key_exists('email', $data)) {
            $user->setEmail($data['email'] !== null ? (string) $data['email'] : null);
        }
        if (array_key_exists('phoneNumber', $data)) {
            $user->setPhoneNumber((string) $data['phoneNumber']);
        }

        // --- Champs auparavant absents du contrôleur ---
        if (array_key_exists('villeResidence', $data)) {
            $user->setVilleResidence((string) $data['villeResidence']);
        }
        if (array_key_exists('quartier', $data)) {
            $user->setQuartier((string) $data['quartier']);
        }
        if (array_key_exists('emergencyContactName', $data)) {
            $user->setEmergencyContactName((string) $data['emergencyContactName']);
        }
        if (array_key_exists('emergencyContactPhone', $data)) {
            $user->setEmergencyContactPhone((string) $data['emergencyContactPhone']);
        }
        // --- Fin des champs ajoutés ---

        if (array_key_exists('prefNotifications', $data)) {
            $user->setPrefNotifications((int) $data['prefNotifications']);
        }
        if (array_key_exists('prefLanguage', $data)) {
            $user->setPrefLanguage((string) $data['prefLanguage']);
        }
        if (array_key_exists('prefDarkMode', $data)) {
            $user->setPrefDarkMode((int) $data['prefDarkMode']);
        }

        $em->persist($user);
        $em->flush();

        $profile = $this->serializeUser($user);

        return $this->json($profile);
    }

    public function updatePhoto(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $request->files->get('profile_photo');
        if (!$file) {
            return $this->json(['message' => 'Aucun fichier fourni.'], Response::HTTP_BAD_REQUEST);
        }

        // Simple file save logic — in production, use proper file storage service (AWS S3, etc.)
        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profile-photos';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }

        $filename = uniqid('photo_' . $user->getId() . '_') . '.' . $file->guessExtension();
        $file->move($uploadsDir, $filename);

        $photoUrl = '/uploads/profile-photos/' . $filename;
        $user->setProfilePhotoUrl($photoUrl);

        $em->persist($user);
        $em->flush();

        return $this->json([
            'message' => 'Photo de profil mise à jour.',
            'profilePhotoUrl' => $photoUrl,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $oldPassword = $data['old_password'] ?? null;
        $newPassword = $data['new_password'] ?? null;

        if (!$oldPassword || !$newPassword) {
            return $this->json(['message' => 'old_password et new_password sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$passwordHasher->isPasswordValid($user, $oldPassword)) {
            return $this->json(['message' => 'Ancien mot de passe incorrect.'], Response::HTTP_BAD_REQUEST);
        }

        $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        $em->persist($user);
        $em->flush();

        return $this->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }

    #[Route('/api/users/staff', name: 'api_users_get_staff', methods: ['GET'])]
    public function getStaffUsers(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $this->getAuthenticatedAgency($this->agentRepository);
        if (!$agency) {
            return $this->json(['message' => 'Aucune agence associée à l\'utilisateur authentifié.'], Response::HTTP_FORBIDDEN);
        }

        $agents = $this->agentRepository->findBy(['agency' => $agency]);
        $staffUsers = array_map(function (Agent $agent) {
            $user = $agent->getUser();
            return [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'email' => $user->getEmail(),
                'phoneNumber' => $user->getPhoneNumber(),
                'roles' => $user->getRoles(),
                'agentRole' => $agent->getAgentRole(),
                'status' => $agent->getStatus(),
            ];
        }, $agents);
        return $this->json($staffUsers);
    }

    #[Route('/api/users/staff', name: 'api_users_create_staff', methods: ['POST'])]
    public function createStaff(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, AgentRepository $agentRepo): JsonResponse
    {
        // Only admins may create staff via this endpoint
        if (!$this->isGranted('ROLE_PARTNER')) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        // return $this->json($payload);

        $fullName = $payload['fullName'] ?? null;
        $email = $payload['email'] ?? null;
        $phoneNumber = $payload['phoneNumber'] ?? null;
        $password = $payload['password'] ?? null;
        $villeResidence = $payload['ville'] ?? null;
        $quartier = $payload['quartier'] ?? null;

        if (!$fullName || !$phoneNumber) {
            return $this->json(['message' => 'fullName et phoneNumber sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        if(!$villeResidence || !$quartier){
            return $this->json(['message' => 'la ville de residence et le quartier sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        // uniqueness checks
        $existing = $em->getRepository(User::class)->findOneBy(['phoneNumber' => $phoneNumber]);
        if ($existing) {
            return $this->json(['message' => 'Un utilisateur avec ce numéro existe déjà.'], Response::HTTP_CONFLICT);
        }
        if ($email) {
            $existingEmail = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingEmail) {
                return $this->json(['message' => 'Un utilisateur avec cet email existe déjà.'], Response::HTTP_CONFLICT);
            }
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setPhoneNumber($phoneNumber);
        $user->setVilleResidence($villeResidence);
        $user->setQuartier($quartier);
        $user->setRoles(['ROLE_USER', 'ROLE_PARTNER']);

        if (!$password) {
            try {
                $password = bin2hex(random_bytes(6));
            } catch (\Exception $e) {
                $password = uniqid('pw_', true);
            }
        }

        $hashed = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashed);

        $em->persist($user);

        $agency = $this->getAuthenticatedAgency($agentRepo);        

        if ($agency) {
            $agent = new Agent();
            $agent->setUser($user);
            $agent->setAgency($agency);
            $agent->setAgentRole($agentData['agentRole'] ?? 'agent_quai');
            $agent->setStatus('active');
            $em->persist($agent);
        }

        $em->flush();

        $res = [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'roles' => $user->getRoles(),
        ];

        if ($agent) {
            $res['agent'] = [
                'id' => $agent->getId(),
                'agentRole' => $agent->getAgentRole(),
                'status' => $agent->getStatus(),
                'agency' => $agent->getAgency() ? ['id' => $agent->getAgency()->getId(), 'name' => $agent->getAgency()->getName()] : null,
            ];
        }

        return $this->json($res, Response::HTTP_CREATED);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'villeResidence' => $user->getVilleResidence(),
            'quartier' => $user->getQuartier(),
            'role' => $user->getRoles()[0] ?? 'Utilisateur',
            'prefNotifications' => $user->getPrefNotifications(),
            'prefLanguage' => $user->getPrefLanguage(),
            'prefDarkMode' => $user->getPrefDarkMode(),
            'isActive' => $user->getStatus() === 'active',
            'emailVerified' => false,
            'phoneVerified' => false,
            'profilePhotoUrl' => $user->getProfilePhotoUrl(),
            'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeAgency(Agency $agency): array
    {
        return [
            'id' => $agency->getId(),
            'name' => $agency->getName(),
            'registrationNumber' => $agency->getRegistrationNumber(),
            'address' => $agency->getAddress(),
            'bannerUrl' => $agency->getBannerUrl(),
            'logoUrl' => $agency->getLogoUrl(),
            'websiteUrl' => $agency->getWebsiteUrl(),
            'mapUrl' => $agency->getMapUrl(),
            'description' => $agency->getDescription(),
            'phone' => $agency->getPhone(),
            'email' => $agency->getEmail(),
            'status' => $agency->getStatus(),
            'ratingCache' => $agency->getRatingCache(),
            'createdAt' => $agency->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'documents' => array_map(fn($doc) => [
                'id' => $doc->getId(),
                'name' => $doc->getName(),
                'fileUrl' => $doc->getFileUrl(),
                'type' => $doc->getType(),
                'status' => $doc->getStatus(),
                'expiryDate' => $doc->getExpiryDate()?->format(\DateTimeInterface::ATOM),
                'createdAt' => $doc->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ], iterator_to_array($agency->getDocuments())),
        ];
    }

    private function getAuthenticatedAgency(AgentRepository $agentRepository): ?Agency
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $agent = $agentRepository->findOneBy(['user' => $user]);
        return $agent?->getAgency();
    }
}
