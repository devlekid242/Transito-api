<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use App\Controller\UserController;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;


#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`users`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_PHONE_NUMBER', fields: ['phoneNumber'])] 
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])] 
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new GetCollection(
            uriTemplate: '/users/staff',
            controller: UserController::class . '::getStaffUsers',
            read: false,
            name: 'api_users_get_staff'
        ),
        new Get(
            uriTemplate: '/users/me',
            controller: UserController::class . '::currentUser',
            read: false,
            name: 'api_user_current'
        ),
        new Put(
            uriTemplate: '/users/me',
            controller: UserController::class . '::update',
            read: false,
            name: 'api_user_update_put'
        ),
        new Patch(
            uriTemplate: '/users/me',
            controller: UserController::class . '::update',
            read: false,
            name: 'api_user_update_patch'
        ),
        new Put(
            uriTemplate: '/users/me/change-password',
            controller: UserController::class . '::changePassword',
            read: false,
            name: 'api_user_change_password'
        ),
        new Post(
            uriTemplate: '/users/me/photo',
            controller: UserController::class . '::updatePhoto',
            read: false,
            name: 'api_user_update_photo'
        )
    ]
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'full_name', length: 100)]
    #[Assert\NotBlank(message: "Le nom complet est obligatoire.")]
    #[Groups(['user:write'])]
    private ?string $fullName = null;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    #[Assert\Email(message: "L'adresse email n'est pas valide.")]
    #[Groups(['user:write'])]
    private ?string $email = null;

    #[ORM\Column(name: 'phone', length: 20, unique: true)]
    #[Assert\NotBlank(message: "Le numéro de téléphone est obligatoire.")]
    #[Groups(['user:write'])]
    private ?string $phoneNumber = null;

    /* NOUVEAU CHAMP : VILLE DE RÉSIDENCE */
    #[ORM\Column(name: 'ville_residence', length: 100, nullable: true)]
    #[Assert\NotBlank(message: "La ville de résidence est obligatoire.")]
    #[Groups(['user:write'])]
    private ?string $villeResidence = null;

    /* NOUVEAU CHAMP : QUARTIER */
    // Nullable car les comptes admin d'agence créés lors de l'approbation d'une
    // candidature (ApplicationApprovalService) ne renseignent pas ce champ.
    // La contrainte Assert\NotBlank ne s'applique qu'au formulaire d'inscription
    // "utilisateur passager" (groupe user:write), pas à la création interne.
    #[ORM\Column(name: 'quartier', length: 100, nullable: true)]
    #[Assert\NotBlank(message: "Le quartier de résidence est obligatoire.", groups: ['registration'])]
    #[Groups(['user:write'])]
    private ?string $quartier = null;

    /* NOUVEAU CHAMP : NOM DE L'URGENCE */
    #[ORM\Column(name: 'emergency_contact_name', length: 100, nullable: true)]
    #[Assert\NotBlank(message: "Le nom du contact d'urgence est obligatoire.")]
    #[Groups(['user:write'])]
    private ?string $emergencyContactName = null;

    /* NOUVEAU CHAMP : TÉLÉPHONE DE L'URGENCE */
    #[ORM\Column(name: 'emergency_contact_phone', length: 20, nullable: true)]
    #[Assert\NotBlank(message: "Le numéro du contact d'urgence est obligatoire.")]
    #[Groups(['user:write'])]
    private ?string $emergencyContactPhone = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column(name: 'password_hash', length: 255)]
    #[Assert\NotBlank(message: "Le mot de passe est obligatoire.")]
    #[Groups(['user:write'])]
    private ?string $password = null;

    #[ORM\Column(name: 'pref_notifications', type: Types::SMALLINT, options: ['default' => 1])]
    private int $prefNotifications = 1;

    #[ORM\Column(name: 'pref_language', length: 10, options: ['default' => 'fr'])]
    private string $prefLanguage = 'fr';

    #[ORM\Column(name: 'pref_dark_mode', type: Types::SMALLINT, options: ['default' => 0])]
    private int $prefDarkMode = 0;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(name: 'email_verified', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(name: 'phone_verified', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $phoneVerified = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'password_reset_code', length: 10, nullable: true)]
    private ?string $passwordResetCode = null;

    #[ORM\Column(name: 'password_reset_expires_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $passwordResetExpiresAt = null;

    #[ORM\Column(name: 'otp_attempts', type: Types::SMALLINT, options: ['default' => 0])]
    private int $otpAttempts = 0;

    #[ORM\Column(name: 'otp_requested_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $otpRequestedAt = null;

    #[ORM\Column(name: 'last_login_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLoginAt = null;

    #[ORM\Column(name: 'profile_photo_url', length: 500, nullable: true)]
    private ?string $profilePhotoUrl = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Admin::class, cascade: ['persist'])]
    private ?Admin $admin = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Agent::class, cascade: ['persist'])]
    private ?Agent $agent = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->roles = ['ROLE_USER'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    /* GETTERS & SETTERS POUR LES NOUVEAUX CHAMPS */

    public function getVilleResidence(): ?string
    {
        return $this->villeResidence;
    }

    public function setVilleResidence(string $villeResidence): static
    {
        $this->villeResidence = $villeResidence;
        return $this;
    }

    public function getQuartier(): ?string
    {
        return $this->quartier;
    }

    public function setQuartier(string $quartier): static
    {
        $this->quartier = $quartier;
        return $this;
    }

    public function getEmergencyContactName(): ?string
    {
        return $this->emergencyContactName;
    }

    public function setEmergencyContactName(string $emergencyContactName): static
    {
        $this->emergencyContactName = $emergencyContactName;
        return $this;
    }

    public function getEmergencyContactPhone(): ?string
    {
        return $this->emergencyContactPhone;
    }

    public function setEmergencyContactPhone(string $emergencyContactPhone): static
    {
        $this->emergencyContactPhone = $emergencyContactPhone;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->phoneNumber;
    }

    public function getAdmin(): ?Admin
    {
        return $this->admin;
    }

    public function setAdmin(?Admin $admin): static
    {
        $this->admin = $admin;
        return $this;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function setAgent(?Agent $agent): static
    {
        $this->agent = $agent;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        // Dérive les rôles Symfony depuis l'entité Admin (source de vérité unique)
        if ($this->admin && $this->admin->getStatus() === 'active') {
            $roles[] = 'ROLE_ADMIN';
            $roles[] = 'ROLE_' . $this->admin->getAdminRole(); // ex: ROLE_SUPER_ADMIN
        }

        // 👈 NOUVEAU : dérive les rôles Symfony depuis l'entité Agent, sur le
        // même principe que Admin ci-dessus. Avant ce correctif, aucun compte
        // "agence" ne portait de rôle Symfony exploitable par un
        // #[IsGranted(...)] — un admin_agence légitime se serait pris un 403
        // sur n'importe quelle route protégée par ROLE_AGENCY_ADMIN, dont le
        // futur PartnerSupportController.
        //
        // - agent_quai (embarquement) → ROLE_AGENT uniquement (pas d'accès
        //   back-office).
        // - admin_agence (back-office partenaire) → ROLE_AGENT +
        //   ROLE_AGENCY_ADMIN.
        // Seuls les agents au statut 'active' portent ces rôles, comme pour
        // Admin ci-dessus (un agent désactivé perd son accès immédiatement).
        if ($this->agent && $this->agent->getStatus() === 'active') {
            $roles[] = 'ROLE_AGENT';
            if ($this->agent->getAgentRole() === 'admin_agence') {
                $roles[] = 'ROLE_AGENCY_ADMIN';
            } else {
                $roles[] = 'ROLE_AGENT_QUAI';
            }
        }

        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void {}

    public function getPrefNotifications(): int
    {
        return $this->prefNotifications;
    }

    public function setPrefNotifications(int $prefNotifications): static
    {
        $this->prefNotifications = $prefNotifications;
        return $this;
    }

    public function getPrefLanguage(): string
    {
        return $this->prefLanguage;
    }

    public function setPrefLanguage(string $prefLanguage): static
    {
        $this->prefLanguage = $prefLanguage;
        return $this;
    }

    public function getPrefDarkMode(): int
    {
        return $this->prefDarkMode;
    }

    public function setPrefDarkMode(int $prefDarkMode): static
    {
        $this->prefDarkMode = $prefDarkMode;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;
        return $this;
    }

    public function isPhoneVerified(): bool
    {
        return $this->phoneVerified;
    }

    public function setPhoneVerified(bool $phoneVerified): static
    {
        $this->phoneVerified = $phoneVerified;
        return $this;
    }

    public function getPasswordResetCode(): ?string
    {
        return $this->passwordResetCode;
    }

    public function setPasswordResetCode(?string $code): static
    {
        $this->passwordResetCode = $code;
        return $this;
    }

    public function getOtpAttempts(): int
    {
        return $this->otpAttempts;
    }

    public function setOtpAttempts(int $attempts): static
    {
        $this->otpAttempts = $attempts;
        return $this;
    }

    public function getOtpRequestedAt(): ?\DateTimeInterface
    {
        return $this->otpRequestedAt;
    }

    public function setOtpRequestedAt(?\DateTimeInterface $requestedAt): static
    {
        $this->otpRequestedAt = $requestedAt;
        return $this;
    }

    public function getPasswordResetExpiresAt(): ?\DateTimeInterface
    {
        return $this->passwordResetExpiresAt;
    }

    public function setPasswordResetExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->passwordResetExpiresAt = $expiresAt;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeInterface
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeInterface $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getProfilePhotoUrl(): ?string
    {
        return $this->profilePhotoUrl;
    }

    public function setProfilePhotoUrl(?string $profilePhotoUrl): static
    {
        $this->profilePhotoUrl = $profilePhotoUrl;
        return $this;
    }
}