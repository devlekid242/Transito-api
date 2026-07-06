<?php

namespace App\Controller;

use App\Repository\CityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class GetCitiesController extends AbstractController
{
    public function __invoke(CityRepository $cityRepository): JsonResponse
    {
        // Récupérer toutes les villes actives
        $cities = $cityRepository->findBy(['isActive' => 1], ['name' => 'ASC']);
        
        return $this->json($cities);
    }
}