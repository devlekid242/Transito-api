<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Repository\TripRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TripController extends AbstractController
{
    public function __construct(private TripRepository $tripRepository) {}

    public function index(Request $request): JsonResponse
    {
        $departureCity = $request->query->get('departure_city');
        $arrivalCity = $request->query->get('arrival_city');
        $departureDate = $request->query->get('departure_date');
        $category = $request->query->get('category');
        $maxPrice = $request->query->get('max_price');
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = max(1, (int)$request->query->get('limit', 10));

        $qb = $this->tripRepository->createQueryBuilder('t')
            ->join('t.departurePoint', 'dp')
            ->join('t.arrivalPoint', 'ap')
            ->join('t.agency', 'a')
            ->join('t.bus', 'b')
            ->orderBy('t.departureTime', 'ASC');

        if ($departureCity) {
            $qb->andWhere('LOWER(dp.city) LIKE :departureCity')
                ->setParameter('departureCity', '%' . mb_strtolower($departureCity) . '%');
        }

        if ($arrivalCity) {
            $qb->andWhere('LOWER(ap.city) LIKE :arrivalCity')
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

        return $this->json($this->normalizeTrip($trip));
    }

    public function departureCities(): JsonResponse
    {
        $qb = $this->tripRepository->createQueryBuilder('t')
            ->select('DISTINCT dp.city AS city')
            ->join('t.departurePoint', 'dp')
            ->orderBy('dp.city', 'ASC');

        $rows = $qb->getQuery()->getArrayResult();
        $cities = array_map(fn($row) => $row['city'], $rows);

        return $this->json($cities);
    }

    public function arrivalCities(): JsonResponse
    {
        $qb = $this->tripRepository->createQueryBuilder('t')
            ->select('DISTINCT ap.city AS city')
            ->join('t.arrivalPoint', 'ap')
            ->orderBy('ap.city', 'ASC');

        $rows = $qb->getQuery()->getArrayResult();
        $cities = array_map(fn($row) => $row['city'], $rows);

        return $this->json($cities);
    }

    public function popular(): JsonResponse
    {
        $trips = $this->tripRepository->createQueryBuilder('t')
            ->join('t.departurePoint', 'dp')
            ->join('t.arrivalPoint', 'ap')
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

        return [
            'id' => $trip->getId(),
            'departureCity' => $trip->getDeparturePoint()?->getCity() ?? '',
            'arrivalCity' => $trip->getArrivalPoint()?->getCity() ?? '',
            'departureTime' => $trip->getDepartureTime()?->format(\DateTimeInterface::ATOM),
            'arrivalTime' => $trip->getEstimatedArrivalTime()?->format(\DateTimeInterface::ATOM),
            'departureDate' => $trip->getDepartureTime()?->format('Y-m-d'),
            'category' => $category,
            'maxSeats' => $trip->getBus()?->getCapacity() ?? 0,
            'availableSeats' => max(0, ($trip->getBus()?->getCapacity() ?? 0) - $trip->getSeatsReserved()),
            'pricePerSeat' => (float)$trip->getPrice(),
            'status' => ucfirst($trip->getStatus()),
            'agencyName' => $trip->getAgency()?->getName(),
            'agencyLogo' => $trip->getAgency()?->getLogoUrl(),
            'busType' => $trip->getBus()?->getCategory() ?? null,
            'boardingPoint' => $trip->getDeparturePoint()?->getName() ?? null,
            'createdAt' => $trip->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $trip->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
