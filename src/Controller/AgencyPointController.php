<?php

namespace App\Controller;

use App\Entity\AgencyPoint;
use App\Repository\AgencyPointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agency-points')]
class AgencyPointController extends AbstractController
{
    public function __construct(private AgencyPointRepository $repo, private EntityManagerInterface $em) {}

    #[Route('', name: 'api_agency_points_list', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $agencyId = $request->query->get('agency_id');
        $city = $request->query->get('city');

        $qb = $this->repo->createQueryBuilder('p')
            ->orderBy('p.city', 'ASC');

        if ($agencyId) {
            $qb->andWhere('p.agency = :aid')->setParameter('aid', (int)$agencyId);
        }
        if ($city) {
            $qb->andWhere('LOWER(p.city) LIKE :city')->setParameter('city', '%' . mb_strtolower($city) . '%');
        }

        $points = $qb->getQuery()->getResult();
        return $this->json(array_map([$this, 'normalizePoint'], $points));
    }

    #[Route('/{id}', name: 'api_agency_point_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $point = $this->repo->find($id);
        if (!$point) return $this->json(['message' => 'Point introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        return $this->json($this->normalizePoint($point));
    }

    #[Route('', name: 'api_agency_point_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $point = new AgencyPoint();
        // minimal creation logic; expect admin to set agency via backoffice
        $point->setCity($data['city'] ?? '');
        $point->setName($data['name'] ?? '');
        $point->setPhone($data['phone'] ?? null);
        $point->setHasVipLounge(isset($data['hasVipLounge']) ? (int)$data['hasVipLounge'] : 0);
        $point->setHasWifi(isset($data['hasWifi']) ? (int)$data['hasWifi'] : 0);
        $point->setHasAc(isset($data['hasAc']) ? (int)$data['hasAc'] : 0);
        $point->setHasParking(isset($data['hasParking']) ? (int)$data['hasParking'] : 0);

        // agency assignment skipped for simplicity
        $this->em->persist($point);
        $this->em->flush();

        return $this->json(['id' => $point->getId()], JsonResponse::HTTP_CREATED);
    }

    private function normalizePoint(AgencyPoint $p): array
    {
        return [
            'id' => $p->getId(),
            'city' => $p->getCity(),
            'name' => $p->getName(),
            'address' => $p->getName(),
            'phoneNumber' => $p->getPhone(),
            'isActive' => true
        ];
    }
}
