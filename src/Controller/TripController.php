<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\Agency;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AgencyPointRepository;
use App\Repository\BusRepository;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TripController extends AbstractController
{
    public function __construct(
        private TripRepository $tripRepository,
        private AgentRepository $agentRepository,
        private BusRepository $busRepository,
        private AgencyPointRepository $agencyPointRepository,
        private EntityManagerInterface $em,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $departureCity = $request->query->get('departure_city');
        $arrivalCity = $request->query->get('arrival_city');
        $departureDate = $request->query->get('departure_date');
        $category = $request->query->get('category');
        $maxPrice = $request->query->get('max_price');
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = max(1, (int)$request->query->get('limit', 10));

        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['data' => [], 'total' => 0, 'page' => $page, 'pageSize' => $limit]);
        }

        $qb = $this->tripRepository->createQueryBuilder('t')
            ->leftJoin('t.departurePoint', 'dp')
            ->leftJoin('t.arrivalPoint', 'ap')
            ->join('t.agency', 'a')
            ->join('t.bus', 'b')
            ->andWhere('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('t.departureTime', 'ASC');

        if ($departureCity) {
            $qb->andWhere(
                'LOWER(t.departureCity) LIKE :departureCity OR LOWER(dp.city) LIKE :departureCity'
            )
                ->setParameter('departureCity', '%' . mb_strtolower($departureCity) . '%');
        }

        if ($arrivalCity) {
            $qb->andWhere(
                'LOWER(t.arrivalCity) LIKE :arrivalCity OR LOWER(ap.city) LIKE :arrivalCity'
            )
                ->setParameter('arrivalCity', '%' . mb_strtolower($arrivalCity) . '%');
        }

        if ($departureDate) {
            try {
                $dateFrom = new \DateTime($departureDate);
                $dateTo = (clone $dateFrom)->modify('+1 day');
                $qb->andWhere('t.departureTime >= :dateFrom')
                    ->andWhere('t.departureTime < :dateTo')
                    ->setParameter('dateFrom', $dateFrom)
                    ->setParameter('dateTo', $dateTo);
            } catch (\Exception $exception) {
            }
        }

        if ($category) {
            $qb->andWhere('LOWER(b.name) LIKE :category')
                ->setParameter('category', '%' . mb_strtolower($category) . '%');
        }

        if ($maxPrice !== null && is_numeric($maxPrice)) {
            $qb->andWhere('t.price <= :maxPrice')
                ->setParameter('maxPrice', $maxPrice);
        }

        $countQb = clone $qb;
        $count = (int)$countQb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        $trips = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $data = array_map([$this, 'normalizeTrip'], $trips);

        return $this->json([
            'data' => $data,
            'total' => $count,
            'page' => $page,
            'pageSize' => $limit,
        ]);
    }

    public function detail(int $id): JsonResponse
    {
        $trip = $this->tripRepository->find($id);
        if (!$trip) {
            return $this->json(['message' => 'Trajet introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isAllowedAgency($trip->getAgency())) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->normalizeTrip($trip));
    }

    public function create(Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable pour l\'utilisateur connecté.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $bus = $this->busRepository->find($data['busId'] ?? null);
        $departureCity = trim($data['departureCity'] ?? '');
        $arrivalCity = trim($data['arrivalCity'] ?? '');
        $boardingPointIds = array_filter(array_map('intval', (array)($data['boardingPointIds'] ?? [])));
        $deboardingPointIds = array_filter(array_map('intval', (array)($data['deboardingPointIds'] ?? [])));

        if (!$bus || $bus->getAgency()?->getId() !== $agency->getId()) {
            return $this->json(['message' => 'Bus invalide ou non autorisé.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$departureCity) {
            return $this->json(['message' => 'Ville de départ invalide ou manquante.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$arrivalCity) {
            return $this->json(['message' => 'Ville d\'arrivée invalide ou manquante.'], Response::HTTP_BAD_REQUEST);
        }
        if (empty($boardingPointIds)) {
            return $this->json(['message' => 'Au moins un point d\'embarquement est requis.'], Response::HTTP_BAD_REQUEST);
        }
        if (empty($deboardingPointIds)) {
            return $this->json(['message' => 'Au moins un point de débarquement est requis.'], Response::HTTP_BAD_REQUEST);
        }

        $boardingPoints = $this->resolveAgencyPoints($boardingPointIds, $agency);
        $deboardingPoints = $this->resolveAgencyPoints($deboardingPointIds, $agency);

        if (count($boardingPoints) !== count(array_unique($boardingPointIds))) {
            return $this->json(['message' => 'Certains points d\'embarquement sont invalides ou non autorisés.'], Response::HTTP_BAD_REQUEST);
        }
        if (count($deboardingPoints) !== count(array_unique($deboardingPointIds))) {
            return $this->json(['message' => 'Certains points de débarquement sont invalides ou non autorisés.'], Response::HTTP_BAD_REQUEST);
        }

        // Support new separated fields: tripDate (Y-m-d), departureTimeOfDay (H:i), arrivalTimeOfDay (H:i)
        $tripDate = null;
        $departureTime = null;
        $estimatedArrivalTime = null;
        $departureTimeOfDay = null;
        $arrivalTimeOfDay = null;

        if (!empty($data['tripDate'])) {
            try {
                $tripDate = new \DateTime($data['tripDate']);
            } catch (\Exception $e) {
                $tripDate = null;
            }
        }

        $depTimeStr = $data['departureTimeOfDay'] ?? $data['departureTime'] ?? null;
        $arrTimeStr = $data['arrivalTimeOfDay'] ?? $data['estimatedArrivalTime'] ?? $data['arrivalTime'] ?? null;

        if ($tripDate && $depTimeStr) {
            try {
                $departureTime = new \DateTime($tripDate->format('Y-m-d') . ' ' . $depTimeStr);
            } catch (\Exception $e) {
                $departureTime = null;
            }
            try {
                $departureTimeOfDay = new \DateTime($depTimeStr);
            } catch (\Exception $e) {
                $departureTimeOfDay = null;
            }
        } else {
            $departureTime = $this->parseDateTime($data['departureTime'] ?? null);
            if ($departureTime) {
                $tripDate = new \DateTime($departureTime->format('Y-m-d'));
                try {
                    $departureTimeOfDay = new \DateTime($departureTime->format('H:i'));
                } catch (\Exception $e) {
                    $departureTimeOfDay = null;
                }
            }
        }

        if ($tripDate && $arrTimeStr) {
            try {
                $estimatedArrivalTime = new \DateTime($tripDate->format('Y-m-d') . ' ' . $arrTimeStr);
            } catch (\Exception $e) {
                $estimatedArrivalTime = null;
            }
            try {
                $arrivalTimeOfDay = new \DateTime($arrTimeStr);
            } catch (\Exception $e) {
                $arrivalTimeOfDay = null;
            }
        } else {
            $estimatedArrivalTime = $this->parseDateTime($data['estimatedArrivalTime'] ?? null);
            if ($estimatedArrivalTime && !$tripDate) {
                $tripDate = new \DateTime($estimatedArrivalTime->format('Y-m-d'));
            }
            if ($estimatedArrivalTime) {
                try {
                    $arrivalTimeOfDay = new \DateTime($estimatedArrivalTime->format('H:i'));
                } catch (\Exception $e) {
                    $arrivalTimeOfDay = null;
                }
            }
        }

        if (!$departureTime) {
            return $this->json(['message' => 'Date/heure de départ invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $trip = new Trip();
        $trip->setAgency($agency)
            ->setBus($bus)
            ->setDepartureCity($departureCity)
            ->setArrivalCity($arrivalCity)
            ->setBoardingPoints(array_map(fn($point) => [
                'id' => $point->getId(),
                'name' => $point->getName(),
                'address' => $point->getAddress(),
                'city' => $point->getCity(),
            ], $boardingPoints))
            ->setDeboardingPoints(array_map(fn($point) => [
                'id' => $point->getId(),
                'name' => $point->getName(),
                'address' => $point->getAddress(),
                'city' => $point->getCity(),
            ], $deboardingPoints))
            ->setDeparturePoint($boardingPoints[0])
            ->setArrivalPoint($deboardingPoints[0])
            ->setDepartureTime($departureTime)
            ->setEstimatedArrivalTime($estimatedArrivalTime)
            ->setTripDate($tripDate)
            ->setDepartureTimeOfDay($departureTimeOfDay)
            ->setArrivalTimeOfDay($arrivalTimeOfDay)
            ->setPrice((string)($data['price'] ?? '0'))
            ->setDriverName($data['driverName'] ?? null)
            ->setStatus($data['status'] ?? 'planifie')
            ->setSeatsReserved((int)($data['seatsReserved'] ?? 0));

        $this->em->persist($trip);
        $this->em->flush();

        return $this->json($this->normalizeTrip($trip), Response::HTTP_CREATED);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $trip = $this->tripRepository->find($id);
        if (!$trip) {
            return $this->json(['message' => 'Trajet introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isAllowedAgency($trip->getAgency())) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('busId', $data)) {
            $bus = $this->busRepository->find($data['busId']);
            if (!$bus || $bus->getAgency()?->getId() !== $trip->getAgency()?->getId()) {
                return $this->json(['message' => 'Bus invalide ou non autorisé.'], Response::HTTP_BAD_REQUEST);
            }
            $trip->setBus($bus);
        }
        if (array_key_exists('departureCity', $data)) {
            $departureCity = trim($data['departureCity'] ?? '');
            if (!$departureCity) {
                return $this->json(['message' => 'Ville de départ invalide ou manquante.'], Response::HTTP_BAD_REQUEST);
            }
            $trip->setDepartureCity($departureCity);
        }
        if (array_key_exists('arrivalCity', $data)) {
            $arrivalCity = trim($data['arrivalCity'] ?? '');
            if (!$arrivalCity) {
                return $this->json(['message' => 'Ville d\'arrivée invalide ou manquante.'], Response::HTTP_BAD_REQUEST);
            }
            $trip->setArrivalCity($arrivalCity);
        }
        if (array_key_exists('boardingPointIds', $data)) {
            $boardingPointIds = array_filter(array_map('intval', (array)$data['boardingPointIds']));
            if (empty($boardingPointIds)) {
                return $this->json(['message' => 'Au moins un point d\'embarquement est requis.'], Response::HTTP_BAD_REQUEST);
            }
            $boardingPoints = $this->resolveAgencyPoints($boardingPointIds, $trip->getAgency());
            if (count($boardingPoints) !== count(array_unique($boardingPointIds))) {
                return $this->json(['message' => 'Certains points d\'embarquement sont invalides ou non autorisés.'], Response::HTTP_BAD_REQUEST);
            }
            $trip->setBoardingPoints(array_map(fn($point) => [
                'id' => $point->getId(),
                'name' => $point->getName(),
                'address' => $point->getAddress(),
                'city' => $point->getCity(),
            ], $boardingPoints));
            $trip->setDeparturePoint($boardingPoints[0]);
        }
        if (array_key_exists('deboardingPointIds', $data)) {
            $deboardingPointIds = array_filter(array_map('intval', (array)$data['deboardingPointIds']));
            if (empty($deboardingPointIds)) {
                return $this->json(['message' => 'Au moins un point de débarquement est requis.'], Response::HTTP_BAD_REQUEST);
            }
            $deboardingPoints = $this->resolveAgencyPoints($deboardingPointIds, $trip->getAgency());
            if (count($deboardingPoints) !== count(array_unique($deboardingPointIds))) {
                return $this->json(['message' => 'Certains points de débarquement sont invalides ou non autorisés.'], Response::HTTP_BAD_REQUEST);
            }
            $trip->setDeboardingPoints(array_map(fn($point) => [
                'id' => $point->getId(),
                'name' => $point->getName(),
                'address' => $point->getAddress(),
                'city' => $point->getCity(),
            ], $deboardingPoints));
            $trip->setArrivalPoint($deboardingPoints[0]);
        }
        if (array_key_exists('departureTime', $data)) {
            $departureTime = $this->parseDateTime($data['departureTime']);
            if (!$departureTime) {
                return $this->json(['message' => 'Date/heure de départ invalide.'], Response::HTTP_BAD_REQUEST);
            }
            $trip->setDepartureTime($departureTime);
            // also update tripDate and time-of-day fields for compatibility
            try {
                $trip->setTripDate(new \DateTime($departureTime->format('Y-m-d')));
            } catch (\Exception $e) {
            }
            try {
                $trip->setDepartureTimeOfDay(new \DateTime($departureTime->format('H:i')));
            } catch (\Exception $e) {
            }
        }
        if (array_key_exists('estimatedArrivalTime', $data)) {
            $trip->setEstimatedArrivalTime($this->parseDateTime($data['estimatedArrivalTime']));
            try {
                $trip->setArrivalTimeOfDay(new \DateTime($trip->getEstimatedArrivalTime()?->format('H:i')));
            } catch (\Exception $e) {
            }
            try {
                $trip->setTripDate(new \DateTime($trip->getEstimatedArrivalTime()?->format('Y-m-d')));
            } catch (\Exception $e) {
            }
        }
        if (array_key_exists('tripDate', $data) || array_key_exists('departureTimeOfDay', $data) || array_key_exists('arrivalTimeOfDay', $data)) {
            // process separated fields on update
            if (array_key_exists('tripDate', $data)) {
                try {
                    $trip->setTripDate(new \DateTime($data['tripDate']));
                } catch (\Exception $e) {
                }
            }
            if (array_key_exists('departureTimeOfDay', $data) && !empty($data['departureTimeOfDay'])) {
                try {
                    $trip->setDepartureTimeOfDay(new \DateTime($data['departureTimeOfDay']));
                } catch (\Exception $e) {
                }
            }
            if (array_key_exists('arrivalTimeOfDay', $data) && !empty($data['arrivalTimeOfDay'])) {
                try {
                    $trip->setArrivalTimeOfDay(new \DateTime($data['arrivalTimeOfDay']));
                } catch (\Exception $e) {
                }
            }
            // if we have tripDate and departure time of day, update the datetime fields too
            if ($trip->getTripDate() && $trip->getDepartureTimeOfDay()) {
                try {
                    $dt = new \DateTime($trip->getTripDate()->format('Y-m-d') . ' ' . $trip->getDepartureTimeOfDay()->format('H:i'));
                    $trip->setDepartureTime($dt);
                } catch (\Exception $e) {
                }
            }
            if ($trip->getTripDate() && $trip->getArrivalTimeOfDay()) {
                try {
                    $at = new \DateTime($trip->getTripDate()->format('Y-m-d') . ' ' . $trip->getArrivalTimeOfDay()->format('H:i'));
                    $trip->setEstimatedArrivalTime($at);
                } catch (\Exception $e) {
                }
            }
        }
        if (array_key_exists('price', $data)) {
            $trip->setPrice((string)$data['price']);
        }
        if (array_key_exists('driverName', $data)) {
            $trip->setDriverName($data['driverName']);
        }
        if (array_key_exists('status', $data)) {
            $trip->setStatus($data['status']);
        }
        if (array_key_exists('seatsReserved', $data)) {
            $trip->setSeatsReserved((int)$data['seatsReserved']);
        }

        $this->em->persist($trip);
        $this->em->flush();

        return $this->json($this->normalizeTrip($trip));
    }

    public function delete(int $id): JsonResponse
    {
        $trip = $this->tripRepository->find($id);
        if (!$trip) {
            return $this->json(['message' => 'Trajet introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isAllowedAgency($trip->getAgency())) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($trip);
        $this->em->flush();

        return $this->json(['success' => true, 'message' => 'Trajet supprimé.']);
    }

    private function parseDateTime(?string $dateTime): ?\DateTimeInterface
    {
        if (!$dateTime) {
            return null;
        }

        try {
            return new \DateTime($dateTime);
        } catch (\Exception $exception) {
            return null;
        }
    }

    private function resolveAgencyPoints(array $pointIds, Agency $agency): array
    {
        if (empty($pointIds)) {
            return [];
        }

        $points = $this->agencyPointRepository->findBy(['id' => $pointIds]);
        $pointMap = [];
        foreach ($points as $point) {
            if ($point->getAgency()?->getId() === $agency->getId()) {
                $pointMap[$point->getId()] = $point;
            }
        }

        $orderedPoints = [];
        foreach ($pointIds as $pointId) {
            if (isset($pointMap[$pointId])) {
                $orderedPoints[] = $pointMap[$pointId];
            }
        }

        return $orderedPoints;
    }

    private function getAuthenticatedAgency(): ?\App\Entity\Agency
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $agent = $this->agentRepository->findOneBy(['user' => $user]);
        return $agent?->getAgency();
    }

    private function isAllowedAgency(?\App\Entity\Agency $agency): bool
    {
        if (!$agency) {
            return false;
        }

        $authenticatedAgency = $this->getAuthenticatedAgency();
        return $authenticatedAgency && $authenticatedAgency->getId() === $agency->getId();
    }

    public function departureCities(): JsonResponse
    {
        $qb = $this->tripRepository->createQueryBuilder('t')
            ->select('DISTINCT COALESCE(t.departureCity, dp.city) AS city')
            ->leftJoin('t.departurePoint', 'dp')
            ->orderBy('city', 'ASC');

        $rows = $qb->getQuery()->getArrayResult();
        $cities = array_filter(array_map(fn($row) => $row['city'], $rows));

        return $this->json(array_values($cities));
    }

    public function arrivalCities(): JsonResponse
    {
        $qb = $this->tripRepository->createQueryBuilder('t')
            ->select('DISTINCT COALESCE(t.arrivalCity, ap.city) AS city')
            ->leftJoin('t.arrivalPoint', 'ap')
            ->orderBy('city', 'ASC');

        $rows = $qb->getQuery()->getArrayResult();
        $cities = array_filter(array_map(fn($row) => $row['city'], $rows));

        return $this->json(array_values($cities));
    }

    public function popular(): JsonResponse
    {
        $trips = $this->tripRepository->createQueryBuilder('t')
            ->join('t.agency', 'a')
            ->join('t.bus', 'b')
            ->where('t.status = :status')
            ->setParameter('status', 'planifie')
            ->orderBy('t.departureTime', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->json(array_map([$this, 'normalizeTrip'], $trips));
    }

    private function normalizeTrip(Trip $trip): array
    {
        $busCategory = $trip->getBus()?->getCategory() ?? '';
        $category = strtoupper($busCategory) === 'VIP' ? 'VIP' : 'Classique';

        $boardingPoints = $trip->getBoardingPoints() ?: [];
        $deboardingPoints = $trip->getDeboardingPoints() ?: [];

        return [
            'id' => $trip->getId(),
            'departureCity' => $trip->getDepartureCity(),
            'arrivalCity' => $trip->getArrivalCity(),
            'boardingPoints' => $boardingPoints,
            'deboardingPoints' => $deboardingPoints,
            'departurePoint' => $trip->getDeparturePoint() ? [
                'id' => $trip->getDeparturePoint()?->getId(),
                'name' => $trip->getDeparturePoint()?->getName(),
                'address' => $trip->getDeparturePoint()?->getAddress(),
                'city' => $trip->getDeparturePoint()?->getCity(),
            ] : null,
            'arrivalPoint' => $trip->getArrivalPoint() ? [
                'id' => $trip->getArrivalPoint()?->getId(),
                'name' => $trip->getArrivalPoint()?->getName(),
                'address' => $trip->getArrivalPoint()?->getAddress(),
                'city' => $trip->getArrivalPoint()?->getCity(),
            ] : null,
            'departureTime' => $trip->getDepartureTime()?->format(\DateTimeInterface::ATOM),
            'estimatedArrivalTime' => $trip->getEstimatedArrivalTime()?->format(\DateTimeInterface::ATOM),
            'tripDate' => $trip->getTripDate()?->format('Y-m-d'),
            'departureTimeOfDay' => $trip->getDepartureTimeOfDay()?->format('H:i'),
            'arrivalTimeOfDay' => $trip->getArrivalTimeOfDay()?->format('H:i'),
            'departureDate' => $trip->getTripDate()?->format('Y-m-d') ?? $trip->getDepartureTime()?->format('Y-m-d'),
            'price' => $trip->getPrice(),
            'driverName' => $trip->getDriverName(),
            'status' => $trip->getStatus(),
            'seatsReserved' => $trip->getSeatsReserved(),
            'category' => $category,
            'maxSeats' => $trip->getBus()?->getCapacity() ?? 0,
            'availableSeats' => max(0, ($trip->getBus()?->getCapacity() ?? 0) - $trip->getSeatsReserved()),
            'pricePerSeat' => (float)$trip->getPrice(),
            'agencyName' => $trip->getAgency()?->getName(),
            'agencyLogo' => $trip->getAgency()?->getLogoUrl(),
            'bus' => [
                'id' => $trip->getBus()?->getId(),
                'registrationNumber' => $trip->getBus()?->getRegistrationNumber(),
                'category' => $trip->getBus()?->getCategory(),
                'capacity' => $trip->getBus()?->getCapacity(),
            ],
            'busType' => $trip->getBus()?->getCategory() ?? null,
            'boardingPoint' => $boardingPoints[0]['name'] ?? null,
            'createdAt' => $trip->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $trip->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
