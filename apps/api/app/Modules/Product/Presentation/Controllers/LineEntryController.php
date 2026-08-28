<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\CurrencyScale;
use App\Shared\DTOs\ProductVariantSummary;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class LineEntryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ProductVariantLookup $variantLookup,
        private readonly MarginService $marginService,
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
                'product' => $this->productPayload($productBarcode, $request),
            ], $request);
        }

        $productSku = $this->findProductByCode($code, 'sku', $company->tenant_id, $company->id);
        if ($productSku !== null) {
            return $this->success([
                'kind' => 'product',
                'matched_code_type' => 'product_sku',
                'product' => $this->productPayload($productSku, $request),
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

    public function bulkPricingContext(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'partner_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ], [
            'lines.*.unit_price.regex' => 'Unit price must have at most 3 decimal places.',
        ]);

        /** @var list<array{product_id: string, variant_id?: string|null, unit_price: string|int|float}> $lines */
        $lines = $validated['lines'];
        $productIds = array_values(array_unique(array_map(
            static fn (array $line): string => $line['product_id'],
            $lines,
        )));

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereIn('id', $productIds)
            ->with('company')
            ->get()
            ->keyBy('id');

        $items = [];
        $currency = $company->currency ?? 'TND';
        $moneyScale = CurrencyScale::for($currency);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        foreach ($lines as $line) {
            /** @var Product|null $product */
            $product = $products->get($line['product_id']);
            if ($product === null) {
                continue;
            }

            $variantId = $line['variant_id'] ?? null;
            $key = $this->pricingContextKey($product->id, $variantId);
            $unitPrice = (string) $line['unit_price'];
            $margins = $this->marginService->getEffectiveMargins($product);
            $marginLevel = $this->marginService->getMarginLevel($product, $unitPrice);
            $policy = $this->marginService->canSellAtPrice($product, $unitPrice, $user);

            $items[$key] = [
                'currency' => $currency,
                'cost_wac' => CurrencyScale::bcformat($product->cost_price ?? '0', 6),
                'last_purchase_cost' => $product->last_purchase_cost === null
                    ? null
                    : CurrencyScale::bcformat($product->last_purchase_cost, 6),
                'last_purchase_at' => null,
                'last_sale_to_partner' => $this->lastSaleToPartner(
                    $validated['partner_id'] ?? null,
                    $product->id,
                    $variantId,
                ),
                'suggested_price' => CurrencyScale::bcformat((string) $this->marginService->getSuggestedPrice($product), $moneyScale),
                'target_margin_pct' => $margins['target_margin'],
                'minimum_margin_pct' => $margins['minimum_margin'],
                'policy' => [
                    'level' => $marginLevel['level'],
                    'allowed' => $policy['allowed'],
                    'requires_permission' => $policy['requires_permission'] ?? null,
                ],
            ];
        }

        return $this->success(['items' => $items], $request);
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

        $redactCost = $this->mustRedactCost($request);

        return $this->success([
            'kind' => 'variant',
            'matched_code_type' => $matchedCodeType,
            'product' => $this->productPayload($product, $request),
            'variant' => [
                'id' => $variant->id,
                'product_id' => $variant->productId,
                'sku' => $variant->sku,
                'variant_code' => $variant->variantCode,
                'barcode' => $variant->barcode,
                'name_suffix' => $variant->nameSuffix,
                'is_default' => $variant->isDefault,
                'price_override' => $variant->priceOverride,
                // Cost data: same confidentiality rule as the product DTO's
                // cost fields. `price_override` is sale-side and stays.
                'cost_override' => $redactCost ? null : $variant->costOverride,
                'image_url' => $variant->imageUrl,
            ],
        ], $request);
    }

    /**
     * Product DTO for the scan/lookup response, with cost/margin fields redacted
     * for callers lacking `pricing.view_cost_prices`.
     *
     * This endpoint and `products.index` are both gated by `can:products.view`
     * and both feed the same document line editor — typed search resolves through
     * ProductController (which redacts, see ProductController::index), barcode scan
     * resolves through here. Without this the two entry paths disagreed and a scan
     * handed cost data to a non-holder. W2-6 made the leak visible on screen: the
     * scanned `purchase_price` pre-fills the purchase-order price cell and is
     * announced by the line's price-provenance hint.
     */
    private function productPayload(Product $product, Request $request): ProductData
    {
        $dto = ProductData::fromModel($product, ProductMediaData::makeEmpty());

        return $this->mustRedactCost($request) ? $dto->withoutCostFields() : $dto;
    }

    private function mustRedactCost(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return ! $user->can('pricing.view_cost_prices');
    }

    private function pricingContextKey(string $productId, ?string $variantId): string
    {
        if ($variantId === null || $variantId === '') {
            return $productId;
        }

        return "{$productId}:{$variantId}";
    }

    /**
     * R-2 / LEDGER D-T9-1 — `document_no` is NULLABLE: this query is not filtered by
     * status, so the most recent line for the partner may well sit on a DRAFT, and a
     * draft carries no number. The client renders the draft placeholder for it; the
     * PRICE, which is what this hint is for, is just as usable either way.
     *
     * @return array{unit_price: string, at: string|null, document_no: string|null}|null
     */
    private function lastSaleToPartner(?string $partnerId, string $productId, ?string $variantId): ?array
    {
        if ($partnerId === null || $partnerId === '') {
            return null;
        }

        $line = DocumentLine::query()
            ->where('product_id', $productId)
            ->when($variantId !== null && $variantId !== '', static function ($query) use ($variantId): void {
                $query->where('variant_id', $variantId);
            })
            ->with('document')
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->where('documents.partner_id', $partnerId)
            ->orderByDesc('documents.document_date')
            ->orderByDesc('document_lines.created_at')
            ->select('document_lines.*')
            ->first();

        if ($line === null) {
            return null;
        }

        return [
            'unit_price' => CurrencyScale::bcformat($line->unit_price, 3),
            'at' => $line->document->document_date->toDateString(),
            'document_no' => $line->document->document_number,
        ];
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
