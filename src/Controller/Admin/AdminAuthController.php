<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/auth')]
class AdminAuthController extends AbstractController
{
    /**
     * Get current admin info
     */
    #[Route('/me', name: 'api_admin_auth_me', methods: ['GET'])]
    public function me(
        AdminRepository $adminRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        // Check if admin is active
        if ($admin->getStatus() !== 'active') {
            return $this->json(['error' => 'Admin account is ' . $admin->getStatus()], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->normalizeAdmin($admin, $user));
    }

    /**
     * Get admin permissions
     */
    #[Route('/permissions', name: 'api_admin_auth_permissions', methods: ['GET'])]
    public function permissions(
        AdminRepository $adminRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        $permissions = $this->getAdminPermissions($admin);

        return $this->json([
            'adminRole' => $admin->getAdminRole(),
            'permissions' => $permissions,
        ]);
    }

    /**
     * Logout (just return success, token invalidation handled by frontend)
     */
    #[Route('/logout', name: 'api_admin_auth_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        return $this->json(['message' => 'Logged out successfully']);
    }

    /**
     * Update admin last activity
     */
    #[Route('/activity', name: 'api_admin_auth_activity', methods: ['POST'])]
    public function updateActivity(
        AdminRepository $adminRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $admin = $adminRepository->findByUser($user);
        if (!$admin) {
            return $this->json(['error' => 'Admin not found'], Response::HTTP_FORBIDDEN);
        }

        $admin->setLastLoginAt(new \DateTime());
        $em->flush();

        return $this->json(['message' => 'Activity updated']);
    }

    /**
     * Normalize admin data for response
     */
    private function normalizeAdmin(Admin $admin, User $user): array
    {
        return [
            'id' => $admin->getId(),
            'user' => [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'email' => $user->getEmail(),
                'phoneNumber' => $user->getPhoneNumber(),
                'profilePhotoUrl' => $user->getProfilePhotoUrl(),
            ],
            'adminRole' => $admin->getAdminRole(),
            'status' => $admin->getStatus(),
            'permissions' => $admin->getPermissions() ?? [],
            'department' => $admin->getDepartment(),
            'lastLoginAt' => $admin->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $admin->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'canViewUsers' => $admin->hasPermission('view_users'),
            'canEditUsers' => $admin->hasPermission('edit_users'),
            'canViewFinance' => $admin->hasPermission('view_finance'),
            'canApproveWithdrawals' => $admin->hasPermission('approve_withdrawals'),
            'canViewAgencies' => $admin->hasPermission('view_agencies'),
            'canEditAgencies' => $admin->hasPermission('edit_agencies'),
            'canViewSupport' => $admin->hasPermission('view_support'),
            'canRespondSupport' => $admin->hasPermission('respond_support'),
        ];
    }

    /**
     * Get permission list based on admin role
     */
    private function getAdminPermissions(Admin $admin): array
    {
        $rolePermissions = [
            'SUPER_ADMIN' => [
                'view_users',
                'edit_users',
                'delete_users',
                'suspend_users',
                'view_finance',
                'approve_withdrawals',
                'approve_refunds',
                'view_agencies',
                'edit_agencies',
                'suspend_agencies',
                'verify_kyc',
                'view_support',
                'respond_support',
                'view_reports',
                'export_data',
                'manage_admins',
                'view_settings',
                'edit_settings',
            ],
            'FINANCE_ADMIN' => [
                'view_users',
                'view_finance',
                'approve_withdrawals',
                'approve_refunds',
                'view_agencies',
                'view_reports',
                'export_data',
            ],
            'MODERATION_ADMIN' => [
                'view_users',
                'edit_users',
                'suspend_users',
                'view_agencies',
                'edit_agencies',
                'suspend_agencies',
                'verify_kyc',
                'view_support',
            ],
            'SUPPORT_ADMIN' => [
                'view_users',
                'view_agencies',
                'view_support',
                'respond_support',
            ],
        ];

        // Get role-based permissions
        $rolePerms = $rolePermissions[$admin->getAdminRole()] ?? [];

        // Merge with custom permissions if any
        $customPerms = $admin->getPermissions() ?? [];

        return array_unique(array_merge($rolePerms, $customPerms));
    }
}
