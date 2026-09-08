<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\JobRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ⚠️ #[IsGranted('ROLE_ADMIN')] est un défaut générique — adapte-le à ton système réel
 * (ex: une permission dédiée comme 'view_finance' si tu veux réserver ces rapports aux
 * admins Finance, cf. les permissions déjà utilisées dans layout.component.ts).
 */
#[Route('/api/admin/job-runs')]
#[IsGranted('ROLE_ADMIN')]
final class AdminJobRunController extends AbstractController
{
    public function __construct(private readonly JobRunRepository $jobRuns)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $page = max(1, (int) $request->query->get('page', 1));
        $commandName = $request->query->get('commandName') ?: null;
        $status = $request->query->get('status') ?: null;
        $sinceParam = $request->query->get('since');
        $since = $sinceParam ? new \DateTimeImmutable((string) $sinceParam) : null;

        $runs = $this->jobRuns->search(
            commandName: $commandName,
            status: $status,
            since: $since,
            limit: $limit,
            offset: ($page - 1) * $limit,
        );

        return new JsonResponse([
            'items' => array_map(static fn($r) => $r->toArray(), $runs),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /** Dernière exécution connue pour chaque commande — pour des cartes "statut actuel". */
    #[Route('/latest', methods: ['GET'])]
    public function latest(): JsonResponse
    {
        $runs = $this->jobRuns->findLatestPerCommand();

        return new JsonResponse(array_map(static fn($r) => $r->toArray(), $runs));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $run = $this->jobRuns->find($id);
        if (!$run) {
            return new JsonResponse(['message' => 'Rapport introuvable.'], 404);
        }

        return new JsonResponse($run->toArray());
    }
}
