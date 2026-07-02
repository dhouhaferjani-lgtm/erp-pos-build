<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\DTOs\ProductVariantSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class LineEntryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ProductVariantLookup $variantLookup,
    ) {}

    public function resolveCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ]);

        $code = trim((string) $validated['code']);
        $company = $this->companyContext->requireCompany();

        $productBarcode = $this->findProductByCode($code, 'barcode', $company->tenant_id, $company->id);
        if ($productBarcode !== null) {
            return $this->success([
                'kind' => 'product',
                'matched_code_type' => 'product_barcode',
                'product' => ProductData::fromModel($productBarcode, ProductMediaData::makeEmpty()),
            ], $request);
        }

        $productSku = $this->findProductByCode($code, 'sku', $company->tenant_id, $company->id);
        if ($productSku !== null) {
            return $this->success([
                'kind' => 'product',
                'matched_code_type' => 'product_sku',
                'product' => ProductData::fromModel($productSku, ProductMediaData::makeEmpty()),
            ], $request);
        }

        $variantBarcode = $this->variantLookup->findByBarcode($code, $company->id);
        if ($variantBarcode !== null) {
            return $this->variantResponse($variantBarcode, 'variant_barcode', $request);
        }

        $variantSku = $this->variantLookup->findBySku($code, $company->id);
        if ($variantSku !== null) {
            return $this->variantResponse($variantSku, 'variant_sku', $request);
        }

        return $this->success([
            'kind' => 'not_found',
            'code' => $code,
        ], $request);
    }

    private function findProductByCode(string $code, string $column, string $tenantId, string $companyId): ?Product
    {
        /** @var Product|null $product */
        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where($column, $code)
            ->with(['unitOfMeasure', 'brand'])
            ->withCount(['activeVariants'])
            ->first();

        return $product;
    }

    private function variantResponse(ProductVariantSummary $variant, string $matchedCodeType, Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $variant->productId)
            ->where('is_active', true)
            ->with(['unitOfMeasure', 'brand'])
            ->withCount(['activeVariants'])
            ->first();

        if ($product === null) {
            return $this->success([
                'kind' => 'not_found',
                'code' => $variant->barcode ?? $variant->sku,
            ], $request);
        }

        return $this->success([
            'kind' => 'variant',
            'matched_code_type' => $matchedCodeType,
            'product' => ProductData::fromModel($product, ProductMediaData::makeEmpty()),
            'variant' => [
                'id' => $variant->id,
                'product_id' => $variant->productId,
                'sku' => $variant->sku,
                'variant_code' => $variant->variantCode,
                'barcode' => $variant->barcode,
                'name_suffix' => $variant->nameSuffix,
                'is_default' => $variant->isDefault,
                'price_override' => $variant->priceOverride,
                'cost_override' => $variant->costOverride,
                'image_url' => $variant->imageUrl,
            ],
        ], $request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
