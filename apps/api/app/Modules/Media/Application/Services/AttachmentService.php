<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Media\Domain\DocumentAttachment;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService
{
    /**
     * Maximum file size in bytes (10MB)
     */
    public const MAX_FILE_SIZE = 10485760;

    /**
     * Allowed MIME types for document attachments
     *
     * @var array<string>
     */
    public const ALLOWED_MIME_TYPES = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // Text
        'text/plain',
        'text/csv',
    ];

    /**
     * Upload a file attachment to a document
     */
    public function upload(
        Document $document,
        UploadedFile $file,
        Authenticatable $user,
        ?string $description = null
    ): DocumentAttachment {
        $this->validateFile($file);

        $filename = $this->generateFilename($file);
        $storagePath = $this->getStoragePath($document, $filename);
        $disk = $this->getStorageDisk($document);

        // Store the file
        Storage::disk($disk)->put($storagePath, $file->getContent());

        // Create the attachment record
        return DocumentAttachment::create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'uploaded_by' => $user->getAuthIdentifier(),
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize(),
            'storage_path' => $storagePath,
            'storage_disk' => $disk,
            'description' => $description,
        ]);
    }

    /**
     * Get all attachments for a document
     *
     * @return Collection<int, DocumentAttachment>
     */
    public function getAttachments(Document $document): Collection
    {
        return DocumentAttachment::forDocument($document->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Download an attachment
     */
    public function download(DocumentAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->storage_disk);

        if (! $disk->exists($attachment->storage_path)) {
            throw new \RuntimeException('Attachment file not found on storage.');
        }

        return $disk->download(
            $attachment->storage_path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
            ]
        );
    }

    /**
     * Get the URL for viewing/previewing an attachment
     */
    public function getUrl(DocumentAttachment $attachment): ?string
    {
        $disk = Storage::disk($attachment->storage_disk);

        if (! $disk->exists($attachment->storage_path)) {
            return null;
        }

        // For local disk, generate a temporary signed URL via controller
        // For S3 or public disks, can return direct URL
        if ($attachment->storage_disk === 'local') {
            return null; // Frontend should use download endpoint
        }

        return $disk->url($attachment->storage_path);
    }

    /**
     * Delete an attachment
     */
    public function delete(DocumentAttachment $attachment): bool
    {
        $disk = Storage::disk($attachment->storage_disk);

        // Delete the file from storage
        if ($disk->exists($attachment->storage_path)) {
            $disk->delete($attachment->storage_path);
        }

        // Delete the database record
        return (bool) $attachment->delete();
    }

    /**
     * Validate uploaded file
     *
     * @throws \InvalidArgumentException
     */
    private function validateFile(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                sprintf('File size exceeds maximum allowed size of %d MB.', self::MAX_FILE_SIZE / 1048576)
            );
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('File type "%s" is not allowed.', $mimeType ?? 'unknown')
            );
        }
    }

    /**
     * Generate a unique filename for storage
     */
    private function generateFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $uniqueId = Str::uuid()->toString();

        return $uniqueId.'.'.$extension;
    }

    /**
     * Get the storage path for the attachment
     */
    private function getStoragePath(Document $document, string $filename): string
    {
        return sprintf(
            'attachments/%s/%s/%s',
            $document->tenant_id,
            $document->id,
            $filename
        );
    }

    /**
     * Get the storage disk to use for the tenant
     */
    private function getStorageDisk(Document $document): string
    {
        // Could be extended to support S3 per tenant
        return 'local';
    }

    /**
     * Get allowed extensions for frontend validation
     *
     * @return array<string>
     */
    public static function getAllowedExtensions(): array
    {
        return [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'txt', 'csv',
        ];
    }
}
