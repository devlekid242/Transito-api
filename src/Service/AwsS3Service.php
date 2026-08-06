<?php

namespace App\Service;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Service for handling file uploads to AWS S3
 * This service provides a unified interface for uploading, deleting, and managing files in S3
 */
class AwsS3Service
{
    private S3Client $s3Client;
    private AsciiSlugger $slugger;
    private string $bucketName;
    private string $region;
    private string $cdnBaseUrl;

    public function __construct(
        string $awsAccessKeyId,
        string $awsSecretAccessKey,
        string $bucketName,
        string $region = 'us-east-1',
        string $cdnBaseUrl = ''
    ) {
        $this->bucketName = $bucketName;
        $this->region = $region;
        $this->cdnBaseUrl = $cdnBaseUrl;
        $this->slugger = new AsciiSlugger();

        // Initialize S3 client
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => [
                'key' => $awsAccessKeyId,
                'secret' => $awsSecretAccessKey,
            ],
        ]);
    }

    /**
     * Upload a file to S3 and return the URL
     */
    public function uploadFile(
        UploadedFile $file,
        string $prefix = 'profiles',
        ?string $customFilename = null
    ): string {
        // Validate file
        $this->validateFile($file);
        
        // Generate filename
        $extension = $file->guessExtension() ?: 'jpg';
        $safeFilename = $customFilename ?: $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $prefix . '/' . uniqid() . '_' . $safeFilename . '.' . $extension;
        
        // Upload to S3
        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $filename,
                'SourceFile' => $file->getPathname(),
                'ACL' => 'private', // or 'public-read' if files should be public
                'ContentType' => $file->getClientMimeType(),
                // Enable server-side encryption
                'ServerSideEncryption' => 'AES256',
                // Add metadata
                'Metadata' => [
                    'uploaded-by' => 'transito-admin',
                    'original-filename' => $file->getClientOriginalName(),
                ],
            ]);

            // Return the URL (using CDN if configured, otherwise S3 URL)
            return $this->getFileUrl($filename);

        } catch (AwsException $e) {
            throw new \RuntimeException('Erreur lors du téléchargement vers S3: ' . $e->getMessage());
        }
    }

    /**
     * Upload a file from a base64 string
     */
    public function uploadBase64File(
        string $base64Data,
        string $prefix = 'profiles',
        string $filename = 'photo'
    ): string {
        // Parse base64 data
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
            $extension = $matches[1];
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        } else {
            throw new \InvalidArgumentException('Données base64 invalides');
        }

        // Generate filename
        $filename = $prefix . '/' . uniqid() . '_' . $filename . '.' . $extension;
        
        // Upload to S3
        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $filename,
                'Body' => base64_decode($base64Data),
                'ACL' => 'private',
                'ContentType' => 'image/' . $extension,
                'ServerSideEncryption' => 'AES256',
            ]);

            return $this->getFileUrl($filename);

        } catch (AwsException $e) {
            throw new \RuntimeException('Erreur lors du téléchargement vers S3: ' . $e->getMessage());
        }
    }

    /**
     * Delete a file from S3
     */
    public function deleteFile(string $fileUrl): bool
    {
        try {
            $filename = $this->extractFilenameFromUrl($fileUrl);
            
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucketName,
                'Key' => $filename,
            ]);

            return true;

        } catch (AwsException $e) {
            // Log error but don't throw exception (file might not exist)
            error_log('Erreur lors de la suppression du fichier S3: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a file exists in S3
     */
    public function fileExists(string $fileUrl): bool
    {
        try {
            $filename = $this->extractFilenameFromUrl($fileUrl);
            
            return $this->s3Client->doesObjectExist($this->bucketName, $filename);

        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Get file metadata from S3
     */
    public function getFileMetadata(string $fileUrl): array
    {
        try {
            $filename = $this->extractFilenameFromUrl($fileUrl);
            
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucketName,
                'Key' => $filename,
            ]);

            return [
                'contentType' => $result['ContentType'] ?? '',
                'contentLength' => $result['ContentLength'] ?? 0,
                'lastModified' => $result['LastModified'] ?? null,
                'metadata' => $result['Metadata'] ?? [],
            ];

        } catch (AwsException $e) {
            return [];
        }
    }

    /**
     * Generate a pre-signed URL for temporary access
     */
    public function generatePresignedUrl(string $fileUrl, int $expiresIn = 3600): string
    {
        $filename = $this->extractFilenameFromUrl($fileUrl);
        
        $cmd = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->bucketName,
            'Key' => $filename,
        ]);

        $request = $this->s3Client->createPresignedRequest($cmd, '+20 minutes');
        
        return (string) $request->getUri();
    }

    /**
     * List files with a specific prefix
     */
    public function listFiles(string $prefix = '', int $maxKeys = 100): array
    {
        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucketName,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys,
            ]);

            $files = [];
            foreach ($result['Contents'] ?? [] as $object) {
                $files[] = [
                    'key' => $object['Key'],
                    'size' => $object['Size'],
                    'lastModified' => $object['LastModified'],
                    'url' => $this->getFileUrl($object['Key']),
                ];
            }

            return $files;

        } catch (AwsException $e) {
            return [];
        }
    }

    /**
     * Get the bucket name
     */
    public function getBucketName(): string
    {
        return $this->bucketName;
    }

    /**
     * Get the CDN base URL
     */
    public function getCdnBaseUrl(): string
    {
        return $this->cdnBaseUrl;
    }

    /**
     * Set the CDN base URL
     */
    public function setCdnBaseUrl(string $cdnBaseUrl): void
    {
        $this->cdnBaseUrl = $cdnBaseUrl;
    }

    /**
     * Get the full URL for a file
     */
    private function getFileUrl(string $filename): string
    {
        if ($this->cdnBaseUrl) {
            return rtrim($this->cdnBaseUrl, '/') . '/' . $filename;
        }
        
        // Return S3 public URL
        return 'https://' . $this->bucketName . '.s3.' . $this->region . '.amazonaws.com/' . $filename;
    }

    /**
     * Extract filename from URL
     */
    private function extractFilenameFromUrl(string $fileUrl): string
    {
        // Remove CDN base URL if present
        if ($this->cdnBaseUrl && strpos($fileUrl, $this->cdnBaseUrl) === 0) {
            return substr($fileUrl, strlen($this->cdnBaseUrl) + 1);
        }
        
        // Remove S3 base URL
        $filename = parse_url($fileUrl, PHP_URL_PATH);
        return ltrim($filename, '/');
    }

    /**
     * Validate uploaded file
     */
    private function validateFile(UploadedFile $file): void
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($file->getClientMimeType(), $allowedTypes)) {
            throw new \InvalidArgumentException(
                'Type de fichier non autorisé. Types autorisés: JPEG, PNG, GIF, WebP'
            );
        }
        
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException(
                'La taille du fichier dépasse 2MB'
            );
        }
        
        if (!getimagesize($file->getPathname())) {
            throw new \InvalidArgumentException(
                'Le fichier sélectionné n\'est pas une image valide'
            );
        }
    }

    /**
     * Format file size in human-readable format
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        
        return round($bytes, 2) . ' ' . $units[$index];
    }
}