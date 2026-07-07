<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Services;

use App\Modules\DocumentIngestion\Application\Jobs\ExtractDocumentJob;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Media\Application\Services\MediaAttachmentService;
use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class IngestionService
{
    public function __construct(
        private MediaUploadService $uploadService,
        private MediaAttachmentService $attachmentService,
    ) {}

    public function createFromUpload(
        string $tenantId,
        string $companyId,
        string $userId,
        UploadedFile $file,
        DocumentKind $kind,
    ): DocumentIngestion {
        $ingestionId = (string) Str::uuid();
        $path = $file->getRealPath();
        if (! is_string($path)) {
            throw new \RuntimeException('Uploaded file path is unavailable.');
        }

        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new \RuntimeException('Uploaded file checksum could not be calculated.');
        }

        $duplicateExists = DocumentIngestion::query()
            ->where('company_id', $companyId)
            ->where('checksum', $checksum)
            ->whereNotIn('status', [IngestionStatus::Rejected, IngestionStatus::Failed])
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'file' => ['A non-rejected document ingestion already exists for this file.'],
            ]);
        }

        $mimeType = (string) $file->getMimeType();
        $assetType = str_starts_with($mimeType, 'image/')
            ? MediaAssetType::Image
            : MediaAssetType::Document;

        /** @var array<int, string> $allowedMime */
        $allowedMime = config('media.documents.allowed_mime_types');

        $asset = $this->uploadService->upload(
            tenantId: $tenantId,
            ownerType: MediaOwnerType::DocumentIngestion,
            ownerId: $ingestionId,
            file: $file,
            userId: $userId,
            assetType: $assetType,
            allowedMime: $allowedMime,
        );

        $this->attachmentService->attach(
            assetId: $asset->id,
            ownerType: MediaOwnerType::DocumentIngestion,
            ownerId: $ingestionId,
            role: MediaRole::SourceDocument,
            sort: 0,
            tenantId: $tenantId,
        );

        try {
            /** @var DocumentIngestion $ingestion */
            $ingestion = DocumentIngestion::create([
                'id' => $ingestionId,
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'kind' => $kind,
                'status' => IngestionStatus::Uploaded,
                'media_asset_id' => $asset->id,
                'checksum' => $checksum,
                'created_by' => $userId,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateChecksumViolation($exception)) {
                throw $exception;
            }

            $this->cleanupRaceMedia($tenantId, $ingestionId, $asset->id);

            throw ValidationException::withMessages([
                'file' => ['A non-rejected document ingestion already exists for this file.'],
            ]);
        }

        ExtractDocumentJob::dispatch($tenantId, $companyId, $ingestion->id);

        return $ingestion;
    }

    private function isDuplicateChecksumViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        return (($sqlState === '23505' || $exception->getCode() === '23505')
            && str_contains($message, 'ux_document_ingestions_company_checksum'))
            || (($sqlState === '23000' || $exception->getCode() === '23000')
                && str_contains($message, 'document_ingestions.company_id')
                && str_contains($message, 'document_ingestions.checksum'));
    }

    private function cleanupRaceMedia(string $tenantId, string $ingestionId, string $assetId): void
    {
        MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', MediaOwnerType::DocumentIngestion)
            ->where('owner_id', $ingestionId)
            ->delete();

        MediaAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $assetId)
            ->delete();
    }

    public function reject(DocumentIngestion $ingestion, string $userId): DocumentIngestion
    {
        unset($userId);

        if ($ingestion->status !== IngestionStatus::NeedsReview) {
            throw new \DomainException('Only ingestions awaiting review can be rejected.');
        }

        $ingestion->transitionTo(IngestionStatus::Rejected);

        return $ingestion->refresh();
    }

    public function reExtract(DocumentIngestion $ingestion): DocumentIngestion
    {
        if (! in_array($ingestion->status, [IngestionStatus::Failed, IngestionStatus::NeedsReview], true)) {
            throw new \DomainException('Only failed or reviewable ingestions can be re-extracted.');
        }

        $updated = DocumentIngestion::query()
            ->whereKey($ingestion->id)
            ->whereIn('status', [IngestionStatus::Failed->value, IngestionStatus::NeedsReview->value])
            ->update([
                'status' => IngestionStatus::Extracting->value,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new \DomainException('Only failed or reviewable ingestions can be re-extracted.');
        }

        $fresh = $ingestion->refresh();
        ExtractDocumentJob::dispatch($fresh->tenant_id, $fresh->company_id, $fresh->id);

        return $fresh;
    }
}
