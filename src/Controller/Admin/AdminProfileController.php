<?php

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Entity\AdminActivityLog;
use App\Entity\User;
use App\Repository\AdminRepository;
use App\Repository\AdminActivityLogRepository;
use App\Service\ProfilePhotoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/admin/profile')]
class AdminProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AdminRepository $adminRepository,
        private AdminActivityLogRepository $activityLogRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private ProfilePhotoService $profilePhotoService,
    ) {}

    /**
     * Get current admin profile with KPIs and statistics
     */
    #[Route('/me', name: 'api_admin_profile_me', methods: ['GET'])]
    public function getProfile(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $this->adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        // Check if admin is active
        if ($admin->getStatus() !== 'active') {
            return $this->json(['error' => 'Admin account is ' . $admin->getStatus()], Response::HTTP_FORBIDDEN);
        }

        // Get activity statistics
        $activityStats = $this->activityLogRepository->getAdminActivityStats($admin);

        // Calculate account longevity
        $createdAt = $admin->getCreatedAt();
        $daysActive = $createdAt ? (int) $createdAt->diff(new \DateTime())->days : 0;

        // Get recent activity logs (last 10)
        $recentActivity = $this->activityLogRepository->findRecentByAdmin($admin, 10);

        $response = [
            'success' => true,
            'admin' => $this->normalizeProfile($admin, $user),
            'kpis' => [
                'totalActions' => $activityStats['total'],
                'accountStatus' => $admin->getStatus(),
                'adminRole' => $admin->getAdminRole(),
                'accountAgeDays' => $daysActive,
                'lastLoginAt' => $admin->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                'createdAt' => $admin->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'activityByType' => $activityStats['byType'],
            ],
            'recentActivity' => $this->normalizeActivityLogs($recentActivity),
        ];

        return $this->json($response);
    }

    /**
     * Update current admin's profile information
     */
    #[Route('/me', name: 'api_admin_profile_update', methods: ['PUT', 'PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $this->adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        if ($admin->getStatus() !== 'active') {
            return $this->json(['error' => 'Admin account is inactive'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        $changes = [];

        // Update user fields
        if (isset($payload['fullName'])) {
            $fullName = trim((string) $payload['fullName']);
            if ($fullName !== '' && $user->getFullName() !== $fullName) {
                $user->setFullName($fullName);
                $changes[] = 'fullName: ' . $fullName;
            }
        }

        if (isset($payload['email'])) {
            $email = trim((string) $payload['email']);
            if ($email !== '' && $user->getEmail() !== $email) {
                // Check if email is already used by another user
                $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existingUser && $existingUser->getId() !== $user->getId()) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Cette adresse email est déjà utilisée.',
                    ], Response::HTTP_CONFLICT);
                }
                $user->setEmail($email);
                $changes[] = 'email: ' . $email;
            }
        }

        if (isset($payload['phoneNumber'])) {
            $phoneNumber = trim((string) $payload['phoneNumber']);
            if ($phoneNumber !== '' && $user->getPhoneNumber() !== $phoneNumber) {
                // Check if phone is already used by another user
                $existingUser = $this->em->getRepository(User::class)->findOneBy(['phoneNumber' => $phoneNumber]);
                if ($existingUser && $existingUser->getId() !== $user->getId()) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Ce numéro de téléphone est déjà utilisé.',
                    ], Response::HTTP_CONFLICT);
                }
                $user->setPhoneNumber($phoneNumber);
                $changes[] = 'phoneNumber: ' . $phoneNumber;
            }
        }

        if (isset($payload['profilePhotoUrl'])) {
            $profilePhotoUrl = trim((string) $payload['profilePhotoUrl']);
            if ($profilePhotoUrl !== '' && $user->getProfilePhotoUrl() !== $profilePhotoUrl) {
                $user->setProfilePhotoUrl($profilePhotoUrl);
                $changes[] = 'profilePhotoUrl updated';
            }
        }

        if (isset($payload['department'])) {
            $department = trim((string) $payload['department']);
            if ($admin->getDepartment() !== $department) {
                $admin->setDepartment($department ?: null);
                $changes[] = 'department: ' . ($department ?: 'null');
            }
        }

        if (empty($changes)) {
            return $this->json([
                'success' => true,
                'message' => 'Aucune modification à enregistrer',
                'data' => $this->normalizeProfile($admin, $user),
            ]);
        }

        // Validate the user entity
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getPropertyPath() . ': ' . $error->getMessage();
            }
            return $this->json([
                'success' => false,
                'message' => 'Erreurs de validation',
                'errors' => $errorMessages,
            ], Response::HTTP_BAD_REQUEST);
        }

        // Save changes
        $this->em->persist($user);
        $this->em->persist($admin);
        $this->em->flush();

        // Log the profile update activity
        $this->logActivity(
            $admin,
            AdminActivityLog::ACTION_TYPE_PROFILE,
            'Profile updated',
            'User',
            (string) $user->getId(),
            implode(', ', $changes),
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        return $this->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès',
            'data' => $this->normalizeProfile($admin, $user),
        ]);
    }

    /**
     * Change current admin's password
     */
    #[Route('/me/password', name: 'api_admin_profile_change_password', methods: ['PUT', 'PATCH'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $this->adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        if ($admin->getStatus() !== 'active') {
            return $this->json(['error' => 'Admin account is inactive'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        $currentPassword = $payload['currentPassword'] ?? '';
        $newPassword = $payload['newPassword'] ?? '';
        $confirmPassword = $payload['confirmPassword'] ?? '';

        // Validate required fields
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            return $this->json([
                'success' => false,
                'message' => 'currentPassword, newPassword et confirmPassword sont requis.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if new passwords match
        if ($newPassword !== $confirmPassword) {
            return $this->json([
                'success' => false,
                'message' => 'Les nouveaux mots de passe ne correspondent pas.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify current password
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json([
                'success' => false,
                'message' => 'Le mot de passe actuel est incorrect.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check password strength (min 8 characters)
        if (strlen($newPassword) < 8) {
            return $this->json([
                'success' => false,
                'message' => 'Le mot de passe doit contenir au moins 8 caractères.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if new password is different from current
        if ($this->passwordHasher->isPasswordValid($user, $newPassword)) {
            return $this->json([
                'success' => false,
                'message' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Update password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();

        // Log the password change activity
        $this->logActivity(
            $admin,
            AdminActivityLog::ACTION_TYPE_AUTH,
            'Password changed',
            'User',
            (string) $user->getId(),
            null,
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        return $this->json([
            'success' => true,
            'message' => 'Mot de passe changé avec succès',
        ]);
    }

    /**
     * Upload/update profile photo
     */
    #[Route('/me/photo', name: 'api_admin_profile_photo', methods: ['POST'])]
    public function uploadPhoto(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $this->adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        if ($admin->getStatus() !== 'active') {
            return $this->json(['error' => 'Admin account is inactive'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('photo');
        
        if (!$file) {
            return $this->json([
                'success' => false,
                'message' => 'Aucun fichier photo fourni',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate file type and size
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB

        if (!in_array($file->getClientMimeType(), $allowedTypes)) {
            return $this->json([
                'success' => false,
                'message' => 'Type de fichier non autorisé. Types autorisés: JPEG, PNG, GIF, WebP',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > $maxSize) {
            return $this->json([
                'success' => false,
                'message' => 'La taille du fichier dépasse 2MB',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Handle file upload
        $photoData = $request->request->get('photoUrl');
        
        if ($photoData) {
            // If it's a URL (base64 or regular URL)
            $user->setProfilePhotoUrl($photoData);
        } elseif ($file) {
            // Handle actual file upload using the service
            try {
                $photoUrl = $this->profilePhotoService->uploadProfilePhoto($file, $user->getId());
                $user->setProfilePhotoUrl($photoUrl);
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], Response::HTTP_BAD_REQUEST);
            }
        } else {
            return $this->json([
                'success' => false,
                'message' => 'Aucun fichier photo ou URL fourni',
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($user);
        $this->em->flush();

        // Log the profile photo update activity
        $this->logActivity(
            $admin,
            AdminActivityLog::ACTION_TYPE_PROFILE,
            'Profile photo updated',
            'User',
            (string) $user->getId(),
            'Profile photo changed',
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        return $this->json([
            'success' => true,
            'message' => 'Photo de profil mise à jour avec succès',
            'data' => [
                'profilePhotoUrl' => $user->getProfilePhotoUrl(),
            ],
        ]);
    }

    /**
     * Remove profile photo
     */
    #[Route('/me/photo', name: 'api_admin_profile_photo_delete', methods: ['DELETE'])]
    public function removePhoto(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $this->adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        if ($admin->getStatus() !== 'active') {
            return $this->json(['error' => 'Admin account is inactive'], Response::HTTP_FORBIDDEN);
        }

        // Get current photo URL before removing
        $currentPhotoUrl = $user->getProfilePhotoUrl();
        
        // Delete the file if it exists
        if ($currentPhotoUrl) {
            $this->profilePhotoService->deleteProfilePhoto($currentPhotoUrl);
        }
        
        $user->setProfilePhotoUrl(null);
        
        $this->em->persist($user);
        $this->em->flush();

        // Log the profile photo removal activity
        $this->logActivity(
            $admin,
            AdminActivityLog::ACTION_TYPE_PROFILE,
            'Profile photo removed',
            'User',
            (string) $user->getId(),
            'Profile photo removed',
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        return $this->json([
            'success' => true,
            'message' => 'Photo de profil supprimée avec succès',
        ]);
    }

    /**
     * Get activity logs for current admin
     */
    #[Route('/activity', name: 'api_admin_profile_activity', methods: ['GET'])]
    public function getActivityLogs(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $this->adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $result = $this->activityLogRepository->findByAdminId($admin->getId(), $page, $limit);

        return $this->json([
            'success' => true,
            'data' => $this->normalizeActivityLogs($result['logs']),
            'pagination' => [
                'page' => $result['page'],
                'limit' => $result['limit'],
                'total' => $result['total'],
                'totalPages' => $result['pages'],
            ],
        ]);
    }

    /**
     * Normalize admin profile data for JSON response
     */
    private function normalizeProfile(Admin $admin, User $user): array
    {
        return [
            'id' => $admin->getId(),
            'userId' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'profilePhotoUrl' => $user->getProfilePhotoUrl(),
            'adminRole' => $admin->getAdminRole(),
            'status' => $admin->getStatus(),
            'permissions' => $admin->getPermissions() ?? [],
            'department' => $admin->getDepartment(),
            'notes' => $admin->getNotes(),
            'prefLanguage' => $user->getPrefLanguage(),
            'prefNotifications' => $user->getPrefNotifications(),
            'prefDarkMode' => $user->getPrefDarkMode(),
            'lastLoginAt' => $admin->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $admin->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $admin->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Normalize activity logs for JSON response
     *
     * @param AdminActivityLog[] $logs
     * @return array
     */
    private function normalizeActivityLogs(array $logs): array
    {
        $normalized = [];
        foreach ($logs as $log) {
            $normalized[] = [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'target' => $log->getTargetEntity() && $log->getTargetId() 
                    ? $log->getTargetEntity() . ' #' . $log->getTargetId()
                    : ($log->getTargetEntity() ?? 'N/A'),
                'details' => $log->getDetails(),
                'actionType' => $log->getActionType(),
                'ipAddress' => $log->getIpAddress(),
                'userAgent' => $log->getUserAgent(),
                'timestamp' => $log->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }
        return $normalized;
    }

    /**
     * Log an admin activity
     */
    private function logActivity(
        Admin $admin,
        string $actionType,
        string $action,
        ?string $targetEntity = null,
        ?string $targetId = null,
        ?string $details = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $log = AdminActivityLog::createAdminAction(
            $admin,
            $actionType,
            $action,
            $targetEntity,
            $targetId,
            $details,
            $ipAddress,
            $userAgent
        );

        $this->activityLogRepository->save($log);
    }
}