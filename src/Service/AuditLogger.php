<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class AuditLogger
{
    public function __construct(
        private AuditLogRepository $repository,
        private TokenStorageInterface $tokenStorage,
        private RequestStack $requestStack,
    ) {}

    public function record(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?string $actorType = null,
        ?int $actorId = null,
    ): AuditLog {
        $tokenUser = $this->tokenStorage->getToken()?->getUser();
        if ($actorType === null || $actorId === null) {
            [$actorType, $actorId] = $this->resolveActor($tokenUser);
        }

        $request = $this->requestStack->getCurrentRequest();
        $log = new AuditLog();
        $log->setActorType($actorType)
            ->setActorId($actorId)
            ->setAction($action)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setBeforeState($this->sanitize($before))
            ->setAfterState($this->sanitize($after))
            ->setMetadata($this->sanitize($metadata))
            ->setIpAddress($request?->getClientIp())
            ->setUserAgent($request?->headers->get('User-Agent'));

        $this->repository->save($log);
        return $log;
    }

    private function resolveActor(mixed $user): array
    {
        if (!is_object($user)) return [AuditLog::ACTOR_SYSTEM, null];
        if ($user instanceof Admin) return [AuditLog::ACTOR_ADMIN, $user->getId()];
        if (method_exists($user, 'getId')) return [AuditLog::ACTOR_USER, $user->getId()];
        return [AuditLog::ACTOR_SYSTEM, null];
    }

    private function sanitize(?array $data): ?array
    {
        if ($data === null) return null;
        $sensitive = ['password', 'plainPassword', 'passwordHash', 'otp', 'otpCode', 'token', 'refreshToken', 'accessToken', 'secret', 'apiKey', 'rawResponse'];
        $walk = function ($value) use (&$walk, $sensitive) {
            if (!is_array($value)) return $value;
            $result = [];
            foreach ($value as $key => $item) {
                if (in_array(strtolower((string) $key), array_map('strtolower', $sensitive), true)) {
                    $result[$key] = '[REDACTED]';
                } else {
                    $result[$key] = is_array($item) ? $walk($item) : $item;
                }
            }
            return $result;
        };
        return $walk($data);
    }
}
