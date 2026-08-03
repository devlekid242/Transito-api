<?php

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Repository\AdminRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/admins')]
class AdminAdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private AdminRepository $adminRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('', name: 'api_admin_admins_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;

        $role = $request->query->get('role');
        $status = $request->query->get('status');
        $search = trim((string) $request->query->get('search', ''));

        $qb = $this->adminRepository->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->orderBy('a.createdAt', 'DESC');

        if ($role && in_array($role, ['SUPER_ADMIN', 'FINANCE_ADMIN', 'MODERATION_ADMIN', 'SUPPORT_ADMIN'], true)) {
            $qb->andWhere('a.adminRole = :role')->setParameter('role', $role);
        }

        if ($status && in_array(strtolower($status), ['active', 'inactive', 'suspended'], true)) {
            $qb->andWhere('a.status = :status')->setParameter('status', strtolower($status));
        }

        if ($search !== '') {
            $qb->andWhere('u.fullName LIKE :search OR u.email LIKE :search OR u.phoneNumber LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $admins = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeAdmin'], $admins),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('', name: 'api_admin_admins_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        $fullName = trim((string) ($payload['fullName'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phoneNumber = trim((string) ($payload['phoneNumber'] ?? ''));
        $password = $payload['password'] ?? null;
        $adminRole = $payload['adminRole'] ?? null;

        if ($fullName === '' || $email === '' || $phoneNumber === '' || $password === '' || $adminRole === null) {
            return $this->json([
                'success' => false,
                'message' => 'fullName, email, phoneNumber, password et adminRole sont requis.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'message' => 'Adresse email invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($adminRole, ['SUPER_ADMIN', 'FINANCE_ADMIN', 'MODERATION_ADMIN', 'SUPPORT_ADMIN'], true)) {
            return $this->json([
                'success' => false,
                'message' => 'Le rôle admin est invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($this->userRepository->findOneBy(['email' => $email])) {
            return $this->json([
                'success' => false,
                'message' => 'Cette adresse email est déjà utilisée.',
            ], Response::HTTP_CONFLICT);
        }

        if ($this->userRepository->findOneBy(['phoneNumber' => $phoneNumber])) {
            return $this->json([
                'success' => false,
                'message' => 'Ce numéro de téléphone est déjà utilisé.',
            ], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setPhoneNumber($phoneNumber);
        $user->setVilleResidence('N/A');
        $user->setQuartier('N/A');
        $user->setRoles(['ROLE_USER']);
        $user->setStatus('active');
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $admin = new Admin();
        $admin->setUser($user);
        $admin->setAdminRole($adminRole);
        $admin->setStatus('active');
        $admin->setPermissions(is_array($payload['permissions'] ?? null) ? $payload['permissions'] : []);
        $admin->setDepartment(trim((string) ($payload['department'] ?? '')) ?: null);
        $admin->setNotes(trim((string) ($payload['notes'] ?? '')) ?: null);

        $this->em->persist($admin);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'data' => $this->normalizeAdmin($admin),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_admins_detail', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function detail(int $id): JsonResponse
    {
        $admin = $this->adminRepository->find($id);
        if (!$admin) {
            return $this->json([
                'success' => false,
                'message' => 'Administrateur introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $this->normalizeAdmin($admin),
        ]);
    }

    #[Route('/{id}', name: 'api_admin_admins_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $admin = $this->adminRepository->find($id);
        if (!$admin) {
            return $this->json([
                'success' => false,
                'message' => 'Administrateur introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $admin->getUser();
        if (!$user) {
            return $this->json([
                'success' => false,
                'message' => 'Utilisateur lié introuvable.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (isset($payload['email'])) {
            $email = trim((string) $payload['email']);
            if ($email !== '' && $user->getEmail() !== $email) {
                $existing = $this->userRepository->findOneBy(['email' => $email]);
                if ($existing && $existing->getId() !== $user->getId()) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Cette adresse email est déjà utilisée.',
                    ], Response::HTTP_CONFLICT);
                }
                $user->setEmail($email);
            }
        }

        if (isset($payload['phoneNumber'])) {
            $phoneNumber = trim((string) $payload['phoneNumber']);
            if ($phoneNumber !== '' && $user->getPhoneNumber() !== $phoneNumber) {
                $existing = $this->userRepository->findOneBy(['phoneNumber' => $phoneNumber]);
                if ($existing && $existing->getId() !== $user->getId()) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Ce numéro de téléphone est déjà utilisé.',
                    ], Response::HTTP_CONFLICT);
                }
                $user->setPhoneNumber($phoneNumber);
            }
        }

        if (isset($payload['fullName'])) {
            $fullName = trim((string) $payload['fullName']);
            if ($fullName !== '') {
                $user->setFullName($fullName);
            }
        }

        if (isset($payload['password']) && $payload['password'] !== '') {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $payload['password']);
            $user->setPassword($hashedPassword);
        }

        if (isset($payload['status']) && in_array(strtolower((string) $payload['status']), ['active', 'inactive', 'suspended'], true)) {
            $status = strtolower((string) $payload['status']);
            $user->setStatus($status);
            $admin->setStatus($status);
        }

        if (isset($payload['adminRole'])) {
            $adminRole = $payload['adminRole'];
            if (in_array($adminRole, ['SUPER_ADMIN', 'FINANCE_ADMIN', 'MODERATION_ADMIN', 'SUPPORT_ADMIN'], true)) {
                $admin->setAdminRole($adminRole);
            }
        }

        if (isset($payload['permissions']) && is_array($payload['permissions'])) {
            $admin->setPermissions($payload['permissions']);
        }

        if (isset($payload['department'])) {
            $admin->setDepartment(trim((string) $payload['department']) ?: null);
        }

        if (isset($payload['notes'])) {
            $admin->setNotes(trim((string) $payload['notes']) ?: null);
        }

        $this->em->persist($user);
        $this->em->persist($admin);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'data' => $this->normalizeAdmin($admin),
        ]);
    }

    #[Route('/{id}', name: 'api_admin_admins_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(int $id): JsonResponse
    {
        $admin = $this->adminRepository->find($id);
        if (!$admin) {
            return $this->json([
                'success' => false,
                'message' => 'Administrateur introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        $user = $admin->getUser();
        if ($user) {
            $this->em->remove($admin);
            $this->em->remove($user);
        } else {
            $this->em->remove($admin);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Administrateur supprimé avec succès.',
        ]);
    }

    private function normalizeAdmin(Admin $admin): array
    {
        $user = $admin->getUser();

        return [
            'id' => $admin->getId(),
            'userId' => $user?->getId(),
            'fullName' => $user?->getFullName() ?? '',
            'email' => $user?->getEmail(),
            'phoneNumber' => $user?->getPhoneNumber() ?? '',
            'status' => $admin->getStatus(),
            'adminRole' => $admin->getAdminRole(),
            'permissions' => $admin->getPermissions() ?? [],
            'department' => $admin->getDepartment(),
            'notes' => $admin->getNotes(),
            'lastLoginAt' => $admin->getLastLoginAt()?->format(
                \DateTimeInterface::ATOM,
            ),
            'createdAt' => $admin->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $admin->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'avatarColor' => $this->getAvatarColor($user?->getId()),
        ];
    }

    private function getAvatarColor(?int $userId): string
    {
        if (!$userId) {
            return 'bg-gray-500';
        }

        $colors = [
            'bg-rose-500',
            'bg-green-500',
            'bg-amber-500',
            'bg-cyan-500',
            'bg-violet-500',
            'bg-pink-500',
            'bg-indigo-500',
            'bg-emerald-500',
            'bg-teal-500',
            'bg-orange-500',
            'bg-sky-500',
            'bg-lime-500',
        ];

        return $colors[$userId % count($colors)];
    }
}
