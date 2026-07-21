<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Controller\DeviceController;
use App\Repository\DeviceTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Token d'appareil (FCM) permettant d'envoyer une notification push native
 * (barre de notification du téléphone) à un utilisateur, indépendamment de
 * Pusher qui ne couvre que le temps réel "app ouverte".
 *
 * Un même utilisateur peut avoir plusieurs lignes (plusieurs téléphones).
 * Un même token ne peut appartenir qu'à un seul utilisateur à la fois : lors
 * d'un nouvel enregistrement, la ligne existante est réassignée (cas d'un
 * appareil partagé ou d'une réinstallation).
 */
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: '`device_tokens`')]
#[ApiResource(
    normalizationContext: ['groups' => ['device_token:read']],
    operations: [
        new Post(
            uriTemplate: '/devices/register',
            controller: DeviceController::class . '::register',
            name: 'api_devices_register'
        ),
        new Post(
            uriTemplate: '/devices/unregister',
            controller: DeviceController::class . '::unregister',
            name: 'api_devices_unregister'
        ),
    ]
)]
class DeviceToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['device_token:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $user = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank(message: 'Le token FCM est obligatoire.')]
    #[Groups(['device_token:read'])]
    private ?string $token = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['ios', 'android', 'web'], message: 'Plateforme inconnue.')]
    #[Groups(['device_token:read'])]
    private string $platform = 'android';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['device_token:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['device_token:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;
        return $this;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): static
    {
        $this->platform = $platform;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}