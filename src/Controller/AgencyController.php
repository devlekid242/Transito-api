<?php

namespace App\Controller;

use App\Entity\Agency;
use App\Repository\AgencyPointRepository;
use App\Repository\AgencyRepository;
use App\Repository\TripRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agencies')]
class AgencyController extends AbstractController
{
    #[Route('/{agencyId}/trips', name: 'api_agency_trips', methods: ['GET'])]
    public function trips(
        int $agencyId,
        AgencyRepository $agencyRepository,
        TripRepository $tripRepository
    ): JsonResponse {
        $agency = $agencyRepository->find($agencyId);
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $trips = $tripRepository->findBy(['agency' => $agency], ['departureTime' => 'ASC']);

        return $this->json(array_map([$this, 'normalizeTrip'], $trips));
    }

    #[Route('/{agencyId}/points', name: 'api_agency_points', methods: ['GET'])]
    public function points(
        int $agencyId,
        AgencyRepository $agencyRepository,
        AgencyPointRepository $agencyPointRepository
    ): JsonResponse {
        $agency = $agencyRepository->find($agencyId);
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $points = $agencyPointRepository->findBy(['agency' => $agency], ['city' => 'ASC']);

        return $this->json(array_map([$this, 'normalizePoint'], $points));
    }

    private function normalizeTrip($trip): array
    {
        $capacity = $trip->getBus()?->getCapacity() ?? 0;
        $reserved = $trip->getSeatsReserved();

        return [
            'id' => $trip->getId(),
            'departureCity' => $trip->getDeparturePoint()?->getCity() ?? $trip->getDeparturePoint()?->getAddress() ?? '',
            'arrivalCity' => $trip->getArrivalPoint()?->getCity() ?? $trip->getArrivalPoint()?->getAddress() ?? '',
            'departureTime' => $trip->getDepartureTime()?->format(\DateTimeInterface::ATOM),
            'arrivalTime' => $trip->getEstimatedArrivalTime()?->format(\DateTimeInterface::ATOM),
            'departureDate' => $trip->getDepartureTime()?->format('Y-m-d'),
            'category' => $trip->getBus()?->getName() && str_contains(strtolower($trip->getBus()->getName()), 'vip') ? 'VIP' : 'Classique',
            'maxSeats' => $capacity,
            'availableSeats' => max(0, $capacity - $reserved),
            'pricePerSeat' => (float) $trip->getPrice(),
            'status' => ucfirst($trip->getStatus()),
            'agencyName' => $trip->getAgency()?->getName(),
            'agencyLogo' => $trip->getAgency()?->getLogoUrl(),
            'busType' => $trip->getBus()?->getName() ?? null,
            'boardingPoint' => $trip->getDeparturePoint()?->getAddress() ?? null,
            'createdAt' => $trip->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $trip->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function normalizePoint($point): array
    {
        return [
            'id' => $point->getId(),
            'city' => $point->getCity(),
            'address' => $point->getAddress(),
            'phoneNumber' => $point->getPhoneNumber(),
            'isActive' => $point->isActive(),
        ];
    }
}
