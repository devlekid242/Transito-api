<?php

namespace App\Controller;

use App\Repository\CityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class CityController extends AbstractController
{
    public function __construct(private CityRepository $cityRepository) {}

    public function getCities(): JsonResponse
    {
        $cities = $this->cityRepository->findAll();

        $cityNames = array_map(function($city) {
            return $city->getName();
        }, $cities);

        return $this->json(array_unique($cityNames));
    }
}