<?php

namespace App\Controller;

use App\Entity\Agency;
use App\Entity\Bus;
use App\Entity\User;
use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Repository\BusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

class BusController extends AbstractController
{
    private const ALLOWED_CATEGORIES = ['VIP', 'Classique'];
    private const ALLOWED_STATUSES = ['disponible', 'maintenance', 'hors_service'];

    public function __construct(
        private BusRepository $busRepository,
        private AgentRepository $agentRepository,
        private EntityManagerInterface $em,
    ) {}

    public function getAgencyBus(): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable pour l\'utilisateur connecté.'], Response::HTTP_FORBIDDEN);
        }

        $buses = $this->busRepository->findBy(['agency' => $agency]);

        return $this->json($buses, Response::HTTP_OK, [], [AbstractNormalizer::GROUPS => ['bus:read']]);
    }

    public function maintenanceSchedule(Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable pour l\'utilisateur connecté.'], Response::HTTP_FORBIDDEN);
        } 

        $buses = $this->busRepository->createQueryBuilder('b')
            ->andWhere('b.status = :maintenance')
            ->setParameter('maintenance', 'maintenance')
            ->andWhere('b.agency = :agency')
            ->setParameter('agency', $agency)
            ->orderBy('b.lastMaintenanceDate', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $data = array_map(function (Bus $bus) {
            $scheduledAt = $bus->getLastMaintenanceDate()
                ? $bus->getLastMaintenanceDate()->format('Y-m-d')
                : 'À planifier';

            return [
                'id' => $bus->getId(),
                'registrationNumber' => $bus->getRegistrationNumber(),
                'brand' => $bus->getBrand(),
                'model' => $bus->getModel(),
                'category' => $bus->getCategory(),
                'status' => $bus->getStatus(),
                'scheduledAt' => $scheduledAt,
                'description' => $bus->getBrand() && $bus->getModel()
                    ? sprintf('%s %s', $bus->getBrand(), $bus->getModel())
                    : $bus->getCategory(),
                'lastMaintenanceDate' => $bus->getLastMaintenanceDate()?->format('Y-m-d'),
                'acquisitionDate' => $bus->getAcquisitionDate()?->format('Y-m-d'),
                'mileage' => $bus->getMileage(),
            ];
        }, $buses);

        return $this->json($data);
    }

    public function createBus(Request $request): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable pour l\'utilisateur connecté.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $registrationNumber = trim($data['registrationNumber'] ?? '');
        $capacity = isset($data['capacity']) ? (int)$data['capacity'] : null;

        if (!$registrationNumber || !$capacity) {
            return $this->json([
                'message' => 'registrationNumber et capacity sont requis.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($this->busRepository->findOneBy(['registrationNumber' => $registrationNumber])) {
            return $this->json([
                'message' => 'Un bus avec ce numéro d\'immatriculation existe déjà.',
            ], Response::HTTP_CONFLICT);
        }

        $bus = new Bus();
        $bus->setAgency($agency);
        $bus->setRegistrationNumber($registrationNumber);
        $bus->setCapacity($capacity);
        $bus->setCategory($this->sanitizeCategory($data['category'] ?? 'Classique'));
        $bus->setStatus($this->sanitizeStatus($data['status'] ?? 'disponible'));
        $bus->setBrand($data['brand'] ?? null);
        $bus->setModel($data['model'] ?? null);
        $bus->setColor($data['color'] ?? null);
        $bus->setAcquisitionDate($this->parseDate($data['acquisitionDate'] ?? null));
        $bus->setMileage(isset($data['mileage']) && $data['mileage'] !== '' ? (int)$data['mileage'] : null);
        $bus->setLastMaintenanceDate($this->parseDate($data['lastMaintenanceDate'] ?? null));

        $this->em->persist($bus);
        $this->em->flush();

        return $this->json($bus, Response::HTTP_CREATED, [], [AbstractNormalizer::GROUPS => ['bus:read']]);
    }

    public function updateBus(Request $request, int $id): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable pour l\'utilisateur connecté.'], Response::HTTP_FORBIDDEN);
        }

        $bus = $this->busRepository->find($id);
        if (!$bus) {
            return $this->json(['message' => 'Bus introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($bus->getAgency()?->getId() !== $agency->getId()) {
            return $this->json(['message' => 'Accès refusé à ce bus.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('registrationNumber', $data)) {
            $registrationNumber = trim($data['registrationNumber'] ?? '');
            if ($registrationNumber && $registrationNumber !== $bus->getRegistrationNumber()) {
                if ($this->busRepository->findOneBy(['registrationNumber' => $registrationNumber])) {
                    return $this->json([
                        'message' => 'Un bus avec ce numéro d\'immatriculation existe déjà.',
                    ], Response::HTTP_CONFLICT);
                }
                $bus->setRegistrationNumber($registrationNumber);
            }
        }

        if (array_key_exists('capacity', $data) && $data['capacity'] !== null) {
            $bus->setCapacity((int)$data['capacity']);
        }
        if (array_key_exists('category', $data) && $data['category'] !== null) {
            $bus->setCategory($this->sanitizeCategory($data['category']));
        }
        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $bus->setStatus($this->sanitizeStatus($data['status']));
        }
        if (array_key_exists('brand', $data)) {
            $bus->setBrand($data['brand'] ?? null);
        }
        if (array_key_exists('model', $data)) {
            $bus->setModel($data['model'] ?? null);
        }
        if (array_key_exists('color', $data)) {
            $bus->setColor($data['color'] ?? null);
        }
        if (array_key_exists('acquisitionDate', $data)) {
            $bus->setAcquisitionDate($this->parseDate($data['acquisitionDate'] ?? null));
        }
        if (array_key_exists('mileage', $data)) {
            $bus->setMileage($data['mileage'] !== '' ? (int)$data['mileage'] : null);
        }
        if (array_key_exists('lastMaintenanceDate', $data)) {
            $bus->setLastMaintenanceDate($this->parseDate($data['lastMaintenanceDate'] ?? null));
        }

        $this->em->persist($bus);
        $this->em->flush();

        return $this->json($bus, Response::HTTP_OK, [], [AbstractNormalizer::GROUPS => ['bus:read']]);
    }

    public function deleteBus(int $id): JsonResponse
    {
        $agency = $this->getAuthenticatedAgency();
        if (!$agency) {
            return $this->json(['message' => 'Agence introuvable pour l\'utilisateur connecté.'], Response::HTTP_FORBIDDEN);
        }

        $bus = $this->busRepository->find($id);
        if (!$bus) {
            return $this->json(['message' => 'Bus introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($bus->getAgency()?->getId() !== $agency->getId()) {
            return $this->json(['message' => 'Accès refusé à ce bus.'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($bus);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Bus supprimé avec succès.',
        ]);
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

    private function sanitizeCategory(?string $category): string
    {
        $category = trim((string)$category);
        return in_array($category, self::ALLOWED_CATEGORIES, true) ? $category : 'Classique';
    }

    private function sanitizeStatus(?string $status): string
    {
        $status = trim((string)$status);
        return in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'disponible';
    }

    private function parseDate(?string $value): ?\DateTimeInterface
    {
        if (!$value) {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
