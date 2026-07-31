<?php

namespace App\Service;

use App\Entity\Application;
use App\Entity\ApplicationDocument;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * File Upload Service for handling application document uploads.
 * Provides methods for uploading, processing, and managing application documents.
 */
class FileUploadService
{
    public function __construct(
        private SluggerInterface $slugger,
        private string $publicDir,
        private string $uploadDir = 'uploads/applications',
    ) {
        $this->publicDir = $_ENV['PUBLIC_DIR'] ?? __DIR__ . '/../../public';
    }

    /**
     * Upload an application document.
     */
    public function uploadApplicationDocument(
        UploadedFile $file,
        Application $application,
        string $type = 'AUTRE',
        ?string $name = null
    ): ?ApplicationDocument {
        try {
            // Generate unique filename
            $originalFilename = $file->getClientOriginalName();
            $safeFilename = $this->slugger->slug(pathinfo($originalFilename, PATHINFO_FILENAME));
            $newFilename = $this->generateUniqueFilename($safeFilename, $file->guessExtension());

            // Get file info
            $mimeType = $file->getClientMimeType();
            $fileSize = $file->getSize();
            $sizeInKb = round($fileSize / 1024, 2);

            // Create upload directory if it doesn't exist
            $uploadPath = $this->publicDir . '/' . $this->uploadDir . '/' . date('Y/m/d');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            // Move the file
            $fullPath = $uploadPath . '/' . $newFilename;
            $file->move($uploadPath, $newFilename);

            // Create ApplicationDocument entity
            $document = new ApplicationDocument();
            $document->setApplication($application);
            $document->setName($name ?? $originalFilename);
            $document->setType($type);
            $document->setSize((string) $sizeInKb . ' KB');
            $document->setMimeType($mimeType);
            $document->setOriginalFilename($originalFilename);
            $document->setFilePath($this->uploadDir . '/' . date('Y/m/d') . '/' . $newFilename);
            $document->setUrl('/' . $this->uploadDir . '/' . date('Y/m/d') . '/' . $newFilename);

            return $document;

        } catch (\Exception $e) {
            // Log error
            error_log('File upload error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload multiple application documents.
     */
    public function uploadMultipleApplicationDocuments(
        array $files,
        Application $application,
        array $types = [],
        array $names = []
    ): array {
        $documents = [];
        
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $type = $types[$key] ?? 'AUTRE';
                $name = $names[$key] ?? null;
                
                $document = $this->uploadApplicationDocument($file, $application, $type, $name);
                if ($document) {
                    $documents[] = $document;
                }
            }
        }
        
        return $documents;
    }

    /**
     * Delete an application document.
     */
    public function deleteApplicationDocument(ApplicationDocument $document): bool
    {
        try {
            $filePath = $this->publicDir . '/' . $document->getFilePath();
            
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            return true;
        } catch (\Exception $e) {
            error_log('File deletion error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all documents for an application.
     */
    public function deleteAllApplicationDocuments(Application $application): int
    {
        $count = 0;
        
        foreach ($application->getDocuments() as $document) {
            if ($this->deleteApplicationDocument($document)) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Generate a unique filename to prevent conflicts.
     */
    private function generateUniqueFilename(string $filename, ?string $extension = null): string
    {
        $uniqueId = uniqid();
        $timestamp = date('YmdHis');
        
        if ($extension) {
            return $timestamp . '_' . $uniqueId . '_' . $filename . '.' . $extension;
        }
        
        return $timestamp . '_' . $uniqueId . '_' . $filename;
    }

    /**
     * Validate file upload.
     */
    public function validateFileUpload(UploadedFile $file): array
    {
        $errors = [];
        $warnings = [];

        // Check file size (max 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file->getSize() > $maxSize) {
            $errors[] = 'Le fichier est trop grand (max 10MB)';
        }

        // Check file extension
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $extension = strtolower($file->getClientOriginalExtension());
        
        if (!in_array($extension, $allowedExtensions, true)) {
            $warnings[] = 'Type de fichier non recommandé: ' . $extension;
        }

        // Check MIME type
        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        
        $mimeType = $file->getClientMimeType();
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            $warnings[] = 'Type MIME non recommandé: ' . $mimeType;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get allowed document types for applications.
     */
    public function getAllowedDocumentTypes(): array
    {
        return [
            'RCCM' => 'Registre du Commerce et du Crédit Mobilier',
            'NINEA' => 'Numéro d\'Identification Nationale des Employeurs',
            'ASSURANCE' => 'Assurance Flotte',
            'CARTE_GRISE' => 'Carte Grise',
            'CONTRAT' => 'Contrat Social',
            'AUTRE' => 'Autre Document',
        ];
    }

    /**
     * Get required document types for applications.
     */
    public function getRequiredDocumentTypes(): array
    {
        return ['RCCM', 'NINEA', 'ASSURANCE'];
    }

    /**
     * Check if a file type is required.
     */
    public function isRequiredType(string $type): bool
    {
        return in_array($type, $this->getRequiredDocumentTypes(), true);
    }

    /**
     * Get file size in human-readable format.
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes;
        
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        
        return round($size, 2) . ' ' . $units[$i];
    }
}
