<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Service dedicated to handling profile photo uploads and management
 */
class ProfilePhotoService
{
    private string $uploadDirectory;
    private AsciiSlugger $slugger;

    public function __construct(string $publicDir)
    {
        $this->uploadDirectory = $publicDir . '/uploads/profiles';
        $this->slugger = new AsciiSlugger();
        
        // Ensure upload directory exists
        if (!file_exists($this->uploadDirectory)) {
            mkdir($this->uploadDirectory, 0777, true);
        }
    }

    /**
     * Upload a profile photo and return the relative URL
     */
    public function uploadProfilePhoto(UploadedFile $file, int $userId): string
    {
        // Validate file
        $this->validateFile($file);
        
        // Generate unique filename
        $extension = $file->guessExtension() ?: 'jpg';
        $safeFilename = $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $newFilename = 'profile_' . $userId . '_' . uniqid() . '.' . $extension;
        
        // Move the file
        $file->move($this->uploadDirectory, $newFilename);
        
        // Return the web-accessible URL
        return '/uploads/profiles/' . $newFilename;
    }

    /**
     * Delete a profile photo
     */
    public function deleteProfilePhoto(?string $photoUrl): bool
    {
        if (!$photoUrl || strpos($photoUrl, '/uploads/profiles/') !== 0) {
            return false;
        }
        
        $filename = basename($photoUrl);
        $filePath = $this->uploadDirectory . '/' . $filename;
        
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        
        return false;
    }

    /**
     * Validate uploaded file
     */
    public function validateFile(UploadedFile $file): void
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        // Check file type
        if (!in_array($file->getClientMimeType(), $allowedTypes)) {
            throw new \InvalidArgumentException(
                'Type de fichier non autorisé. Types autorisés: JPEG, PNG, GIF, WebP'
            );
        }
        
        // Check file size
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException(
                'La taille du fichier dépasse 2MB'
            );
        }
        
        // Check if file is actually an image
        if (!getimagesize($file->getPathname())) {
            throw new \InvalidArgumentException(
                'Le fichier sélectionné n\'est pas une image valide'
            );
        }
    }

    /**
     * Get file information
     */
    public function getFileInfo(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'size_formatted' => $this->formatFileSize($file->getSize()),
            'extension' => $file->guessExtension() ?: 'unknown',
        ];
    }

    /**
     * Format file size in human-readable format
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        
        return round($bytes, 2) . ' ' . $units[$index];
    }

    /**
     * Check if photo URL is valid and accessible
     */
    public function isValidPhotoUrl(?string $photoUrl): bool
    {
        if (!$photoUrl) {
            return false;
        }
        
        // Check if it's a data URL or a regular URL
        if (strpos($photoUrl, 'data:image/') === 0) {
            return true; // Base64 encoded image
        }
        
        // Check if file exists in uploads directory
        if (strpos($photoUrl, '/uploads/profiles/') === 0) {
            $filename = basename($photoUrl);
            $filePath = $this->uploadDirectory . '/' . $filename;
            return file_exists($filePath);
        }
        
        return false;
    }

    /**
     * Get the upload directory
     */
    public function getUploadDirectory(): string
    {
        return $this->uploadDirectory;
    }
}