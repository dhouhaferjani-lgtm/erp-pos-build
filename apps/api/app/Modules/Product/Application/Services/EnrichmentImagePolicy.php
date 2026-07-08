<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether an enriched image should be attached as PRIMARY or GALLERY.
 *
 * PRIMARY is assigned only if the product has no existing READY PRIMARY
 * attachment AND the current enrichment run has not already assigned one
 * (a run may enrich several images for the same product; only the first
 * becomes PRIMARY, the rest fall back to GALLERY).
 */
final class EnrichmentImagePolicy
{
    public function roleFor(string $productId, string $tenantId, bool $runAssignedPrimary): MediaRole
    {
        if ($runAssignedPrimary) {
            return MediaRole::Gallery;
        }

        $hasReadyPrimary = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $productId)
            ->where('role', MediaRole::Primary)
            ->whereHas('mediaAsset', static function (Builder $query): void {
                /** @var Builder<MediaAsset> $query */
                $query->where('status', MediaStatus::Ready);
            })
            ->exists();

        return $hasReadyPrimary ? MediaRole::Gallery : MediaRole::Primary;
    }
}
