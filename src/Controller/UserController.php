<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserController extends AbstractController
{
    public function currentUser(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->serializeUser($user));
    }

    public function update(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('fullName', $data)) {
            $user->setFullName((string)$data['fullName']);
        }
        if (array_key_exists('email', $data)) {
            $user->setEmail($data['email'] !== null ? (string)$data['email'] : null);
        }
        if (array_key_exists('phoneNumber', $data)) {
            $user->setPhoneNumber((string)$data['phoneNumber']);
        }
        if (array_key_exists('prefNotifications', $data)) {
            $user->setPrefNotifications((int)$data['prefNotifications']);
        }
        if (array_key_exists('prefLanguage', $data)) {
            $user->setPrefLanguage((string)$data['prefLanguage']);
        }
        if (array_key_exists('prefDarkMode', $data)) {
            $user->setPrefDarkMode((int)$data['prefDarkMode']);
        }

        $em->persist($user);
        $em->flush();

        return $this->json($this->serializeUser($user));
    }

    public function updatePhoto(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $request->files->get('profile_photo');
        if (!$file) {
            return $this->json(['message' => 'Aucun fichier fourni.'], Response::HTTP_BAD_REQUEST);
        }

        // Simple file save logic — in production, use proper file storage service (AWS S3, etc.)
        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profile-photos';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }

        $filename = uniqid('photo_' . $user->getId() . '_') . '.' . $file->guessExtension();
        $file->move($uploadsDir, $filename);

        $photoUrl = '/uploads/profile-photos/' . $filename;
        $user->setProfilePhotoUrl($photoUrl);

        $em->persist($user);
        $em->flush();

        return $this->json([
            'message' => 'Photo de profil mise à jour.',
            'profilePhotoUrl' => $photoUrl,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Non autorisé.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $oldPassword = $data['old_password'] ?? null;
        $newPassword = $data['new_password'] ?? null;

        if (!$oldPassword || !$newPassword) {
            return $this->json(['message' => 'old_password et new_password sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$passwordHasher->isPasswordValid($user, $oldPassword)) {
            return $this->json(['message' => 'Ancien mot de passe incorrect.'], Response::HTTP_BAD_REQUEST);
        }

        $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        $em->persist($user);
        $em->flush();

        return $this->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getPhoneNumber(),
            'role' => $user->getRoles()[0] ?? 'Utilisateur',
            'prefNotifications' => $user->getPrefNotifications(),
            'prefLanguage' => $user->getPrefLanguage(),
            'prefDarkMode' => $user->getPrefDarkMode(),
            'isActive' => $user->getStatus() === 'active',
            'emailVerified' => false,
            'phoneVerified' => false,
            'profilePhotoUrl' => $user->getProfilePhotoUrl(),
            'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
