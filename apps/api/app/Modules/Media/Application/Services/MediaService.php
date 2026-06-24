<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Services;

use App\Models\User;
use App\Modules\Media\Domain\Contracts\MediaStorageInterface;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Shared\Contracts\MediaServiceInterface;
use App\Shared\DTOs\Media\MediaAttachmentView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner-agnostic media seam implementation.
 *
 * Wraps {@see MediaUploadService} (file storage) and {@see MediaAttachmentService}
 * (link management) behind the {@see MediaServiceInterface} contract so any owner
 * type (Product, Document, Category, …) can share the same upload/list/download/
 * detach/purge flow without duplicating code.
 *
 * Uses Eloquent queries directly for read operations that require ordering by
 * created_at desc (listForOwner — R-M1); the repository's forOwners() orders
 * by sort_order which is intentionally different.
 *
 * Lives in Catalog Application for now (Phase 1) and may be moved to
 * App\Modules\Media in Phase 4 once the models migrate there.
 */
final class MediaService implements MediaServiceInterface
{
    public function __construct(
        private readonly MediaUploadService $uploadService,
        private readonly MediaAttachmentService $attachmentService,
        private readonly MediaStorageInterface $storage,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Upload flow:
     *  1. Store the file + create the MediaAsset (MediaUploadService::upload).
     *  2. Link the asset to the owner (MediaAttachmentService::attach).
     *  3. Build and return a MediaAttachmentView from the resulting rows.
     *
     * @param  array<int, string>  $allowedMime
     */
    public function attachUpload(
        MediaOwnerType $ownerType,
        string $ownerId,
        string $tenantId,
        UploadedFile $file,
        ?string $userId,
        MediaRole $role,
        ?string $caption,
        array $allowedMime,
        MediaAssetType $assetType,
    ): MediaAttachmentView {
        $asset = $this->uploadService->upload(
            $tenantId,
            $ownerType,
            $ownerId,
            $file,
            $userId,
            $assetType,
            $allowedMime,
        );

        $attachment = $this->attachmentService->attach(
            $asset->id,
            $ownerType,
            $ownerId,
            $role,
            0,
            $tenantId,
            $caption,
        );

        return $this->buildView($asset, $attachment, $userId);
    }

    /**
     * {@inheritDoc}
     *
     * R-M1: returns READY assets ordered newest-first (created_at desc).
     *
     * The repository's forOwners() orders by sort_order ascending — which is the
     * correct order for gallery display but NOT for an audit/history list.  We
     * query MediaAttachment directly here to apply the created_at desc ordering
     * independently of the gallery sort_order.
     *
     * @return array<int, MediaAttachmentView>
     */
    public function listForOwner(MediaOwnerType $ownerType, string $ownerId, string $tenantId): array
    {
        /** @var array<int, MediaAttachment> $rows */
        $rows = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->whereHas('mediaAsset', static function (Builder $q) use ($tenantId): void {
                /** @var Builder<MediaAsset> $q */
                $q->where('tenant_id', $tenantId)
                    ->where('status', MediaStatus::Ready);
            })
            ->with(['mediaAsset' => static function (Relation $relation) use ($tenantId): void {
                /** @var Relation<MediaAsset, MediaAttachment, *> $relation */
                $relation->where('tenant_id', $tenantId);
            }])
            ->orderBy('media_attachments.created_at', 'desc')
            ->get()
            ->all();

        return array_map(
            fn (MediaAttachment $attachment): MediaAttachmentView => $this->buildView(
                // @phpstan-ignore-next-line — mediaAsset is eager-loaded and non-null here
                $attachment->mediaAsset,
                $attachment,
                $attachment->mediaAsset?->uploaded_by,
            ),
            $rows,
        );
    }

    /**
     * {@inheritDoc}
     *
     * - Resolves the attachment scoped to (tenant, owner_type, owner_id, id).
     * - Returns 404 if not found (foreign owner or missing).
     * - EXTERNAL_URL assets → redirect()->away().
     * - Upload assets → StreamedResponse with Content-Disposition + Content-Type (R-H1).
     */
    public function download(
        MediaOwnerType $ownerType,
        string $ownerId,
        string $attachmentId,
        string $tenantId,
    ): StreamedResponse|RedirectResponse {
        /** @var MediaAttachment|null $attachment */
        $attachment = MediaAttachment::query()
            ->where('id', $attachmentId)
            ->where('tenant_id', $tenantId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->with(['mediaAsset' => static function (Relation $relation) use ($tenantId): void {
                /** @var Relation<MediaAsset, MediaAttachment, *> $relation */
                $relation->where('tenant_id', $tenantId);
            }])
            ->first();

        if ($attachment === null || $attachment->mediaAsset === null) {
            abort(404);
        }

        /** @var MediaAsset $asset */
        $asset = $attachment->mediaAsset;

        if ($asset->source === MediaSource::ExternalUrl) {
            return redirect()->away((string) $asset->external_url);
        }

        return $this->storage->download(
            (string) $asset->storage_disk,
            (string) $asset->storage_path,
            (string) ($asset->original_filename ?? basename((string) $asset->storage_path)),
            (string) ($asset->mime_type ?? 'application/octet-stream'),
        );
    }

    /**
     * {@inheritDoc}
     *
     * Removes the attachment link; if the asset has no remaining links it is
     * soft-deleted (MediaAttachmentService::deleteAsset).  If the asset still
     * has other links, the RuntimeException thrown by deleteAsset is caught and
     * silently swallowed — the asset survives.
     */
    public function detach(
        MediaOwnerType $ownerType,
        string $ownerId,
        string $attachmentId,
        string $tenantId,
    ): void {
        /** @var MediaAttachment|null $attachment */
        $attachment = MediaAttachment::query()
            ->where('id', $attachmentId)
            ->where('tenant_id', $tenantId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();

        if ($attachment === null) {
            abort(404);
        }

        $assetId = (string) $attachment->media_asset_id;

        $this->attachmentService->detachLink($attachmentId, $tenantId);

        try {
            $this->attachmentService->deleteAsset($assetId, $tenantId);
        } catch (\RuntimeException) {
            // Asset still has other links — leave it alive.
        }
    }

    /**
     * {@inheritDoc}
     *
     * Iterates every attachment for the owner and calls {@see detach()} on each.
     * Orphaned assets (no remaining links after detach) are soft-deleted
     * inside the detach loop via MediaAttachmentService::deleteAsset.
     */
    public function purgeOwner(MediaOwnerType $ownerType, string $ownerId, string $tenantId): void
    {
        $attachments = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->get();

        foreach ($attachments as $attachment) {
            /** @var MediaAttachment $attachment */
            $this->detach($ownerType, $ownerId, (string) $attachment->id, $tenantId);
        }
    }

    /**
     * Build a MediaAttachmentView from an asset + attachment pair.
     *
     * isImage: MIME starts with 'image/'.
     * isPdf: MIME is exactly 'application/pdf'.
     * uploadedByName: resolved via a nullable User lookup on the uploaded_by column.
     * createdAt: attachment.created_at formatted as ISO-8601.
     */
    private function buildView(MediaAsset $asset, MediaAttachment $attachment, ?string $uploadedById): MediaAttachmentView
    {
        $mimeType = (string) ($asset->mime_type ?? '');
        $uploadedByName = null;

        if ($uploadedById !== null && $uploadedById !== '') {
            /** @var User|null $user */
            $user = User::find($uploadedById);
            $uploadedByName = $user?->name;
        }

        return new MediaAttachmentView(
            id: (string) $attachment->id,
            originalFilename: (string) ($asset->original_filename ?? ''),
            mimeType: $mimeType,
            fileSize: (int) ($asset->file_size ?? 0),
            caption: $attachment->caption,
            uploadedById: $uploadedById,
            uploadedByName: $uploadedByName,
            createdAt: $attachment->created_at?->toIso8601String(),
            isImage: str_starts_with($mimeType, 'image/'),
            isPdf: $mimeType === 'application/pdf',
        );
    }
}
