<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Security\AdminRoleVoter;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/audit')]
#[IsGranted(AdminRoleVoter::SUPER)]
final class AdminAuditController extends AbstractController
{
    public function __construct(private AuditLogRepository $repository) {}

    #[Route('/domain-logs', name: 'api_admin_domain_audit_logs', methods: ['GET'])]
    public function domainLogs(Request $request): JsonResponse
    {
        $from = $this->parseDate($request->query->get('from'));
        $to = $this->parseDate($request->query->get('to'));
        if ($from) $from = $from->setTime(0, 0, 0);
        if ($to) $to = $to->setTime(23, 59, 59);

        $result = $this->repository->findPage([
            'actorType' => $request->query->get('actorType'),
            'action' => $request->query->get('action'),
            'targetType' => $request->query->get('targetType'),
            'targetId' => $request->query->get('targetId'),
            'from' => $from,
            'to' => $to,
        ], (int) $request->query->get('page', 1), (int) $request->query->get('limit', 50));

        return $this->json([
            'success' => true,
            'data' => [
                'items' => array_map(static fn (AuditLog $log) => [
                    'id' => $log->getId(),
                    'actorType' => $log->getActorType(),
                    'actorId' => $log->getActorId(),
                    'action' => $log->getAction(),
                    'targetType' => $log->getTargetType(),
                    'targetId' => $log->getTargetId(),
                    'before' => $log->getBeforeState(),
                    'after' => $log->getAfterState(),
                    'metadata' => $log->getMetadata(),
                    'ipAddress' => $log->getIpAddress(),
                    'userAgent' => $log->getUserAgent(),
                    'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ], $result['items']),
                'pagination' => [
                    'page' => $result['page'], 'limit' => $result['limit'],
                    'total' => $result['total'], 'pages' => $result['pages'],
                ],
            ],
        ]);
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) return null;
        try { return new \DateTimeImmutable($value); } catch (\Throwable) { return null; }
    }
}
