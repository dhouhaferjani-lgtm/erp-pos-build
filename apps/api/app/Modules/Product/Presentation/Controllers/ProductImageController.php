<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageService $imageService
    ) {}

    /**
     * List all images for a product.
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Product Route Model Binding does NOT auto-scope by tenant (Product model has no global scope filtering by tenant_id). The product_images table has tenant_id, but this controller relies on Route Model Binding without explicit tenant validation. Tracked for future api.product cluster fix; the gap is permission-gated by the route\'s middleware (`can:products.view` or similar).')]
    public function index(Product $product): JsonResponse
    {
        // Authorization handled by middleware
        $images = $product->images()->ordered()->get();

        return response()->json(['data' => $images]);
    }

    /**
     * Upload a new image for a product.
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Product Route Model Binding without tenant global scope (mirrors index shape). ProductImageService::upload uses $product->tenant_id when stamping the storage path and image row, so the uploaded image inherits tenant_id from the bound Product — but a cross-tenant Product binding would still attach the image to that tenant\'s product. Tracked for future api.product cluster fix.')]
    public function store(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,webp,gif|max:5120', // 5MB
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $image = $this->imageService->upload(
            $product,
            $request->file('image'),
            $request->input('sort_order')
        );

        return response()->json(['data' => $image], 201);
    }

    /**
     * Update an image (set primary, change sort order).
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Product + ProductImage Route Model Bindings without tenant global scope (mirrors index shape). The controller does NOT validate $image->product_id === $product->id. Tracked for future api.product cluster fix.')]
    public function update(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $request->validate([
            'sort_order' => 'nullable|integer|min:0',
            'is_primary' => 'nullable|boolean',
        ]);

        if ($request->has('is_primary') && $request->boolean('is_primary')) {
            $this->imageService->setPrimary($image);
        }

        if ($request->has('sort_order')) {
            $image->update(['sort_order' => $request->input('sort_order')]);
        }

        return response()->json(['data' => $image->fresh()]);
    }

    /**
     * Delete an image.
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Product + ProductImage Route Model Bindings without tenant global scope (mirrors update shape); delete operates directly on $image without product-id alignment check. Tracked for future api.product cluster fix.')]
    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        $this->imageService->delete($image);

        return response()->json(null, 204);
    }

    /**
     * Serve an image file inline for browser rendering.
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Product + ProductImage Route Model Bindings without tenant global scope (mirrors update shape). The serve() flow streams image bytes and could disclose cross-tenant images if a different-tenant Product+ProductImage pair is provided. Tracked for future api.product cluster fix.')]
    public function download(Request $request, Product $product, ProductImage $image): StreamedResponse|RedirectResponse
    {
        $variant = $request->query('variant');
        $validVariants = ['sm', 'md'];
        $resolvedVariant = is_string($variant) && in_array($variant, $validVariants, true) ? $variant : null;

        return $this->imageService->serve($image, $resolvedVariant);
    }

    /**
     * Reorder product images.
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Product Route Model Binding without tenant global scope (mirrors index shape). The image_ids validation only checks UUIDs exist in product_images globally; not that they belong to $product. Tracked for future api.product cluster fix.')]
    public function reorder(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'image_ids' => 'required|array',
            'image_ids.*' => 'uuid|exists:product_images,id',
        ]);

        $this->imageService->reorder($product, $request->input('image_ids'));

        return response()->json(['data' => $product->images()->ordered()->get()]);
    }
}
