<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\Services\MediaUrlResolver;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Product\Domain\Product;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Public storefront façade over the media_assets / media_attachments model.
 *
 * Exposes the same /api/v1/public/products/{product}/images[...] routes that
 * PublicProductImageController did, preserving the exact response shape, but
 * now backed by media_attachments (PRODUCT owner, image-type, READY assets)
 * instead of product_images.
 *
 * {image} in every URL is a media_attachments.id (UUID).
 *
 * Access gate: the product's is_active_for_ecommerce flag.
 * Products with is_active_for_ecommerce = false → 404 (same as the old controller).
 *
 * No authentication required — these routes are rate-limited only.
 * Cross-tenant by design: the public storefront serves product images from any
 * tenant whose product is flagged for e-commerce.
 */
final class PublicProductMediaController extends Controller
{
    public function __construct(
        private readonly MediaUrlResolver $urlResolver,
    ) {}

    /**
     * Get all public READY image attachments for an e-commerce-active product.
     */
    #[CrossTenantRoute(reason: 'Public e-commerce catalog: serves product images from any tenant whose product has is_active_for_ecommerce=true. Cross-tenant by design — public storefront integration; the is_active_for_ecommerce flag is the access gate (returns 404 otherwise). No auth required.')]
    public function index(string $product): JsonResponse
    {
        $productModel = $this->resolveActiveProduct($product);

        $attachments = $this->loadAttachments($productModel->id, $productModel->tenant_id);

        return response()->json(['data' => $this->formatList($attachments)]);
    }

    /**
     * Get a single public READY image attachment for an e-commerce-active product.
     */
    #[CrossTenantRoute(reason: 'Public e-commerce catalog: serves a single product image from any tenant whose product has is_active_for_ecommerce=true; validates attachment owner_id === product.id to prevent cross-product image leak. No auth required.')]
    public function show(string $product, string $image): JsonResponse
    {
        $productModel = $this->resolveActiveProduct($product);

        $attachment = $this->resolveAttachment($productModel, $image);

        return response()->json(['data' => $this->formatOne($attachment)]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve a product by UUID and abort 404 if it doesn't exist or is not
     * active for e-commerce (the public access gate).
     */
    private function resolveActiveProduct(string $productId): Product
    {
        if (! Str::isUuid($productId)) {
            abort(404);
        }

        $product = Product::query()->find($productId);

        if ($product === null || ! $product->is_active_for_ecommerce) {
            abort(404);
        }

        return $product;
    }

    /**
     * Resolve a media_attachments row scoped to the resolved product.
     * Returns 404 on any mismatch (foreign id, cross-product id, etc.).
     */
    private function resolveAttachment(Product $product, string $attachmentId): MediaAttachment
    {
        if (! Str::isUuid($attachmentId)) {
            abort(404);
        }

        $attachment = MediaAttachment::query()
            ->where('id', $attachmentId)
            ->where('tenant_id', $product->tenant_id)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $product->id)
            ->with([
                'mediaAsset' => static function ($relation) use ($product): void {
                    $relation->where('tenant_id', $product->tenant_id);
                },
            ])
            ->first();

        if ($attachment === null) {
            abort(404);
        }

        return $attachment;
    }

    /**
     * Load all READY image attachments (with their assets) ordered by sort_order.
     *
     * @return Collection<int, MediaAttachment>
     */
    private function loadAttachments(string $productId, string $tenantId): Collection
    {
        return MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $productId)
            ->whereHas('mediaAsset', static function (Builder $q) use ($tenantId): void {
                /** @var Builder<MediaAsset> $q */
                $q->where('tenant_id', $tenantId)
                    ->where('status', MediaStatus::Ready);
            })
            ->with([
                'mediaAsset' => static function ($relation) use ($tenantId): void {
                    $relation->where('tenant_id', $tenantId);
                },
            ])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Map a single MediaAttachment to the public response shape.
     *
     * Preserves the exact shape of PublicProductImageController:
     * { id, url, is_primary, sort_order } plus the extended fields
     * (role, alt, caption) already present in the authed façade.
     *
     * @return array<string, mixed>
     */
    private function formatOne(MediaAttachment $attachment): array
    {
        $url = $this->urlResolver->forAttachment($attachment, null);

        return [
            'id' => $attachment->id,
            'url' => $url,
            'is_primary' => $attachment->role === MediaRole::Primary,
            'sort_order' => $attachment->sort_order,
            'role' => $attachment->role->value,
            'alt' => $attachment->alt,
            'caption' => $attachment->caption,
        ];
    }

    /**
     * Map a collection of MediaAttachment models to the response array.
     *
     * @param  Collection<int, MediaAttachment>  $attachments
     * @return array<int, array<string, mixed>>
     */
    private function formatList(Collection $attachments): array
    {
        return $attachments->map(fn (MediaAttachment $a): array => $this->formatOne($a))->values()->all();
    }
}
