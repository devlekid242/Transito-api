<?php

namespace App\Controller;

use App\Entity\Agency;
use App\Entity\AgencyDocument;
use App\Repository\AgencyDocumentRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agency-documents')]
class AgencyDocumentController extends AbstractController
{
    #[Route('', name: 'api_agency_documents_list', methods: ['GET'])]
    public function list(
        AgencyDocumentRepository $documentRepository,
        AgentRepository $agentRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agent = $agentRepository->findOneBy(['user' => $user]);
        if (!$agent || !$agent->getAgency()) {
            return $this->json(['message' => 'Agence introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $documents = $documentRepository->findBy(['agency' => $agent->getAgency()], ['createdAt' => 'DESC']);

        return $this->json(array_map([$this, 'normalizeDocument'], $documents));
    }

    #[Route('', name: 'api_agency_documents_create', methods: ['POST'])]
    public function upload(
        Request $request,
        EntityManagerInterface $em,
        AgentRepository $agentRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agent = $agentRepository->findOneBy(['user' => $user]);
        if (!$agent || !$agent->getAgency()) {
            return $this->json(['message' => 'Agence introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('document');
        if (!$file) {
            return $this->json(['message' => 'Aucun document fourni.'], Response::HTTP_BAD_REQUEST);
        }

        $name = $request->request->get('name') ?? $file->getClientOriginalName();
        $type = $request->request->get('type') ?? $file->getClientMimeType();
        $expiryDate = $request->request->get('expiryDate');

        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/agency-documents';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }

        $filename = uniqid('agency_doc_' . $agent->getAgency()->getId() . '_') . '.' . $file->guessExtension();
        $file->move($uploadsDir, $filename);

        $document = new AgencyDocument();
        $document->setAgency($agent->getAgency());
        $document->setName((string)$name);
        $document->setFileUrl('/uploads/agency-documents/' . $filename);
        $document->setType($type ? (string)$type : null);
        if ($expiryDate) {
            try {
                $document->setExpiryDate(new \DateTime((string)$expiryDate));
            } catch (\Exception $e) {
                // ignore invalid expiry date
            }
        }

        $em->persist($document);
        $em->flush();

        return $this->json($this->normalizeDocument($document), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_agency_documents_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        AgencyDocumentRepository $documentRepository,
        EntityManagerInterface $em,
        AgentRepository $agentRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $agent = $agentRepository->findOneBy(['user' => $user]);
        if (!$agent || !$agent->getAgency()) {
            return $this->json(['message' => 'Agence introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $document = $documentRepository->find($id);
        if (!$document || $document->getAgency()->getId() !== $agent->getAgency()->getId()) {
            return $this->json(['message' => 'Document introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->getParameter('kernel.project_dir') . '/public' . $document->getFileUrl();
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $em->remove($document);
        $em->flush();

        return $this->json(['success' => true]);
    }

    private function normalizeDocument(AgencyDocument $document): array
    {
        return [
            'id' => $document->getId(),
            'name' => $document->getName(),
            'fileUrl' => $document->getFileUrl(),
            'type' => $document->getType(),
            'status' => $document->getStatus(),
            'expiryDate' => $document->getExpiryDate()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $document->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
