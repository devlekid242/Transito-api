<?php

namespace App\Controller\Admin;

use App\Dto\ApplicationApproveDto;
use App\Dto\ApplicationRejectDto;
use App\Entity\Agency;
use App\Entity\Agent;
use App\Entity\Application;
use App\Entity\ApplicationDocument;
use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Repository\AgentRepository;
use App\Repository\ApplicationRepository;
use App\Repository\UserRepository;
use App\Service\ApplicationApprovalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Admin Application Controller for Partnership Application Management.
 * Provides endpoints for listing, filtering, viewing, approving, and rejecting applications.
 */
#[Route('/api/admin/applications')]
class AdminApplicationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private ApplicationRepository $applicationRepository,
        private UserRepository $userRepository,
        private AgencyRepository $agencyRepository,
        private AgentRepository $agentRepository,
        private ApplicationApprovalService $approvalService,
        private ValidatorInterface $validator,
    ) {}

    /**
     * Get all applications with optional filtering and pagination.
     * Supports filtering by status, date range, search keyword, and city.
     */
    #[Route('', name: 'api_admin_applications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Parse query parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));
        $status = $request->query->get('status'); // PENDING, UNDER_REVIEW, APPROVED, REJECTED, or ALL
        $search = $request->query->get('search', '');
        $city = $request->query->get('city');
        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');

        // Convert dates if provided
        $startDateObj = $startDate ? new \DateTime($startDate) : null;
        $endDateObj = $endDate ? new \DateTime($endDate) : null;

        // Get paginated results
        $applications = $this->applicationRepository->findPaginated(
            $page, $limit, $status, $search, $city, $startDateObj, $endDateObj
        );

        // Get total count for pagination
        $total = $this->applicationRepository->countFiltered(
            $status, $search, $city, $startDateObj, $endDateObj
        );
        $totalPages = (int) ceil($total / $limit);

        // Normalize response
        $data = array_map([$this, 'normalizeApplicationForList'], $applications);

        return $this->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get application KPI statistics.
     */
    #[Route('/kpis', name: 'api_admin_applications_kpis', methods: ['GET'])]
    public function kpis(Request $request): JsonResponse
    {
        $startDate = $request->query->get('start_date') ? new \DateTime($request->query->get('start_date')) : new \DateTime('-30 days');
        $endDate = $request->query->get('end_date') ? new \DateTime($request->query->get('end_date')) : new \DateTime('now');

        // Count by status
        $total = $this->applicationRepository->count([]);
        $pending = $this->applicationRepository->count(['status' => 'PENDING']);
        $underReview = $this->applicationRepository->count(['status' => 'UNDER_REVIEW']);
        $approved = $this->applicationRepository->count(['status' => 'APPROVED']);
        $rejected = $this->applicationRepository->count(['status' => 'REJECTED']);

        // Count by date range
        $inRange = $this->applicationRepository->countFiltered(
            'ALL', null, null, $startDate, $endDate
        );

        // Get recent applications
        $recent = $this->applicationRepository->findRecent(7);
        $newThisWeek = count($recent);

        return $this->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'pending' => $pending,
                'underReview' => $underReview,
                'approved' => $approved,
                'rejected' => $rejected,
                'newThisWeek' => $newThisWeek,
                'inDateRange' => $inRange,
            ],
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get a single application by ID with full details.
     */
    #[Route('/{id}', name: 'api_admin_applications_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $application = $this->applicationRepository->find($id);

        if (!$application) {
            return $this->json([
                'success' => false,
                'message' => 'Candidature introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = $this->normalizeApplicationDetail($application);

        return $this->json([
            'success' => true,
            'data' => $data,
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Approve a partnership application.
     * This triggers the automated workflow: agency creation, admin user creation, and email notification.
     */
    #[Route('/{id}/approve', name: 'api_admin_applications_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $application = $this->applicationRepository->find($id);

        if (!$application) {
            return $this->json([
                'success' => false,
                'message' => 'Candidature introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        // Parse request data
        $data = json_decode($request->getContent(), true);
        $dto = new ApplicationApproveDto();
        if (isset($data['reviewerNotes'])) {
            $dto->reviewerNotes = $data['reviewerNotes'];
        }

        // Validate DTO
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => (string) $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Use the approval service to handle the workflow
            $result = $this->approvalService->approveApplication($application, $dto, $this->getUser());

            return $this->json([
                'success' => true,
                'message' => 'Candidature approuvée avec succès',
                'data' => [
                    'applicationId' => $application->getId(),
                    'agencyId' => $result['agencyId'] ?? null,
                    'adminUserId' => $result['adminUserId'] ?? null,
                    'status' => 'APPROVED',
                    'reviewedAt' => (new \DateTime())->format(\DateTimeInterface::ATOM),
                ],
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de l\'approbation: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reject a partnership application.
     * This updates the status and sends a notification email to the applicant.
     */
    #[Route('/{id}/reject', name: 'api_admin_applications_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        $application = $this->applicationRepository->find($id);

        if (!$application) {
            return $this->json([
                'success' => false,
                'message' => 'Candidature introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        // Parse request data
        $data = json_decode($request->getContent(), true);
        $dto = new ApplicationRejectDto();
        if (isset($data['rejectionReason'])) {
            $dto->rejectionReason = $data['rejectionReason'];
        }
        if (isset($data['reviewerNotes'])) {
            $dto->reviewerNotes = $data['reviewerNotes'];
        }

        // Validate DTO
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => (string) $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Use the approval service to handle the rejection workflow
            $result = $this->approvalService->rejectApplication($application, $dto, $this->getUser());

            return $this->json([
                'success' => true,
                'message' => 'Candidature rejetée avec succès',
                'data' => [
                    'applicationId' => $application->getId(),
                    'status' => 'REJECTED',
                    'rejectionReason' => $dto->rejectionReason,
                    'reviewedAt' => (new \DateTime())->format(\DateTimeInterface::ATOM),
                ],
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors du rejet: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Start reviewing an application (move from PENDING to UNDER_REVIEW).
     */
    #[Route('/{id}/start-review', name: 'api_admin_applications_start_review', methods: ['POST'])]
    public function startReview(int $id): JsonResponse
    {
        $application = $this->applicationRepository->find($id);

        if (!$application) {
            return $this->json([
                'success' => false,
                'message' => 'Candidature introuvable',
            ], Response::HTTP_NOT_FOUND);
        }

        // Check if already under review or processed
        if (!in_array($application->getStatus(), ['PENDING', 'UNDER_REVIEW'], true)) {
            return $this->json([
                'success' => false,
                'message' => 'La candidature ne peut pas être mise en revue dans son état actuel',
            ], Response::HTTP_BAD_REQUEST);
        }

        $application->setStatus('UNDER_REVIEW');
        $application->setReviewer($this->getUser()?->getEmail() ?? 'System');
        $application->setReviewedAt(new \DateTime());

        $this->em->persist($application);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Candidature mise en revue',
            'data' => [
                'applicationId' => $application->getId(),
                'status' => 'UNDER_REVIEW',
                'reviewer' => $application->getReviewer(),
                'reviewedAt' => $application->getReviewedAt()?->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    /**
     * Get application status options.
     */
    #[Route('/status-options', name: 'api_admin_applications_status_options', methods: ['GET'])]
    public function getStatusOptions(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'data' => [
                ['value' => 'ALL', 'label' => 'Tous les statuts'],
                ['value' => 'PENDING', 'label' => 'En attente'],
                ['value' => 'UNDER_REVIEW', 'label' => 'En revue'],
                ['value' => 'APPROVED', 'label' => 'Approuvée'],
                ['value' => 'REJECTED', 'label' => 'Rejetée'],
            ],
        ]);
    }

    /**
     * Get document type options.
     */
    #[Route('/document-types', name: 'api_admin_applications_document_types', methods: ['GET'])]
    public function getDocumentTypes(): JsonResponse
    {
        $documentTypes = [
            'RCCM' => 'Registre du Commerce et du Crédit Mobilier',
            'NINEA' => 'Numéro d\'Identification Nationale des Employeurs',
            'ASSURANCE' => 'Assurance Flotte',
            'CARTE_GRISE' => 'Carte Grise',
            'CONTRAT' => 'Contrat Social',
            'AUTRE' => 'Autre Document',
        ];

        $data = [];
        foreach ($documentTypes as $value => $label) {
            $data[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Normalize application for list response.
     */
    private function normalizeApplicationForList(Application $application): array
    {
        $statusMap = [
            'PENDING' => 'En attente',
            'UNDER_REVIEW' => 'En revue',
            'APPROVED' => 'Approuvée',
            'REJECTED' => 'Rejetée',
        ];

        return [
            'id' => $application->getId(),
            'ref' => $application->getReference(),
            'agencyName' => $application->getAgencyName(),
            'legalRepresentative' => $application->getLegalRepresentative(),
            'email' => $application->getEmail(),
            'phone' => $application->getPhone(),
            'city' => $application->getCity(),
            'address' => $application->getAddress(),
            'fleetSize' => $application->getFleetSize(),
            'routesPlanned' => $application->getRoutesPlanned(),
            'status' => $application->getStatus(),
            'statusLabel' => $statusMap[$application->getStatus()] ?? $application->getStatus(),
            'submittedAt' => $application->getSubmittedAt()?->format('Y-m-d H:i'),
            'reviewedAt' => $application->getReviewedAt()?->format('Y-m-d H:i'),
            'reviewer' => $application->getReviewer(),
            'documentsCount' => count($application->getDocuments()),
            'createdAt' => $application->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Normalize application detail response.
     */
    private function normalizeApplicationDetail(Application $application): array
    {
        $statusMap = [
            'PENDING' => 'En attente',
            'UNDER_REVIEW' => 'En revue',
            'APPROVED' => 'Approuvée',
            'REJECTED' => 'Rejetée',
        ];

        $applicationData = [
            'id' => $application->getId(),
            'ref' => $application->getReference(),
            'agencyName' => $application->getAgencyName(),
            'legalRepresentative' => $application->getLegalRepresentative(),
            'email' => $application->getEmail(),
            'phone' => $application->getPhone(),
            'city' => $application->getCity(),
            'address' => $application->getAddress(),
            'fleetSize' => $application->getFleetSize(),
            'routesPlanned' => $application->getRoutesPlanned(),
            'description' => $application->getDescription(),
            'status' => $application->getStatus(),
            'statusLabel' => $statusMap[$application->getStatus()] ?? $application->getStatus(),
            'submittedAt' => $application->getSubmittedAt()?->format('Y-m-d H:i'),
            'reviewedAt' => $application->getReviewedAt()?->format('Y-m-d H:i'),
            'reviewer' => $application->getReviewer(),
            'reviewerNotes' => $application->getReviewerNotes(),
            'rejectionReason' => $application->getRejectionReason(),
            'documents' => array_map([$this, 'normalizeDocument'], $application->getDocuments()->toArray()),
            'createdAt' => $application->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $application->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];

        // Include created agency and admin user if approved
        if ($application->getStatus() === 'APPROVED') {
            $agency = $application->getAgency();
            if ($agency) {
                $applicationData['createdAgency'] = [
                    'id' => $agency->getId(),
                    'name' => $agency->getName(),
                    'email' => $agency->getEmail(),
                    'phone' => $agency->getPhone(),
                ];
            }

            $adminUser = $application->getAdminUser();
            if ($adminUser) {
                $applicationData['createdAdminUser'] = [
                    'id' => $adminUser->getId(),
                    'email' => $adminUser->getEmail(),
                    'fullName' => $adminUser->getFullName(),
                ];
            }
        }

        return $applicationData;
    }

    /**
     * Normalize document for response.
     */
    private function normalizeDocument(ApplicationDocument $document): array
    {
        $typeLabels = [
            'RCCM' => 'Registre du Commerce et du Crédit Mobilier',
            'NINEA' => 'Numéro d\'Identification Nationale des Employeurs',
            'ASSURANCE' => 'Assurance Flotte',
            'CARTE_GRISE' => 'Carte Grise',
            'CONTRAT' => 'Contrat Social',
            'AUTRE' => 'Autre Document',
        ];

        $typeIcons = [
            'RCCM' => 'fa-file-contract',
            'NINEA' => 'fa-file-lines',
            'ASSURANCE' => 'fa-shield-halved',
            'CARTE_GRISE' => 'fa-car',
            'CONTRAT' => 'fa-file-signature',
            'AUTRE' => 'fa-file',
        ];

        return [
            'id' => $document->getId(),
            'name' => $document->getName(),
            'type' => $document->getType(),
            'typeLabel' => $typeLabels[$document->getType()] ?? $document->getType(),
            'typeIcon' => $typeIcons[$document->getType()] ?? 'fa-file',
            'size' => $document->getSize(),
            'sizeInBytes' => (int) $document->getSize(),
            'mimeType' => $document->getMimeType(),
            'originalFilename' => $document->getOriginalFilename(),
            'url' => $document->getUrl(),
            'filePath' => $document->getFilePath(),
            'uploadedAt' => $document->getUploadedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
