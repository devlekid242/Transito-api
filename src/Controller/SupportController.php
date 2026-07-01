<?php

namespace App\Controller;

use App\Entity\SupportTicket;
use App\Entity\SupportResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SupportController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/api/support', name: 'create_support', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) return new JsonResponse(['error' => 'Invalid'], 400);
        $ticket = new SupportTicket();
        $user = $this->getUser();
        if ($user) $ticket->setUser($user);
        $ticket->setSubject($data['subject'] ?? '');
        $ticket->setMessage($data['message'] ?? '');
        $ticket->setCategory($data['category'] ?? 'other');
        $ticket->setPriority($data['priority'] ?? 'medium');
        $this->em->persist($ticket);
        $this->em->flush();
        return new JsonResponse(['id' => $ticket->getId()], 201);
    }

    #[Route('/api/support/my-tickets', name: 'my_support', methods: ['GET'])]
    public function myTickets(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return new JsonResponse([], 200);
        $list = $this->em->getRepository(SupportTicket::class)->findBy(['user' => $user]);
        $out = array_map(fn($t) => ['id' => $t->getId(), 'subject' => $t->getSubject(), 'status' => $t->getStatus()], $list);
        return new JsonResponse($out, 200);
    }

    #[Route('/api/support/{id}/responses', name: 'add_support_response', methods: ['POST'])]
    public function addResponse(int $id, Request $request): JsonResponse
    {
        $ticket = $this->em->getRepository(SupportTicket::class)->find($id);
        if (!$ticket) return new JsonResponse(['error' => 'Not found'], 404);
        $data = json_decode($request->getContent(), true);
        $resp = new SupportResponse();
        $resp->setTicket($ticket);
        $resp->setMessage($data['message'] ?? '');
        $this->em->persist($resp);
        $this->em->flush();
        return new JsonResponse(['id' => $resp->getId()], 201);
    }
}
