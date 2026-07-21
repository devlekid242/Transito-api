<?php

namespace App\Controller;

use App\Entity\Agency;
use App\Entity\User;
use App\Repository\AgencyPointRepository;
use App\Repository\AgencyRepository;
use App\Repository\AgentRepository;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

        // $trips = $tripRepository->findBy(['agency' => $agency], ['departureTime' => 'ASC']);

        $qb = $tripRepository->createQueryBuilder('t')
            ->join('t.agency', 'a')
            ->join('t.bus', 'b')
            ->where('t.status = :status')
            ->setParameter('status', 'planifie')
            ->andWhere('t.departureTime >= :now')
            ->setParameter('now', new \DateTime())
            ->andWhere('t.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('t.departureTime', 'ASC');

        $trips = $qb->getQuery()->getResult();

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

    #[Route('/{agencyId}/admin', name: 'api_agency_update_admin', methods: ['PUT'])]
    public function update(
        int $agencyId,
        Request $request,
        AgencyRepository $agencyRepository,
        AgentRepository $agentRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $agencyRepository->find($agencyId);
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $agent = $agentRepository->findOneBy(['user' => $user, 'agency' => $agency]);
        if (!$agent || $agent->getAgentRole() !== 'admin_agence') {
            return $this->json(['message' => 'Accès refusé. Vous devez être administrateur de cette agence.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('name', $data)) {
            $agency->setName((string)$data['name']);
        }
        if (array_key_exists('registrationNumber', $data)) {
            $agency->setRegistrationNumber($data['registrationNumber'] !== null ? (string)$data['registrationNumber'] : null);
        }
        if (array_key_exists('address', $data)) {
            $agency->setAddress($data['address'] !== null ? (string)$data['address'] : null);
        }
        if (array_key_exists('bannerUrl', $data)) {
            $agency->setBannerUrl($data['bannerUrl'] !== null ? (string)$data['bannerUrl'] : null);
        }
        if (array_key_exists('logoUrl', $data)) {
            $agency->setLogoUrl($data['logoUrl'] !== null ? (string)$data['logoUrl'] : null);
        }
        if (array_key_exists('websiteUrl', $data)) {
            $agency->setWebsiteUrl($data['websiteUrl'] !== null ? (string)$data['websiteUrl'] : null);
        }
        if (array_key_exists('mapUrl', $data)) {
            $agency->setMapUrl($data['mapUrl'] !== null ? (string)$data['mapUrl'] : null);
        }
        if (array_key_exists('description', $data)) {
            $agency->setDescription($data['description'] !== null ? (string)$data['description'] : null);
        }
        if (array_key_exists('phone', $data)) {
            $agency->setPhone($data['phone'] !== null ? (string)$data['phone'] : null);
        }
        if (array_key_exists('email', $data)) {
            $agency->setEmail($data['email'] !== null ? (string)$data['email'] : null);
        }
        if (array_key_exists('status', $data)) {
            $agency->setStatus($data['status'] !== null ? (string)$data['status'] : $agency->getStatus());
        }

        $em->persist($agency);
        $em->flush();

        return $this->json($this->normalizeAgency($agency));
    }

    #[Route('/{agencyId}/upload-images', name: 'api_agency_upload_images', methods: ['POST'])]
    public function uploadImages(
        int $agencyId,
        Request $request,
        AgencyRepository $agencyRepository,
        AgentRepository $agentRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agency = $agencyRepository->find($agencyId);
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $agent = $agentRepository->findOneBy(['user' => $user, 'agency' => $agency]);
        if (!$agent || $agent->getAgentRole() !== 'admin_agence') {
            return $this->json(['message' => 'Accès refusé. Vous devez être administrateur de cette agence.'], Response::HTTP_FORBIDDEN);
        }

        $bannerFile = $request->files->get('banner');
        $logoFile = $request->files->get('logo');

        if (!$bannerFile && !$logoFile) {
            return $this->json(['message' => 'Aucun fichier fourni.'], Response::HTTP_BAD_REQUEST);
        }

        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/agency-images';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }

        if ($bannerFile instanceof UploadedFile) {
            $bannerFilename = uniqid('agency_banner_' . $agency->getId() . '_') . '.' . $bannerFile->guessExtension();
            $bannerFile->move($uploadsDir, $bannerFilename);
            $agency->setBannerUrl('/uploads/agency-images/' . $bannerFilename);
        }

        if ($logoFile instanceof UploadedFile) {
            $logoFilename = uniqid('agency_logo_' . $agency->getId() . '_') . '.' . $logoFile->guessExtension();
            $logoFile->move($uploadsDir, $logoFilename);
            $agency->setLogoUrl('/uploads/agency-images/' . $logoFilename);
        }

        $em->persist($agency);
        $em->flush();

        return $this->json($this->normalizeAgency($agency));
    }

    private function normalizeTrip($trip): array
    {
        $capacity = $trip->getBus()?->getCapacity() ?? 0;
        $reserved = $trip->getSeatsReserved();

        return [
            'id' => $trip->getId(),
            'departureCity' => $trip->getDepartureCity() ?? $trip->getDeparturePoint()?->getCity() ?? $trip->getDeparturePoint()?->getAddress() ?? '',
            'arrivalCity' => $trip->getArrivalCity() ?? $trip->getArrivalPoint()?->getCity() ?? $trip->getArrivalPoint()?->getAddress() ?? '',
            'departureTime' => $trip->getDepartureTime()?->format(\DateTimeInterface::ATOM),
            'arrivalTime' => $trip->getEstimatedArrivalTime()?->format(\DateTimeInterface::ATOM),
            'departureDate' => $trip->getDepartureTime()?->format('Y-m-d'),
            'category' => $trip->getBus()?->getCategory() && str_contains(strtolower($trip->getBus()->getCategory()), 'vip') ? 'VIP' : 'Classique',
            'maxSeats' => $capacity,
            'availableSeats' => max(0, $capacity - $reserved),
            'pricePerSeat' => (float) $trip->getPrice(),
            'status' => ucfirst($trip->getStatus()),
            'agencyName' => $trip->getAgency()?->getName(),
            'agencyLogo' => $trip->getAgency()?->getLogoUrl(),
            'busType' => $trip->getBus()?->getModel() ?? null,
            'boardingPoint' => $trip->getBoardingPoints()[0]['name'] ?? $trip->getDeparturePoint()?->getAddress() ?? null,
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
            'phoneNumber' => $point->getPhone(),
            'longitude' => $point->getLongitude(),
            'latitude' => $point->getLatitude(),
            'isActive' => $point->isActive(),
        ];
    }

    private function normalizeAgency(Agency $agency): array
    {
        return [
            'id' => $agency->getId(),
            'name' => $agency->getName(),
            'registrationNumber' => $agency->getRegistrationNumber(),
            'address' => $agency->getAddress(),
            'bannerUrl' => $agency->getBannerUrl(),
            'logoUrl' => $agency->getLogoUrl(),
            'websiteUrl' => $agency->getWebsiteUrl(),
            'mapUrl' => $agency->getMapUrl(),
            'description' => $agency->getDescription(),
            'phone' => $agency->getPhone(),
            'email' => $agency->getEmail(),
            'status' => $agency->getStatus(),
            'ratingCache' => $agency->getRatingCache(),
            'createdAt' => $agency->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
