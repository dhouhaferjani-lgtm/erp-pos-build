<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Application\DTOs\MediaAttachmentData;
use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Catalog\Application\Services\MediaUrlResolver;
use App\Modules\Catalog\Domain\Contracts\MediaAttachmentRepositoryInterface;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Shared\Contracts\CatalogMediaQueryInterface;

/**
 * Reads all READY media attachments for one or more products in a single batched
 * query and projects them onto ProductMediaData DTOs.
 *
 * Design notes:
 *   - The repository issues one batched query for all owner ids (no N+1).
 *   - URL resolution is performed by MediaUrlResolver (injected), keeping DTOs pure.
 *   - Primary image selection picks the first attachment whose role is PRIMARY.
 */
final class CatalogMediaQuery implements CatalogMediaQueryInterface
{
    public function __construct(
        private readonly MediaAttachmentRepositoryInterface $attachments,
        private readonly MediaUrlResolver $urls,
    ) {}

    /**
     * @param  array<string>  $productIds
     * @return array<string, ProductMediaData>
     */
    public function forProducts(array $productIds, string $tenantId): array
    {
        if ($productIds === []) {
            return [];
        }

        $byOwner = $this->attachments->forOwners(MediaOwnerType::Product, $productIds, $tenantId);

        $out = [];

        foreach ($productIds as $id) {
            $rows = $byOwner[$id] ?? [];

            $dtos = array_map(
                // Use forPosSync() here — CatalogMediaQuery feeds the POS sync payload
                // whose image_url shape is frozen (Tauri cache key).  The SPA uses
                // forAttachment() via ProductMediaController/PublicProductMediaController.
                fn ($a) => MediaAttachmentData::fromModel($a, $this->urls->forPosSync($a, 'sm')),
                $rows,
            );

            $primaryUrl = null;
            foreach ($dtos as $d) {
                if ($d->role === MediaRole::Primary->value) {
                    $primaryUrl = $d->url;
                    break;
                }
            }

            $out[$id] = new ProductMediaData($primaryUrl, $dtos);
        }

        return $out;
    }

    public function forProduct(string $productId, string $tenantId): ProductMediaData
    {
        return $this->forProducts([$productId], $tenantId)[$productId] ?? ProductMediaData::makeEmpty();
    }
}
