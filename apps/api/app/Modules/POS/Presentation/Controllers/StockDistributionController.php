<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\Contracts\ProductVariantLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Cross-location stock distribution for one product (+ optional variant).
 *
 * GET /api/v1/pos/products/{product}/stock-distribution
 *
 * Double-gated: requires the `pos.view_cross_location_stock` permission AND
 * the company master switch `companies.allow_cross_location_stock_view`.
 * Variant-aware: mirrors StockTransferService's variant rule — a product with
 * active variants requires a `variant_id`; a product without active variants
 * rejects a supplied `variant_id`.
 */
final class StockDistributionController extends Controller
{
    public function __construct(
        private readonly LocationStockReader $stockReader,
        private readonly ProductVariantLookup $variantLookup,
        private readonly CompanyContext $companyContext,
    ) {}

    public function show(Request $request, string $product): JsonResponse
    {
        Gate::authorize('pos.view_cross_location_stock');

        $company = $this->companyContext->requireCompany();
        if (! (bool) $company->allow_cross_location_stock_view) {
            abort(403, 'Cross-location stock view is disabled for this company.');
        }

        if (! Str::isUuid($product)) {
            abort(404);
        }

        $validated = $request->validate([
            'variant_id' => ['sometimes', 'uuid'],
            'current_location_id' => ['sometimes', 'uuid'],
        ]);
        $variantId = $validated['variant_id'] ?? null;
        $currentLocationId = $validated['current_location_id'] ?? '';

        $hasActiveVariants = $this->variantLookup->listForProduct($product, true)->isNotEmpty();
        if ($hasActiveVariants && $variantId === null) {
            abort(422, 'This product has variants; variant_id is required.');
        }
        if (! $hasActiveVariants && $variantId !== null) {
            abort(422, 'This product has no variants; variant_id must be omitted.');
        }

        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        $dto = $this->stockReader->stockDistributionForProduct(
            tenantId: $tenantId,
            companyId: $companyId,
            productId: $product,
            variantId: $variantId,
            currentLocationId: $currentLocationId,
        );

        $variantLabel = null;
        if ($variantId !== null) {
            $summary = $this->variantLookup->findById($variantId);
            $variantLabel = $summary?->nameSuffix;
        }

        return response()->json([
            'data' => [
                'product_id' => $dto->productId,
                'variant_id' => $dto->variantId,
                'variant_label' => $variantLabel,
                'locations' => array_map(static fn ($r) => [
                    'location_id' => $r->locationId,
                    'location_name' => $r->locationName,
                    'location_type' => $r->locationType,
                    'is_current' => $r->isCurrent,
                    'on_hand' => $r->onHand,
                    'incoming_transfer' => $r->incomingTransfer,
                ], $dto->locations),
                'totals' => [
                    'on_hand' => $dto->totalOnHand,
                    'incoming_transfer' => $dto->totalIncomingTransfer,
                ],
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }
}
