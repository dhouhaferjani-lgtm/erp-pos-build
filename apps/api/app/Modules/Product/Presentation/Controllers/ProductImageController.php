<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Http\JsonResponse;
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
    public function index(Product $product): JsonResponse
    {
        // Authorization handled by middleware
        $images = $product->images()->ordered()->get();

        return response()->json(['data' => $images]);
    }

    /**
     * Upload a new image for a product.
     */
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
    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        $this->imageService->delete($image);

        return response()->json(null, 204);
    }

    /**
     * Serve an image file inline for browser rendering.
     */
    public function download(Product $product, ProductImage $image): StreamedResponse
    {
        return $this->imageService->serve($image);
    }

    /**
     * Reorder product images.
     */
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
