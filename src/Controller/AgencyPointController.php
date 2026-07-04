<?php

namespace App\Controller;

use App\Entity\AgencyPoint;
use App\Entity\Agency;
use App\Entity\User;
use App\Repository\AgencyPointRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agency-points')]
class AgencyPointController extends AbstractController
{
    public function __construct(
        private AgencyPointRepository $repo,
        private AgentRepository $agentRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_agency_points_list', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $agencyId = $request->query->get('agency_id');
        $city = $request->query->get('city');
        $qb = $this->repo->createQueryBuilder('p')->orderBy('p.city', 'ASC');

        $agency = $this->getAuthenticatedAgency();
        if ($agencyId) {
            if (!$agency || $agency->getId() !== (int)$agencyId) {
                return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
            }
            $qb->andWhere('p.agency = :aid')->setParameter('aid', (int)$agencyId);
        } elseif ($agency) {
            $qb->andWhere('p.agency = :aid')->setParameter('aid', $agency->getId());
        }

        if ($city) {
            $qb->andWhere('LOWER(p.city) LIKE :city')->setParameter('city', '%' . mb_strtolower($city) . '%');
        }

        $points = $qb->getQuery()->getResult();
        return $this->json(array_map([$this, 'normalizePoint'], $points));
    }

    #[Route('/by-agency/{agencyId}', name: 'api_agency_points_by_agency', methods: ['GET'])]
    public function getByAgency(int $agencyId, Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency || $agency->getId() !== $agencyId) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $city = $request->query->get('city');
        $points = $this->repo->createQueryBuilder('p')
            ->andWhere('p.agency = :aid')
            ->setParameter('aid', $agencyId)
            ->orderBy('p.city', 'ASC')
            ->getQuery()
            ->getResult();

        if ($city) {
            $points = array_filter($points, fn(AgencyPoint $point) => mb_stripos($point->getCity(), $city) !== false);
        }

        return $this->json(array_map([$this, 'normalizePoint'], $points));
    }

    #[Route('/{id}', name: 'api_agency_point_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $point = $this->repo->find($id);
        if (!$point) {
            return $this->json(['message' => 'Point introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isAllowedAgency($point->getAgency())) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->normalizePoint($point));
    }

    #[Route('', name: 'api_agency_point_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable pour l\'utilisateur connecté.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $point = new AgencyPoint();
        $point->setAgency($agency);
        $point->setCity($data['city'] ?? '');
        $point->setName($data['name'] ?? '');
        $point->setAddress($data['address'] ?? null);
        $point->setQuartier($data['quartier'] ?? null);
        $point->setPhone($data['phoneNumber'] ?? $data['phone'] ?? null);
        $point->setLatitude(isset($data['latitude']) ? (float)$data['latitude'] : null);
        $point->setLongitude(isset($data['longitude']) ? (float)$data['longitude'] : null);
        $point->setPointType($data['pointType'] ?? 'principal');
        $point->setStatus($data['status'] ?? 'active');
        $point->setIsActive(isset($data['isActive']) ? (int)$data['isActive'] : ($data['status'] === 'active' ? 1 : 0));
        $point->setHasVipLounge(isset($data['hasVipLounge']) ? (int)$data['hasVipLounge'] : 0);
        $point->setHasWifi(isset($data['hasWifi']) ? (int)$data['hasWifi'] : 0);
        $point->setHasAc(isset($data['hasAc']) ? (int)$data['hasAc'] : 0);
        $point->setHasParking(isset($data['hasParking']) ? (int)$data['hasParking'] : 0);

        $this->em->persist($point);
        $this->em->flush();

        return $this->json($this->normalizePoint($point), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_agency_point_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $point = $this->repo->find($id);
        if (!$point) {
            return $this->json(['message' => 'Point introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isAllowedAgency($point->getAgency())) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (array_key_exists('city', $data)) {
            $point->setCity($data['city']);
        }
        if (array_key_exists('name', $data)) {
            $point->setName($data['name']);
        }
        if (array_key_exists('address', $data)) {
            $point->setAddress($data['address']);
        }
        if (array_key_exists('quartier', $data)) {
            $point->setQuartier($data['quartier']);
        }
        if (array_key_exists('phoneNumber', $data) || array_key_exists('phone', $data)) {
            $point->setPhone($data['phoneNumber'] ?? $data['phone'] ?? null);
        }
        if (array_key_exists('latitude', $data)) {
            $point->setLatitude($data['latitude'] !== null ? (float)$data['latitude'] : null);
        }
        if (array_key_exists('longitude', $data)) {
            $point->setLongitude($data['longitude'] !== null ? (float)$data['longitude'] : null);
        }
        if (array_key_exists('pointType', $data)) {
            $point->setPointType($data['pointType']);
        }
        if (array_key_exists('status', $data)) {
            $point->setStatus($data['status']);
            $point->setIsActive($data['status'] === 'active' ? 1 : 0);
        }
        if (array_key_exists('isActive', $data)) {
            $point->setIsActive((int)$data['isActive']);
        }
        if (array_key_exists('hasVipLounge', $data)) {
            $point->setHasVipLounge((int)$data['hasVipLounge']);
        }
        if (array_key_exists('hasWifi', $data)) {
            $point->setHasWifi((int)$data['hasWifi']);
        }
        if (array_key_exists('hasAc', $data)) {
            $point->setHasAc((int)$data['hasAc']);
        }
        if (array_key_exists('hasParking', $data)) {
            $point->setHasParking((int)$data['hasParking']);
        }

        $this->em->persist($point);
        $this->em->flush();

        return $this->json($this->normalizePoint($point));
    }

    #[Route('/{id}', name: 'api_agency_point_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $point = $this->repo->find($id);
        if (!$point) {
            return $this->json(['message' => 'Point introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isAllowedAgency($point->getAgency())) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($point);
        $this->em->flush();

        return $this->json(['success' => true, 'message' => 'Point supprimé.']);
    }

    private function getAuthenticatedAgency(): ?Agency
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        return $agent?->getAgency();
    }

    private function isAllowedAgency(?Agency $agency): bool
    {
        if (!$agency) {
            return false;
        }

        $authenticatedAgency = $this->getAuthenticatedAgency();
        return $authenticatedAgency && $authenticatedAgency->getId() === $agency->getId();
    }

    private function normalizePoint(AgencyPoint $p): array
    {
        return [
            'id' => $p->getId(),
            'city' => $p->getCity(),
            'name' => $p->getName(),
            'address' => $p->getAddress(),
            'quartier' => $p->getQuartier(),
            'phoneNumber' => $p->getPhone(),
            'latitude' => $p->getLatitude(),
            'longitude' => $p->getLongitude(),
            'pointType' => $p->getPointType(),
            'status' => $p->getStatus(),
            'isActive' => $p->isActive(),
            'hasVipLounge' => $p->getHasVipLounge(),
            'hasWifi' => $p->getHasWifi(),
            'hasAc' => $p->getHasAc(),
            'hasParking' => $p->getHasParking(),
            'createdAt' => $p->getCreatedAt()?->format('c'),
        ];
    }
}
