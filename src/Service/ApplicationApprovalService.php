<?php

namespace App\Service;

use App\Dto\ApplicationApproveDto;
use App\Dto\ApplicationRejectDto;
use App\Entity\Agency;
use App\Entity\Agent;
use App\Entity\Application;
use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Repository\ApplicationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Application Approval Service for handling the approval and rejection workflows.
 * This service encapsulates the business logic for:
 * - Approving applications (agency creation, admin user creation, email notifications)
 * - Rejecting applications (status update, email notifications)
 */
class ApplicationApprovalService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ApplicationRepository $applicationRepository,
        private AgencyRepository $agencyRepository,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ApplicationEmailService $emailService,
    ) {}

    /**
     * Approve a partnership application.
     * This method orchestrates the complete approval workflow:
     * 1. Create the Agency entity from application data
     * 2. Create an Admin User for the agency
     * 3. Link both to the application
     * 4. Update application status
     * 5. Send approval notification email
     *
     * @return array Results containing agencyId and adminUserId
     * @throws \Exception If any step in the workflow fails
     */
    public function approveApplication(
        Application $application,
        ApplicationApproveDto $dto,
        ?\Symfony\Component\Security\Core\User\UserInterface $currentUser = null
    ): array {
        // Step 1: Validate that the application can be approved
        if ($application->getStatus() !== 'PENDING' && $application->getStatus() !== 'UNDER_REVIEW') {
            throw new \LogicException('Only PENDING or UNDER_REVIEW applications can be approved');
        }

        $temporaryPassword = $dto->temporaryPassword ?? $this->generateTemporaryPassword();

        // Tout le workflow s'exécute dans UNE seule transaction : soit tout est
        // enregistré (agence + utilisateur + agent + candidature), soit rien ne
        // l'est en cas d'erreur. On ne fait plus de flush() intermédiaire, et on
        // ne fait plus appel à $this->em->rollback() : EntityManagerInterface ne
        // possède pas cette méthode (elle existe sur Connection), donc le code
        // précédent provoquait une seconde erreur fatale dans le catch, qui
        // masquait la cause réelle, tout en laissant l'agence et l'utilisateur
        // déjà flush() persistés en base malgré l'échec.
        return $this->em->wrapInTransaction(function () use ($application, $dto, $currentUser, $temporaryPassword) {
            // Step 2: Create Agency entity
            $agency = new Agency();
            $agency->setName($dto->agencyNameOverride ?? $application->getAgencyName());
            $agency->setEmail($application->getEmail());
            $agency->setPhone($application->getPhone());
            $agency->setAddress($application->getAddress() ?? '');
            $agency->setCity($application->getCity());
            $agency->setLegalRepresentative($dto->legalRepresentativeOverride ?? $application->getLegalRepresentative());
            $agency->setStatus('active');
            $agency->setCommissionRate('10.00'); // commissionRate est un string (DECIMAL), pas un float
            $agency->setIsVerified(true);

            $this->em->persist($agency);

            // Step 3: Create Admin User for the agency
            $adminUser = new User();
            $adminUser->setFullName($application->getLegalRepresentative());
            $adminUser->setEmail($application->getEmail());
            $adminUser->setPhoneNumber($application->getPhone());
            $adminUser->setPassword($this->hashTemporaryPassword($adminUser, $temporaryPassword));
            $adminUser->setStatus('active');
            $adminUser->setEmailVerified(true);
            $adminUser->setPhoneVerified(false);
            $adminUser->setVilleResidence($application->getCity());

            $this->em->persist($adminUser);

            // Step 4: Create Agent relationship between user and agency.
            // 'admin_agence' est la seule valeur autorisée par Agent::$agentRole
            // (Assert\Choice) pour un rôle d'administrateur d'agence — 'ADMIN'
            // violait cette contrainte.
            $agent = new Agent();
            $agent->setUser($adminUser);
            $agent->setAgency($agency);
            $agent->setAgentRole('admin_agence');
            $agent->setStatus('active');
            $agent->setCommissionRate('0.00'); // L'admin ne touche pas de commission sur sa propre agence

            $this->em->persist($agent);

            // Step 5: Link agency and admin user to application
            $application->setAgency($agency);
            $application->setAdminUser($adminUser);
            $application->setStatus('APPROVED');
            $application->setReviewedAt(new \DateTime());
            $application->setReviewer($currentUser?->getUserIdentifier() ?? 'System');
            if ($dto->reviewerNotes) {
                $application->setReviewerNotes($dto->reviewerNotes);
            }

            $this->em->persist($application);
            $this->em->flush();

            // Step 6: Send approval notification email
            $this->emailService->sendApprovalNotification($application, $adminUser, $agency);

            return [
                'agencyId' => $agency->getId(),
                'adminUserId' => $adminUser->getId(),
                'temporaryPassword' => $temporaryPassword,
            ];
        });
    }

    /**
     * Reject a partnership application.
     * This method:
     * 1. Updates application status to REJECTED
     * 2. Sets rejection reason and reviewer notes
     * 3. Sends rejection notification email
     *
     * @return array Results containing applicationId
     * @throws \Exception If any step in the workflow fails
     */
    public function rejectApplication(
        Application $application,
        ApplicationRejectDto $dto,
        ?\Symfony\Component\Security\Core\User\UserInterface $currentUser = null
    ): array {
        // Validate that the application can be rejected
        if ($application->getStatus() !== 'PENDING' && $application->getStatus() !== 'UNDER_REVIEW') {
            throw new \LogicException('Only PENDING or UNDER_REVIEW applications can be rejected');
        }

        return $this->em->wrapInTransaction(function () use ($application, $dto, $currentUser) {
            // Update application
            $application->setStatus('REJECTED');
            $application->setReviewedAt(new \DateTime());
            $application->setReviewer($currentUser?->getUserIdentifier() ?? 'System');
            $application->setRejectionReason($dto->rejectionReason);
            if ($dto->reviewerNotes) {
                $application->setReviewerNotes($dto->reviewerNotes);
            }

            $this->em->persist($application);
            $this->em->flush();

            // Send rejection notification email
            $this->emailService->sendRejectionNotification($application);

            return [
                'applicationId' => $application->getId(),
                'rejectionReason' => $dto->rejectionReason,
            ];
        });
    }

    /**
     * Generate a temporary password for new agency admin users.
     */
    private function generateTemporaryPassword(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*()';
        $password = '';
        
        for ($i = 0; $i < 12; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }

    /**
     * Hash a temporary password.
     */
    private function hashTemporaryPassword(User $user, string $password): string
    {
        return $this->passwordHasher->hashPassword($user, $password);
    }

    /**
     * Check if an application has all required documents.
     */
    public function hasAllRequiredDocuments(Application $application, array $requiredTypes = ['RCCM', 'NINEA', 'ASSURANCE']): bool
    {
        $existingTypes = [];
        foreach ($application->getDocuments() as $document) {
            $existingTypes[] = $document->getType();
        }
        
        foreach ($requiredTypes as $type) {
            if (!in_array($type, $existingTypes, true)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get missing document types for an application.
     */
    public function getMissingDocumentTypes(Application $application, array $requiredTypes = ['RCCM', 'NINEA', 'ASSURANCE']): array
    {
        $existingTypes = [];
        foreach ($application->getDocuments() as $document) {
            $existingTypes[] = $document->getType();
        }
        
        return array_diff($requiredTypes, $existingTypes);
    }

    /**
     * Validate that an application can be processed (approved or rejected).
     */
    public function validateForProcessing(Application $application): array
    {
        $errors = [];
        $warnings = [];

        // Check status
        if (!in_array($application->getStatus(), ['PENDING', 'UNDER_REVIEW'], true)) {
            $errors[] = 'L\'application ne peut pas être traitée dans son état actuel';
        }

        // Check required fields
        if (empty($application->getAgencyName())) {
            $errors[] = 'Le nom de l\'agence est requis';
        }

        if (empty($application->getEmail())) {
            $errors[] = 'L\'email est requis';
        }

        if (empty($application->getPhone())) {
            $errors[] = 'Le téléphone est requis';
        }

        if (empty($application->getLegalRepresentative())) {
            $errors[] = 'Le représentant légal est requis';
        }

        // Check for required documents
        $missingDocs = $this->getMissingDocumentTypes($application);
        if (!empty($missingDocs)) {
            $warnings[] = 'Documents manquants: ' . implode(', ', $missingDocs);
        }

        return [
            'canBeProcessed' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}