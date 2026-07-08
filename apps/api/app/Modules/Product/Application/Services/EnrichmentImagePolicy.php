<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Media\Application\Services\MediaAttachmentService;
use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether an enriched image should be attached as PRIMARY or GALLERY.
 *
 * PRIMARY is assigned only if the product has no existing blocking PRIMARY
 * attachment AND the current enrichment run has not already assigned one
 * (a run may enrich several images for the same product; only the first
 * becomes PRIMARY, the rest fall back to GALLERY).
 *
 * A PRIMARY attachment blocks unless its underlying asset has FAILED.
 * {@see MediaUploadService::upload()}
 * dispatches rendition generation asynchronously (DB::afterCommit), so a
 * just-persisted image sits in UPLOADED/PROCESSING for a while before
 * reaching READY. Treating only READY as blocking would let a second
 * `persist()` call during that window see no blocking PRIMARY, assign the
 * new image PRIMARY, and have {@see MediaAttachmentService::attach()}
 * unconditionally demote the still-processing PRIMARY to GALLERY — silently
 * clobbering it. Only FAILED assets are excluded from blocking, since a
 * failed upload is not a legitimate image.
 */
final class EnrichmentImagePolicy
{
    public function roleFor(string $productId, string $tenantId, bool $runAssignedPrimary): MediaRole
    {
        if ($runAssignedPrimary) {
            return MediaRole::Gallery;
        }

        $hasBlockingPrimary = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $productId)
            ->where('role', MediaRole::Primary)
            ->whereHas('mediaAsset', static function (Builder $query): void {
                /** @var Builder<MediaAsset> $query */
                $query->whereIn('status', [
                    MediaStatus::Uploaded,
                    MediaStatus::Processing,
                    MediaStatus::Ready,
                ]);
            })
            ->exists();

        return $hasBlockingPrimary ? MediaRole::Gallery : MediaRole::Primary;
    }
}
