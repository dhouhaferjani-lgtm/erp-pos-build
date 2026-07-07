<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Services;

use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Application\Jobs\ExtractDocumentJob;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Media\Application\Services\MediaAttachmentService;
use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
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
        $checksum = hash('sha256', (string) file_get_contents($file->getRealPath()));

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

        ExtractDocumentJob::dispatch($tenantId, $companyId, $ingestion->id);

        return $ingestion;
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

        if ($ingestion->status === IngestionStatus::Failed) {
            $ingestion->transitionTo(IngestionStatus::Extracting);
        } else {
            $ingestion->status = IngestionStatus::Extracting;
            $ingestion->save();
        }

        $fresh = $ingestion->refresh();
        ExtractDocumentJob::dispatch($fresh->tenant_id, $fresh->company_id, $fresh->id);

        return $fresh;
    }
}
