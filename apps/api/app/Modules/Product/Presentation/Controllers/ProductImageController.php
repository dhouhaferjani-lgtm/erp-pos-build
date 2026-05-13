<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product image management.
 *
 * dev-remediation/B.M2.1 — closed the six known tenant-isolation gaps
 * on Route Model Binding by switching to scoped queries through
 * {@see CompanyContext}. Every method now resolves `$product` via
 * `tenant_id` + `company_id` predicates, and any method that also
 * receives an image id additionally enforces
 * `image.product_id === product.id` so a mixed-id request (foreign
 * image attached to a same-tenant product) returns 404 with the same
 * shape as a genuinely missing id.
 */
class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageService $imageService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all images for a product.
     */
    public function index(string $product): JsonResponse
    {
        $productModel = $this->resolveProduct($product);

        $images = $productModel->images()->ordered()->get();

        return response()->json(['data' => $images]);
    }

    /**
     * Upload a new image for a product.
     */
    public function store(Request $request, string $product): JsonResponse
    {
        $productModel = $this->resolveProduct($product);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,webp,gif|max:5120', // 5MB
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $image = $this->imageService->upload(
            $productModel,
            $request->file('image'),
            $request->input('sort_order')
        );

        return response()->json(['data' => $image], 201);
    }

    /**
     * Update an image (set primary, change sort order).
     */
    public function update(Request $request, string $product, string $image): JsonResponse
    {
        $productModel = $this->resolveProduct($product);
        $imageModel = $this->resolveImage($productModel, $image);

        $request->validate([
            'sort_order' => 'nullable|integer|min:0',
            'is_primary' => 'nullable|boolean',
        ]);

        if ($request->has('is_primary') && $request->boolean('is_primary')) {
            $this->imageService->setPrimary($imageModel);
        }

        if ($request->has('sort_order')) {
            $imageModel->update(['sort_order' => $request->input('sort_order')]);
        }

        return response()->json(['data' => $imageModel->fresh()]);
    }

    /**
     * Delete an image.
     */
    public function destroy(string $product, string $image): JsonResponse
    {
        $productModel = $this->resolveProduct($product);
        $imageModel = $this->resolveImage($productModel, $image);

        $this->imageService->delete($imageModel);

        return response()->json(null, 204);
    }

    /**
     * Serve an image file inline for browser rendering.
     */
    public function download(Request $request, string $product, string $image): StreamedResponse|RedirectResponse
    {
        $productModel = $this->resolveProduct($product);
        $imageModel = $this->resolveImage($productModel, $image);

        $variant = $request->query('variant');
        $validVariants = ['sm', 'md'];
        $resolvedVariant = is_string($variant) && in_array($variant, $validVariants, true) ? $variant : null;

        return $this->imageService->serve($imageModel, $resolvedVariant);
    }

    /**
     * Reorder product images.
     */
    public function reorder(Request $request, string $product): JsonResponse
    {
        $productModel = $this->resolveProduct($product);

        $request->validate([
            'image_ids' => 'required|array',
            'image_ids.*' => [
                'uuid',
                // Validate against the bound product's image set, not the
                // global product_images table. Anything else (foreign
                // image, image from another same-tenant product) is a
                // validation failure → 422.
                'exists:product_images,id,product_id,'.$productModel->id,
            ],
        ]);

        $this->imageService->reorder($productModel, $request->input('image_ids'));

        return response()->json(['data' => $productModel->images()->ordered()->get()]);
    }

    /**
     * Resolve the bound product through the caller's tenant + company
     * scope. Returns the model or aborts 404 with the standard JSON
     * shape so foreign ids cannot be distinguished from missing ids.
     */
    private function resolveProduct(string $productId): Product
    {
        $company = $this->companyContext->requireCompany();

        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            abort(404, 'Product not found');
        }

        return $product;
    }

    /**
     * Resolve a product image scoped to the resolved product. The
     * caller has already proven access to `$product` via
     * {@see resolveProduct()}, so the only additional invariant here
     * is that the image actually belongs to that product.
     */
    private function resolveImage(Product $product, string $imageId): ProductImage
    {
        $image = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('id', $imageId)
            ->first();

        if ($image === null) {
            abort(404, 'Product image not found');
        }

        return $image;
    }
}
