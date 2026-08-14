<?php

namespace App\Controller\Partner;

use App\Entity\Agent;
use App\Entity\Agency;
use App\Entity\Bus;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Wallet;
use App\Repository\AgentRepository;
use App\Repository\AgencyPointRepository;
use App\Repository\BusRepository;
use App\Repository\TripRepository;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints métier manquants du Partner Back Office.
 * Toute opération est strictement limitée à l'agence de l'utilisateur connecté.
 */
#[Route('/api/partner')]
class PartnerOperationsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agentRepository,
        private BusRepository $busRepository,
        private TripRepository $tripRepository,
        private AgencyPointRepository $pointRepository,
        private WalletService $walletService,
        private UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('/context', name: 'api_partner_context', methods: ['GET'])]
    public function context(): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Aucune agence associée au compte.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->agencyPayload($agency));
    }

    #[Route('/dashboard', name: 'api_partner_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Aucune agence associée au compte.'], Response::HTTP_FORBIDDEN);
        }

        $wallet = $this->walletService->getOrCreateWallet($agency);
        $now = new \DateTimeImmutable();
        $start = new \DateTime($now->format('Y-m-d 00:00:00'));
        $end = new \DateTime($now->format('Y-m-d 23:59:59'));

        $tripsToday = $this->tripRepository->countTripsByAgency($agency, $start, $end);
        $activeTrips = $this->tripRepository->countActiveTrips($agency, $start, $end);
        $completedTrips = $this->tripRepository->countCompletedTrips($agency, $start, $end);
        $cancelledTrips = $this->tripRepository->countCancelledTrips($agency, $start, $end);

        $agentCount = $this->agentRepository->count(['agency' => $agency]);
        $activeAgentCount = $this->agentRepository->count(['agency' => $agency, 'status' => 'active']);
        $busCount = $this->busRepository->count(['agency' => $agency]);
        $availableBusCount = $this->busRepository->count(['agency' => $agency, 'status' => 'disponible']);
        $pointCount = $this->pointRepository->count(['agency' => $agency]);
        $activePointCount = $this->pointRepository->count(['agency' => $agency, 'isActive' => 1]);

        return $this->json([
            'agency' => $this->agencyPayload($agency),
            'wallet' => [
                'available' => $wallet->getAvailableBalance(),
                'blocked' => $wallet->getBlockedBalance(),
                'reserved' => $wallet->getReservedBalance(),
                'currency' => 'FCFA',
            ],
            'today' => [
                'trips' => $tripsToday,
                'activeTrips' => $activeTrips,
                'completedTrips' => $completedTrips,
                'cancelledTrips' => $cancelledTrips,
            ],
            'resources' => [
                'agents' => $agentCount,
                'activeAgents' => $activeAgentCount,
                'buses' => $busCount,
                'availableBuses' => $availableBusCount,
                'points' => $pointCount,
                'activePoints' => $activePointCount,
            ],
        ]);
    }

    #[Route('/agents', name: 'api_partner_agents', methods: ['GET'])]
    public function agents(): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Aucune agence associée au compte.'], Response::HTTP_FORBIDDEN);
        }

        $agents = $this->agentRepository->findBy(['agency' => $agency], ['createdAt' => 'DESC']);
        return $this->json(array_map([$this, 'agentPayload'], $agents));
    }

    #[Route('/agents', name: 'api_partner_agent_create', methods: ['POST'])]
    public function createAgent(Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency || !$this->isAgencyAdmin()) {
            return $this->json(['message' => 'Seul l’administrateur de l’agence peut gérer les agents.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $fullName = trim((string)($data['fullName'] ?? ''));
        $phone = trim((string)($data['phoneNumber'] ?? $data['phone'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $role = (string)($data['agentRole'] ?? 'agent_quai');

        if ($fullName === '' || $phone === '' || $password === '') {
            return $this->json(['message' => 'fullName, phoneNumber et password sont obligatoires.'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($role, ['agent_quai', 'admin_agence'], true)) {
            return $this->json(['message' => 'Rôle agent invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if ($role === 'admin_agence' && !$this->isAgencyOwnerAdmin()) {
            return $this->json(['message' => 'Seul l’administrateur principal peut créer un autre administrateur d’agence.'], Response::HTTP_FORBIDDEN);
        }

        $existing = $this->em->getRepository(User::class)->findOneBy(['phoneNumber' => $phone]);
        if ($existing) {
            return $this->json(['message' => 'Ce numéro de téléphone est déjà utilisé.'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setPhoneNumber($phone);
        $user->setEmail(isset($data['email']) && $data['email'] !== '' ? (string)$data['email'] : null);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setPhoneVerified(true);
        $user->setStatus('active');

        $agent = new Agent();
        $agent->setUser($user)
            ->setAgency($agency)
            ->setAgentRole($role)
            ->setStatus('active')
            ->setCommissionRate((string)($data['commissionRate'] ?? '0.00'));

        $this->em->persist($user);
        $this->em->persist($agent);
        $this->em->flush();

        return $this->json($this->agentPayload($agent), Response::HTTP_CREATED);
    }

    #[Route('/agents/{id}', name: 'api_partner_agent_update', methods: ['PATCH'])]
    public function updateAgent(int $id, Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency || !$this->isAgencyAdmin()) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $agent = $this->agentRepository->find($id);
        if (!$agent || $agent->getAgency()?->getId() !== $agency->getId()) {
            return $this->json(['message' => 'Agent introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $role = $agent->getAgentRole();
        if (array_key_exists('agentRole', $data)) {
            $role = (string)$data['agentRole'];
            if (!in_array($role, ['agent_quai', 'admin_agence'], true)) {
                return $this->json(['message' => 'Rôle agent invalide.'], Response::HTTP_BAD_REQUEST);
            }
            if ($role === 'admin_agence' && !$this->isAgencyOwnerAdmin()) {
                return $this->json(['message' => 'Seul l’administrateur principal peut promouvoir un agent en administrateur d’agence.'], Response::HTTP_FORBIDDEN);
            }
        }

        $user = $agent->getUser();
        if (array_key_exists('fullName', $data)) {
            $user->setFullName(trim((string)$data['fullName']));
        }
        if (array_key_exists('email', $data)) {
            $user->setEmail($data['email'] !== null && $data['email'] !== '' ? (string)$data['email'] : null);
        }
        if (array_key_exists('password', $data) && (string)$data['password'] !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, (string)$data['password']));
        }
        if (array_key_exists('status', $data)) {
            $status = (string)$data['status'];
            if (!in_array($status, ['active', 'inactive'], true)) {
                return $this->json(['message' => 'Statut agent invalide.'], Response::HTTP_BAD_REQUEST);
            }
            $agent->setStatus($status);
            $user->setStatus($status);
        }
        $agent->setAgentRole($role);

        if (array_key_exists('commissionRate', $data)) {
            $rate = (string)$data['commissionRate'];
            if (!is_numeric($rate) || bccomp($rate, '0', 2) < 0 || bccomp($rate, '100', 2) > 0) {
                return $this->json(['message' => 'Le taux de commission doit être compris entre 0 et 100.'], Response::HTTP_BAD_REQUEST);
            }
            $agent->setCommissionRate(number_format((float)$rate, 2, '.', ''));
        }

        $this->em->flush();
        return $this->json($this->agentPayload($agent));
    }

    #[Route('/agents/{id}/status', name: 'api_partner_agent_status', methods: ['PATCH'])]
    public function setAgentStatus(int $id, Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency || !$this->isAgencyAdmin()) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $agent = $this->agentRepository->find($id);
        if (!$agent || $agent->getAgency()?->getId() !== $agency->getId()) {
            return $this->json(['message' => 'Agent introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($agent->getUser()?->getId() === $this->getUser()?->getId()) {
            return $this->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte depuis cet écran.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) {
            return $this->json(['message' => 'status doit être active ou inactive.'], Response::HTTP_BAD_REQUEST);
        }

        $agent->setStatus($status);
        $agent->getUser()?->setStatus($status);
        $this->em->flush();

        return $this->json($this->agentPayload($agent));
    }

    private function getAuthenticatedAgency(): ?Agency
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }
        return $this->agentRepository->findOneBy(['user' => $user])?->getAgency();
    }

    private function getCurrentAgent(): ?Agent
    {
        $user = $this->getUser();
        return $user instanceof User ? $this->agentRepository->findOneBy(['user' => $user]) : null;
    }

    private function isAgencyAdmin(): bool
    {
        return $this->getCurrentAgent()?->getAgentRole() === 'admin_agence';
    }

    private function isAgencyOwnerAdmin(): bool
    {
        $agent = $this->getCurrentAgent();
        return $agent?->getAgentRole() === 'admin_agence';
    }

    private function agencyPayload(Agency $agency): array
    {
        return [
            'id' => $agency->getId(),
            'name' => $agency->getName(),
            'logoUrl' => $agency->getLogoUrl(),
            'bannerUrl' => $agency->getBannerUrl(),
            'phone' => $agency->getPhone(),
            'email' => $agency->getEmail(),
            'address' => $agency->getAddress(),
            'status' => $agency->getStatus(),
        ];
    }

    private function agentPayload(Agent $agent): array
    {
        $user = $agent->getUser();
        return [
            'id' => $agent->getId(),
            'fullName' => $user?->getFullName(),
            'phoneNumber' => $user?->getPhoneNumber(),
            'email' => $user?->getEmail(),
            'profilePhotoUrl' => $user?->getProfilePhotoUrl(),
            'agentRole' => $agent->getAgentRole(),
            'status' => $agent->getStatus(),
            'commissionRate' => $agent->getCommissionRate(),
            'createdAt' => $agent->getCreatedAt()?->format('c'),
        ];
    }
}
