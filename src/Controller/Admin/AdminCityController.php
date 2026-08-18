<?php

namespace App\Controller\Admin;

use App\Security\AdminRoleVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\AgencyPoint;
use App\Entity\City;
use App\Repository\CityRepository;
use App\Repository\TripRepository;
use App\Service\AdminNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin City Controller for Super Admin Dashboard.
 * Provides full CRUD operations for the reference list of cities
 * (villes desservies), utilisée pour les trajets et les points d'embarquement.
 */
#[Route('/api/admin/cities')]
#[IsGranted(AdminRoleVoter::MODERATION)]
class AdminCityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CityRepository $cityRepository,
        private TripRepository $tripRepository,
        private AdminNotificationService $adminNotificationService,
    ) {}

    /**
     * Get all cities with optional search and status filter.
     */
    #[Route('', name: 'api_admin_cities_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $search = trim((string) $request->query->get('search', ''));
        $status = $request->query->get('status'); // 'active' | 'inactive' | null (=> toutes)

        $qb = $this->cityRepository->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC');

        if ($search !== '') {
            $qb->andWhere('(c.name LIKE :search OR c.code LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($status === 'active') {
            $qb->andWhere('c.isActive = 1');
        } elseif ($status === 'inactive') {
            $qb->andWhere('c.isActive = 0');
        }

        $cities = $qb->getQuery()->getResult();

        return $this->json([
            'success' => true,
            'data' => array_map([$this, 'normalizeCityWithStats'], $cities),
        ]);
    }

    /**
     * Get a single city.
     */
    #[Route('/{id}', name: 'api_admin_city_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $city = $this->cityRepository->find($id);

        if (!$city) {
            return $this->json([
                'success' => false,
                'message' => 'Ville introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $this->normalizeCityWithStats($city),
        ]);
    }

    /**
     * Create a new city.
     */
    #[Route('', name: 'api_admin_city_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json([
                'success' => false,
                'message' => "Le champ 'name' est obligatoire",
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($this->cityRepository->findOneBy(['name' => $name])) {
            return $this->json([
                'success' => false,
                'message' => 'Une ville avec ce nom existe déjà',
            ], Response::HTTP_CONFLICT);
        }

        $city = new City();
        $city->setName($name);
        $city->setCode(!empty($data['code']) ? strtoupper(trim((string) $data['code'])) : null);
        $city->setCountry(!empty($data['country']) ? trim((string) $data['country']) : 'Congo');
        $city->setIsActive(array_key_exists('isActive', $data) ? (int) (bool) $data['isActive'] : 1);

        $this->em->persist($city);
        $this->em->flush();

        // 👈 Notifier les admins de la création d'une nouvelle ville
        $this->adminNotificationService->notifyEvent(
            'Nouvelle ville créée',
            sprintf(
                'Une nouvelle ville "%s" (code: %s) a été ajoutée à la plateforme.',
                $city->getName(),
                $city->getCode() ?? 'N/A'
            ),
            'CITY_CREATED',
            ['cityId' => $city->getId(), 'cityName' => $city->getName()]
        );

        return $this->json([
            'success' => true,
            'message' => 'Ville créée avec succès',
            'data' => $this->normalizeCityWithStats($city),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update a city.
     */
    #[Route('/{id}', name: 'api_admin_city_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $city = $this->cityRepository->find($id);

        if (!$city) {
            return $this->json([
                'success' => false,
                'message' => 'Ville introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload JSON invalide',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json([
                    'success' => false,
                    'message' => "Le champ 'name' ne peut pas être vide",
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($name !== $city->getName()) {
                $existing = $this->cityRepository->findOneBy(['name' => $name]);
                if ($existing && $existing->getId() !== $city->getId()) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Une ville avec ce nom existe déjà',
                    ], Response::HTTP_CONFLICT);
                }
            }

            $city->setName($name);
        }

        if (array_key_exists('code', $data)) {
            $city->setCode(!empty($data['code']) ? strtoupper(trim((string) $data['code'])) : null);
        }

        if (array_key_exists('country', $data)) {
            $city->setCountry(!empty($data['country']) ? trim((string) $data['country']) : null);
        }

        if (array_key_exists('isActive', $data)) {
            $city->setIsActive((int) (bool) $data['isActive']);
        }

        $this->em->persist($city);
        $this->em->flush();

        // 👈 Notifier les admins de la modification de la ville
        $this->adminNotificationService->notifyEvent(
            'Ville mise à jour',
            sprintf(
                'La ville "%s" a été modifiée.',
                $city->getName()
            ),
            'CITY_UPDATED',
            ['cityId' => $city->getId(), 'cityName' => $city->getName()]
        );

        return $this->json([
            'success' => true,
            'message' => 'Ville mise à jour avec succès',
            'data' => $this->normalizeCityWithStats($city),
        ]);
    }

    /**
     * Activate or deactivate a city. Keeps history of trips/points intact,
     * simply removes the city from selection lists used by clients/agences.
     */
    #[Route('/{id}/toggle-status', name: 'api_admin_city_toggle_status', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function toggleStatus(int $id): JsonResponse
    {
        $city = $this->cityRepository->find($id);

        if (!$city) {
            return $this->json([
                'success' => false,
                'message' => 'Ville introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $city->setIsActive($city->getIsActive() ? 0 : 1);
        $this->em->persist($city);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => $city->getIsActive() ? 'Ville activée' : 'Ville désactivée',
            'data' => [
                'id' => $city->getId(),
                'isActive' => (bool) $city->getIsActive(),
            ],
        ]);
    }

    /**
     * Delete a city. Refused when the city is still referenced by trips or
     * agency boarding points ; on désactive plutôt dans ce cas.
     */
    #[Route('/{id}', name: 'api_admin_city_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $city = $this->cityRepository->find($id);

        if (!$city) {
            return $this->json([
                'success' => false,
                'message' => 'Ville introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $tripsUsingCity = (int) $this->tripRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.departureCity = :name OR t.arrivalCity = :name')
            ->setParameter('name', $city->getName())
            ->getQuery()
            ->getSingleScalarResult();

        $pointsUsingCity = $this->em->getRepository(AgencyPoint::class)->count(['city' => $city->getName()]);

        if ($tripsUsingCity > 0 || $pointsUsingCity > 0) {
            return $this->json([
                'success' => false,
                'message' => "Impossible de supprimer une ville utilisée par des trajets ou des points d'embarquement. Désactivez-la plutôt.",
                'data' => [
                    'tripsCount' => $tripsUsingCity,
                    'boardingPointsCount' => $pointsUsingCity,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->em->remove($city);
        $this->em->flush();

        // 👈 Notifier les admins de la suppression d'une ville
        $this->adminNotificationService->notifyEvent(
            'Ville supprimée',
            sprintf(
                'La ville "%s" a été supprimée.',
                $city->getName()
            ),
            'CITY_DELETED',
            ['cityId' => $city->getId(), 'cityName' => $city->getName()]
        );

        return $this->json([
            'success' => true,
            'message' => 'Ville supprimée avec succès',
        ]);
    }

    /**
     * Normalize city with usage stats for the admin dashboard.
     */
    private function normalizeCityWithStats(City $city): array
    {
        $tripsCount = (int) $this->tripRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.departureCity = :name OR t.arrivalCity = :name')
            ->setParameter('name', $city->getName())
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'id' => $city->getId(),
            'name' => $city->getName(),
            'code' => $city->getCode(),
            'country' => $city->getCountry(),
            'isActive' => (bool) $city->getIsActive(),
            'tripsCount' => $tripsCount,
            'boardingPointsCount' => $this->em->getRepository(AgencyPoint::class)->count(['city' => $city->getName()]),
            'createdAt' => $city->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
