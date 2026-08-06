<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Application\DTOs\MediaAttachmentData;
use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Media\Application\Services\MediaUrlResolver;
use App\Modules\Media\Domain\Contracts\MediaAttachmentRepositoryInterface;
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
                // media[]: forPosSync() — the /products payload is also the POS sync
                // payload and this per-attachment url shape is frozen (Tauri cache
                // contract).  Do NOT change it without a coordinated POS release.
                fn ($a) => MediaAttachmentData::fromModel($a, $this->urls->forPosSync($a, 'sm')),
                $rows,
            );

            // primary_image_url: forAttachment() — this is what the SPA renders in an
            // <img src="…"> on the product hero (ProductHero / ProductEditHero).  An
            // <img> tag cannot send the Sanctum Bearer header and the SPA is
            // same-origin through the web proxy (no cookie for the API host), so the
            // auth:sanctum-gated products.images.download URL always 401s → broken
            // image icon (BUG-005 / RCA A1).  forAttachment() mints a RELATIVE
            // HMAC-signed media.serve URL that the browser can load unauthenticated.
            // 'md' is the hero rendition; the variant is passed to the resolver so it
            // is covered by the HMAC (appending ?variant= afterwards would invalidate
            // the signature).
            $primaryUrl = null;
            foreach ($rows as $a) {
                if ($a->role === MediaRole::Primary) {
                    $primaryUrl = $this->urls->forAttachment($a, 'md');
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
