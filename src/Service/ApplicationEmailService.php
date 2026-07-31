<?php

namespace App\Service;

use App\Entity\Agency;
use App\Entity\Application;
use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Application Email Service for sending application-related email notifications.
 * Handles approval, rejection, and status update notifications.
 */
class ApplicationEmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private string $fromEmail,
        private string $fromName,
    ) {
        $this->fromEmail = $_ENV['MAILER_FROM_EMAIL'] ?? 'noreply@tansico.com';
        $this->fromName = $_ENV['MAILER_FROM_NAME'] ?? 'Tansico Partenariats';
    }

    /**
     * Send approval notification email to the applicant.
     */
    public function sendApprovalNotification(Application $application, User $adminUser, Agency $agency): void
    {
        $email = (new Email())
            ->from($this->fromEmail)
            ->to($application->getEmail())
            ->subject('Votre candidature de partenariat a été approuvée !')
            ->html($this->renderApprovalEmail($application, $adminUser, $agency))
            ->text($this->renderApprovalEmailText($application, $adminUser, $agency));

        $this->mailer->send($email);
    }

    /**
     * Send rejection notification email to the applicant.
     */
    public function sendRejectionNotification(Application $application): void
    {
        $email = (new Email())
            ->from($this->fromEmail)
            ->to($application->getEmail())
            ->subject('Votre candidature de partenariat - Réponse')
            ->html($this->renderRejectionEmail($application))
            ->text($this->renderRejectionEmailText($application));

        $this->mailer->send($email);
    }

    /**
     * Send review started notification to the applicant.
     */
    public function sendReviewStartedNotification(Application $application): void
    {
        $email = (new Email())
            ->from($this->fromEmail)
            ->to($application->getEmail())
            ->subject('Votre candidature est en cours de revue')
            ->html($this->renderReviewStartedEmail($application))
            ->text($this->renderReviewStartedEmailText($application));

        $this->mailer->send($email);
    }

    /**
     * Send internal notification to admins about new application.
     */
    public function sendNewApplicationNotification(Application $application, string $adminEmail): void
    {
        $email = (new Email())
            ->from($this->fromEmail)
            ->to($adminEmail)
            ->subject('Nouvelle candidature de partenariat reçue')
            ->html($this->renderNewApplicationEmail($application))
            ->text($this->renderNewApplicationEmailText($application));

        $this->mailer->send($email);
    }

    /**
     * Render approval email template.
     */
    private function renderApprovalEmail(Application $application, User $adminUser, Agency $agency): string
    {
        return $this->twig->render('emails/application_approved.html.twig', [
            'application' => $application,
            'agency' => $agency,
            'adminUser' => $adminUser,
            'temporaryPassword' => '*****', // Password should be sent separately or via secure channel
            'loginUrl' => $this->getAdminDashboardUrl(),
            'supportEmail' => 'partenariats@tansico.com',
            'supportPhone' => '+221 77 123 45 67',
        ]);
    }

    /**
     * Render approval email text version.
     */
    private function renderApprovalEmailText(Application $application, User $adminUser, Agency $agency): string
    {
        return $this->twig->render('emails/application_approved.txt.twig', [
            'application' => $application,
            'agency' => $agency,
            'adminUser' => $adminUser,
            'loginUrl' => $this->getAdminDashboardUrl(),
            'supportEmail' => 'partenariats@tansico.com',
            'supportPhone' => '+221 77 123 45 67',
        ]);
    }

    /**
     * Render rejection email template.
     */
    private function renderRejectionEmail(Application $application): string
    {
        return $this->twig->render('emails/application_rejected.html.twig', [
            'application' => $application,
            'rejectionReason' => $application->getRejectionReason(),
            'reviewerNotes' => $application->getReviewerNotes(),
            'supportEmail' => 'partenariats@tansico.com',
            'supportPhone' => '+221 77 123 45 67',
        ]);
    }

    /**
     * Render rejection email text version.
     */
    private function renderRejectionEmailText(Application $application): string
    {
        return $this->twig->render('emails/application_rejected.txt.twig', [
            'application' => $application,
            'rejectionReason' => $application->getRejectionReason(),
            'reviewerNotes' => $application->getReviewerNotes(),
            'supportEmail' => 'partenariats@tansico.com',
            'supportPhone' => '+221 77 123 45 67',
        ]);
    }

    /**
     * Render review started email template.
     */
    private function renderReviewStartedEmail(Application $application): string
    {
        return $this->twig->render('emails/application_review_started.html.twig', [
            'application' => $application,
            'reviewer' => $application->getReviewer(),
            'estimatedProcessingTime' => '2-5 jours ouvrables',
            'supportEmail' => 'partenariats@tansico.com',
        ]);
    }

    /**
     * Render review started email text version.
     */
    private function renderReviewStartedEmailText(Application $application): string
    {
        return $this->twig->render('emails/application_review_started.txt.twig', [
            'application' => $application,
            'reviewer' => $application->getReviewer(),
            'estimatedProcessingTime' => '2-5 jours ouvrables',
            'supportEmail' => 'partenariats@tansico.com',
        ]);
    }

    /**
     * Render new application email template for admins.
     */
    private function renderNewApplicationEmail(Application $application): string
    {
        return $this->twig->render('emails/new_application.html.twig', [
            'application' => $application,
            'adminDashboardUrl' => $this->getAdminDashboardUrl() . '/applications/' . $application->getId(),
            'submittedAt' => $application->getSubmittedAt()?->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Render new application email text version for admins.
     */
    private function renderNewApplicationEmailText(Application $application): string
    {
        return $this->twig->render('emails/new_application.txt.twig', [
            'application' => $application,
            'adminDashboardUrl' => $this->getAdminDashboardUrl() . '/applications/' . $application->getId(),
            'submittedAt' => $application->getSubmittedAt()?->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Get the admin dashboard URL.
     */
    private function getAdminDashboardUrl(): string
    {
        return $_ENV['ADMIN_DASHBOARD_URL'] ?? 'https://admin.tansico.com';
    }

    /**
     * Get the public enrollment URL.
     */
    public function getEnrollmentUrl(): string
    {
        return $_ENV['ENROLLMENT_URL'] ?? 'https://tansico.com/partenariats';
    }
}
