<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`users`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_PHONE_NUMBER', fields: ['phoneNumber'])]
#[ApiResource(
    operations: [
        // Endpoint d'inscription ouvert au public
        new Post(
            uriTemplate: '/register',
            processor: \App\State\UserPasswordProcessor::class,
            denormalizationContext: ['groups' => ['user:write']]
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
        // guarantee every user at least has ROLE_USER
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
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

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
}