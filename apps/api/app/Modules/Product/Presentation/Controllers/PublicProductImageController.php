<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PublicProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageService $imageService
    ) {}

    /**
     * Get all public images for an e-commerce product.
     */
    #[CrossTenantRoute(reason: 'Public e-commerce catalog: serves product images from any tenant whose product has is_active_for_ecommerce=true. Cross-tenant by design — public storefront integration; the is_active_for_ecommerce flag is the access gate (returns 404 otherwise). No auth required.')]
    public function index(Product $product): JsonResponse
    {
        // Only allow access if product is active for e-commerce
        if (! $product->is_active_for_ecommerce) {
            abort(404);
        }

        $images = $product->images()->ordered()->get()->map(function (ProductImage $image) {
            return [
                'id' => $image->id,
                'url' => $this->imageService->getPublicUrl($image),
                'is_primary' => $image->is_primary,
                'width' => $image->width,
                'height' => $image->height,
                'sort_order' => $image->sort_order,
            ];
        });

        return response()->json(['data' => $images]);
    }

    /**
     * Get a single public image for an e-commerce product.
     */
    #[CrossTenantRoute(reason: 'Public e-commerce catalog: serves a single product image from any tenant whose product has is_active_for_ecommerce=true; validates $image->product_id === $product->id alignment to prevent cross-product image leak. No auth required.')]
    public function show(Product $product, ProductImage $image): JsonResponse
    {
        if (! $product->is_active_for_ecommerce) {
            abort(404);
        }

        if ($image->product_id !== $product->id) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'id' => $image->id,
                'url' => $this->imageService->getPublicUrl($image),
                'is_primary' => $image->is_primary,
                'width' => $image->width,
                'height' => $image->height,
                'sort_order' => $image->sort_order,
            ],
        ]);
    }
}
