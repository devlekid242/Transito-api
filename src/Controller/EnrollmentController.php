<?php

namespace App\Controller;

use App\Entity\Application;
use App\Entity\ApplicationDocument;
use App\Repository\ApplicationRepository;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Enrollment Controller for public partnership application submissions.
 * Provides endpoints for submitting new partnership applications.
 */
#[Route('/api/public/enrollment')]
class EnrollmentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private ApplicationRepository $applicationRepository,
        private FileUploadService $fileUploadService,
        private ValidatorInterface $validator,
        private string $publicDir,
    ) {}

    /**
     * Submit a new partnership application.
     * Creates a new application entity with the provided data.
     */
    #[Route('', name: 'api_public_enrollment_submit', methods: ['POST'])]
    public function submitApplication(Request $request): JsonResponse
    {
        try {
            // Parse JSON data
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'message' => 'Données JSON invalides',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate unique reference
            $reference = $this->applicationRepository->generateReference();

            // Create new application
            $application = new Application();
            $application->setReference($reference);
            $application->setAgencyName($data['agencyName'] ?? '');
            $application->setLegalRepresentative($data['legalRepresentative'] ?? '');
            $application->setEmail($data['email'] ?? '');
            $application->setPhone($data['phone'] ?? '');
            $application->setCity($data['city'] ?? '');
            $application->setAddress($data['address'] ?? null);
            $application->setFleetSize($data['fleetSize'] ?? 0);
            $application->setRoutesPlanned($data['routesPlanned'] ?? []);
            $application->setDescription($data['description'] ?? '');
            $application->setStatus('PENDING');

            // Validate the application entity
            $errors = $this->validator->validate($application);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getPropertyPath() . ': ' . $error->getMessage();
                }
                return $this->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $errorMessages,
                ], Response::HTTP_BAD_REQUEST);
            }

            // Handle document uploads if present
            $documents = $data['documents'] ?? [];
            foreach ($documents as $docData) {
                if (isset($docData['file'])) {
                    // In a real implementation, the file would be uploaded as multipart form data
                    // For now, we'll handle base64 encoded files or assume the file is already uploaded
                    $document = new ApplicationDocument();
                    $document->setName($docData['name'] ?? 'Document');
                    $document->setType($docData['type'] ?? 'AUTRE');
                    $document->setSize($docData['size'] ?? '0');
                    $document->setMimeType($docData['mimeType'] ?? null);
                    $document->setOriginalFilename($docData['originalFilename'] ?? null);
                    $document->setUrl($docData['url'] ?? '');
                    $document->setFilePath($docData['filePath'] ?? null);
                    
                    $application->addDocument($document);
                }
            }

            // Save the application
            $this->em->persist($application);
            $this->em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Candidature soumise avec succès',
                'data' => [
                    'applicationId' => $application->getId(),
                    'reference' => $application->getReference(),
                    'status' => $application->getStatus(),
                    'submittedAt' => $application->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
                ],
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la soumission: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check the status of a submitted application by reference.
     * This is a public endpoint for applicants to check their submission status.
     */
    #[Route('/status/{reference}', name: 'api_public_enrollment_status', methods: ['GET'])]
    public function checkStatus(string $reference): JsonResponse
    {
        try {
            $application = $this->applicationRepository->findByReference($reference);

            if (!$application) {
                return $this->json([
                    'success' => false,
                    'message' => 'Candidature introuvable',
                ], Response::HTTP_NOT_FOUND);
            }

            $statusMap = [
                'PENDING' => 'En attente',
                'UNDER_REVIEW' => 'En revue',
                'APPROVED' => 'Approuvée',
                'REJECTED' => 'Rejetée',
            ];

            $statusLabel = $statusMap[$application->getStatus()] ?? $application->getStatus();

            // For approved applications, include additional info
            $responseData = [
                'reference' => $application->getReference(),
                'status' => $application->getStatus(),
                'statusLabel' => $statusLabel,
                'agencyName' => $application->getAgencyName(),
                'submittedAt' => $application->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            ];

            // If approved, include agency and admin user info
            if ($application->getStatus() === 'APPROVED') {
                $agency = $application->getAgency();
                if ($agency) {
                    $responseData['agency'] = [
                        'id' => $agency->getId(),
                        'name' => $agency->getName(),
                    ];
                }

                $adminUser = $application->getAdminUser();
                if ($adminUser) {
                    $responseData['adminCredentials'] = [
                        'email' => $adminUser->getEmail(),
                        'fullName' => $adminUser->getFullName(),
                    ];
                }

                // Include rejection reason if rejected
                if ($application->getRejectionReason()) {
                    $responseData['rejectionReason'] = $application->getRejectionReason();
                }
            }

            return $this->json([
                'success' => true,
                'data' => $responseData,
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification du statut: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload application documents.
     * This endpoint handles file uploads for partnership applications.
     */
    #[Route('/{applicationId}/documents', name: 'api_public_enrollment_upload_documents', methods: ['POST'])]
    public function uploadDocuments(int $applicationId, Request $request): JsonResponse
    {
        try {
            $application = $this->applicationRepository->find($applicationId);

            if (!$application) {
                return $this->json([
                    'success' => false,
                    'message' => 'Candidature introuvable',
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if application is still pending
            if ($application->getStatus() !== 'PENDING') {
                return $this->json([
                    'success' => false,
                    'message' => 'Impossible d\'ajouter des documents à une candidature qui n\'est pas en attente',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Handle file uploads
            $uploadedFiles = $request->files->all();
            $documents = [];

            foreach ($uploadedFiles as $key => $file) {
                if ($file instanceof UploadedFile) {
                    $document = $this->fileUploadService->uploadApplicationDocument(
                        $file,
                        $application,
                        $request->request->get($key . '_type', 'AUTRE'),
                        $request->request->get($key . '_name', $file->getClientOriginalName())
                    );
                    
                    if ($document) {
                        $documents[] = $document;
                        $application->addDocument($document);
                    }
                }
            }

            if (empty($documents)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Aucun fichier valide téléchargé',
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->em->persist($application);
            $this->em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Documents téléchargés avec succès',
                'data' => [
                    'applicationId' => $application->getId(),
                    'documentsCount' => count($documents),
                    'documents' => array_map(function($doc) {
                        return [
                            'id' => $doc->getId(),
                            'name' => $doc->getName(),
                            'type' => $doc->getType(),
                            'originalFilename' => $doc->getOriginalFilename(),
                            'url' => $doc->getUrl(),
                        ];
                    }, $documents),
                ],
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors du téléchargement: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get document type options for the application form.
     */
    #[Route('/document-types', name: 'api_public_enrollment_document_types', methods: ['GET'])]
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

        $requiredTypes = ['RCCM', 'NINEA', 'ASSURANCE'];

        $data = [];
        foreach ($documentTypes as $value => $label) {
            $data[] = [
                'value' => $value,
                'label' => $label,
                'required' => in_array($value, $requiredTypes),
            ];
        }

        return $this->json([
            'success' => true,
            'data' => $data,
            'requiredTypes' => $requiredTypes,
        ]);
    }

    /**
     * Get application submission guidelines and requirements.
     */
    #[Route('/requirements', name: 'api_public_enrollment_requirements', methods: ['GET'])]
    public function getRequirements(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'data' => [
                'title' => 'Devenir partenaire Tansico',
                'description' => 'Rejoignez notre plateforme de gestion de voyages et de transport en soumettant votre candidature.',
                'requirements' => [
                    'Vous devez être une entreprise enregistrée et légale',
                    'Posséder une flotte de véhicules pour le transport de passagers',
                    'Avoir les documents légaux requis (RCCM, NINEA, etc.)',
                    'Être basé dans l\'une des villes desservies par notre plateforme',
                    'Accepter les termes et conditions de notre partenariat',
                ],
                'process' => [
                    'Étape 1: Soumettez votre candidature avec les informations de base',
                    'Étape 2: Téléchargez les documents requis',
                    'Étape 3: Notre équipe examine votre candidature (2-5 jours ouvrables)',
                    'Étape 4: Si approuvé, vous recevrez vos identifiants de connexion',
                    'Étape 5: Commencez à gérer vos trajets et réservations',
                ],
                'contact' => [
                    'email' => 'partenariats@tansico.com',
                    'phone' => '+221 77 123 45 67',
                ],
            ],
        ]);
    }
}
