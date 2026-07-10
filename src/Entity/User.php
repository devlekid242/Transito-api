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
        new GetCollection(),
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
    #[ORM\Column(name: 'ville_residence', length: 100)]
    #[Assert\NotBlank(message: "La ville de résidence est obligatoire.")]
    #[Groups(['user:write'])]
    private ?string $villeResidence = null;

    /* NOUVEAU CHAMP : QUARTIER */
    #[ORM\Column(name: 'quartier', length: 100)]
    #[Assert\NotBlank(message: "Le quartier de résidence est obligatoire.")]
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

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'password_reset_code', length: 10, nullable: true)]
    private ?string $passwordResetCode = null;

    #[ORM\Column(name: 'password_reset_expires_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $passwordResetExpiresAt = null;

    #[ORM\Column(name: 'profile_photo_url', length: 500, nullable: true)]
    private ?string $profilePhotoUrl = null;

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

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
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

    public function getPasswordResetCode(): ?string
    {
        return $this->passwordResetCode;
    }

    public function setPasswordResetCode(?string $code): static
    {
        $this->passwordResetCode = $code;
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