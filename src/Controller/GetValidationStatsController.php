<?php

namespace App\Controller;

use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GetValidationStatsController extends AbstractController
{
    public function __invoke(int $tripId, TicketRepository $ticketRepository): JsonResponse
    {
        // Récupérer les statistiques de validation pour le trajet spécifié
        $stats = $ticketRepository->getValidationStatsByTrip($tripId);

        if (!$stats) {
            return $this->json(['error' => 'Trip not found or no validation stats available'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($stats);
    }
}