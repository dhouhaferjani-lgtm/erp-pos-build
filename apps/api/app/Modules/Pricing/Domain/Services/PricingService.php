<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Pricing\Domain\PriceList;
use App\Modules\Pricing\Domain\PriceListItem;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\ProductVariantLookup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PricingService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly CompanyContext $companyContext,
        private readonly ProductVariantLookup $variantLookup,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Get price for a product based on partner, quantity, date, and optional variant.
     *
     * Resolution order (spec §5.3 / §9.2 — LOCKED — DO NOT REORDER):
     *   1. Variant price_override      (when $variantId given and override is set)
     *   2. Partner price list, variant-specific
     *   3. Partner price list, variant-agnostic
     *   4. Default price list, variant-specific
     *   5. Default price list, variant-agnostic
     *   6. Product sale_price fallback
     *
     * Backward compat: callers that do not pass $variantId (null) behave exactly
     * as before — step 1 is skipped, price-list lookups use variant-agnostic rows.
     *
     * @return array{price: string, source: string, price_list_id: string|null}
     */
    public function getPrice(
        string $productId,
        ?string $partnerId = null,
        string $quantity = '1.00',
        string $currency = 'USD',
        ?\DateTimeInterface $date = null,
        ?string $variantId = null,
    ): array {
        $date = $date ?? now();

        // Step 1 — Variant price_override (spec §5.3 step 1).
        // Only attempted when a variantId is provided AND the variant exists
        // with a non-null priceOverride. Uses bcmath: no float comparisons.
        if ($variantId !== null) {
            $variant = $this->variantLookup->findById($variantId);
            if ($variant !== null && $variant->priceOverride !== null) {
                return [
                    'price' => $variant->priceOverride,
                    'source' => 'variant_override',
                    'price_list_id' => null,
                ];
            }
        }

        // Steps 2–3 — Partner price list (variant-specific, then variant-agnostic).
        if ($partnerId !== null) {
            $partnerPrice = $this->getPartnerPrice($partnerId, $productId, $quantity, $currency, $date, $variantId);
            if ($partnerPrice !== null) {
                return [
                    'price' => $partnerPrice['price'],
                    'source' => 'partner_price_list',
                    'price_list_id' => $partnerPrice['price_list_id'],
                ];
            }
        }

        // Steps 4–5 — Default price list (variant-specific, then variant-agnostic).
        $defaultPrice = $this->getDefaultPriceListPrice($productId, $quantity, $currency, $date, $variantId);
        if ($defaultPrice !== null) {
            return [
                'price' => $defaultPrice['price'],
                'source' => 'default_price_list',
                'price_list_id' => $defaultPrice['price_list_id'],
            ];
        }

        // Step 6 — Product sale_price fallback.
        // api.pricing.001: tenant-scope Product fallback. The controller-tier
        // validator now also rejects cross-tenant product_id with ScopedExists,
        // but service-direct callers (queue jobs, cross-module orchestrators)
        // could still hit this path; defense-in-depth.
        $company = $this->companyContext->requireCompany();
        $product = Product::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($productId);

        return [
            'price' => $product->sale_price ?? '0.00',
            'source' => 'base_price',
            'price_list_id' => null,
        ];
    }

    /**
     * Get partner-specific price.
     *
     * Iterates partner price lists in priority order. For each list, tries a
     * variant-specific row first (when $variantId is given), then falls back
     * to a variant-agnostic row — per spec §5.3 / §9.2.
     *
     * @return array{price: string, price_list_id: string}|null
     */
    private function getPartnerPrice(
        string $partnerId,
        string $productId,
        string $quantity,
        string $currency,
        \DateTimeInterface $date,
        ?string $variantId = null,
    ): ?array {
        // api.pricing round-2 (Opus Finding 3): partner_price_lists has no
        // tenant_id column; the transitive scope on partner_id is enforced
        // by the controller-tier validator. The join to price_lists must
        // also filter by tenant_id + company_id so a same-tenant
        // partner_price_lists row referencing a foreign-tenant price_list_id
        // (legacy admin error / migration race) cannot leak a foreign
        // price_list_id in the response.
        $company = $this->companyContext->requireCompany();

        // Get all active price lists for partner, ordered by priority
        $partnerPriceLists = DB::table('partner_price_lists')
            ->join('price_lists', 'partner_price_lists.price_list_id', '=', 'price_lists.id')
            ->where('partner_price_lists.partner_id', $partnerId)
            ->where('partner_price_lists.is_active', true)
            ->where('price_lists.is_active', true)
            ->where('price_lists.currency', $currency)
            ->where('price_lists.tenant_id', $company->tenant_id)
            ->where('price_lists.company_id', $company->id)
            ->where(function ($query) use ($date): void {
                $query->whereNull('partner_price_lists.valid_from')
                    ->orWhere('partner_price_lists.valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('partner_price_lists.valid_until')
                    ->orWhere('partner_price_lists.valid_until', '>=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('price_lists.valid_from')
                    ->orWhere('price_lists.valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('price_lists.valid_until')
                    ->orWhere('price_lists.valid_until', '>=', $date);
            })
            ->orderBy('partner_price_lists.priority', 'desc')
            ->select('price_lists.id')
            ->pluck('id');

        // Try each price list in priority order.
        // Within each list: variant-specific row first, then variant-agnostic.
        foreach ($partnerPriceLists as $priceListId) {
            $price = $this->getPriceFromList((string) $priceListId, $productId, $quantity, $variantId);
            if ($price !== null) {
                return [
                    'price' => $price,
                    'price_list_id' => $priceListId,
                ];
            }
        }

        return null;
    }

    /**
     * Get price from default price list.
     *
     * Prefers variant-specific row over variant-agnostic row — per spec §5.3 / §9.2.
     *
     * @return array{price: string, price_list_id: string}|null
     */
    private function getDefaultPriceListPrice(
        string $productId,
        string $quantity,
        string $currency,
        \DateTimeInterface $date,
        ?string $variantId = null,
    ): ?array {
        // api.pricing round-2 (Opus Finding 2): pre-fix this picked an
        // arbitrary same-currency default price list ACROSS ALL TENANTS
        // because PriceList has no global tenant scope. Even though
        // getPriceFromList downstream couldn't return a value (foreign
        // price_list_id would not match same-tenant product_id rows),
        // the response wrapping leaks `price_list_id` from a foreign
        // tenant via timing/probe attacks. Scope at the source.
        $company = $this->companyContext->requireCompany();

        $priceList = PriceList::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('currency', $currency)
            ->where('is_default', true)
            ->where('is_active', true)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $date);
            })
            ->first();

        if ($priceList === null) {
            return null;
        }

        /** @var string $priceListId */
        $priceListId = $priceList->id;
        $price = $this->getPriceFromList($priceListId, $productId, $quantity, $variantId);

        if ($price === null) {
            return null;
        }

        return [
            'price' => $price,
            'price_list_id' => $priceListId,
        ];
    }

    /**
     * Get price from a specific price list with quantity breaks.
     *
     * When $variantId is provided, tries the variant-specific row first
     * (price_list_items.variant_id = $variantId). Falls back to the
     * variant-agnostic row (variant_id IS NULL) at the same min_quantity tier.
     * When $variantId is null, only the variant-agnostic row is searched
     * (backward-compatible behaviour for existing callers).
     */
    private function getPriceFromList(
        string $priceListId,
        string $productId,
        string $quantity,
        ?string $variantId = null,
    ): ?string {
        // Step A: variant-specific lookup (only when variantId given).
        if ($variantId !== null) {
            $variantItem = PriceListItem::where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->where('min_quantity', '<=', $quantity)
                ->where(function ($query) use ($quantity): void {
                    $query->whereNull('max_quantity')
                        ->orWhere('max_quantity', '>=', $quantity);
                })
                ->orderBy('min_quantity', 'desc')
                ->first();

            if ($variantItem !== null) {
                return $variantItem->price;
            }
        }

        // Step B: variant-agnostic fallback (always tried; is the only path when variantId is null).
        $agnosticItem = PriceListItem::where('price_list_id', $priceListId)
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($quantity): void {
                $query->whereNull('max_quantity')
                    ->orWhere('max_quantity', '>=', $quantity);
            })
            ->orderBy('min_quantity', 'desc')
            ->first();

        return $agnosticItem?->price;
    }

    /**
     * Calculate line subtotal with discounts.
     *
     * @return array{subtotal: string, discount_amount: string, total: string}
     */
    public function calculateLineTotal(
        string $unitPrice,
        string $quantity,
        ?string $discountPercent = null,
        ?string $discountAmount = null
    ): array {
        /** @var numeric-string $unitPrice */
        /** @var numeric-string $quantity */
        $subtotal = bcmul($unitPrice, $quantity, $this->scale());

        /** @var numeric-string $totalDiscount */
        $totalDiscount = '0';

        // Apply percentage discount
        if ($discountPercent !== null) {
            /** @var numeric-string $discountPct */
            $discountPct = $discountPercent;
            if (bccomp($discountPct, '0', $this->scale()) > 0) {
                $percentDiscount = bcmul($subtotal, bcdiv($discountPct, '100', 4), $this->scale());
                $totalDiscount = bcadd($totalDiscount, $percentDiscount, $this->scale());
            }
        }

        // Apply fixed amount discount
        if ($discountAmount !== null) {
            /** @var numeric-string $discountAmt */
            $discountAmt = $discountAmount;
            if (bccomp($discountAmt, '0', $this->scale()) > 0) {
                $totalDiscount = bcadd($totalDiscount, $discountAmt, $this->scale());
            }
        }

        // Discount cannot exceed subtotal
        if (bccomp($totalDiscount, $subtotal, $this->scale()) > 0) {
            $totalDiscount = $subtotal;
        }

        $total = bcsub($subtotal, $totalDiscount, $this->scale());

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'total' => $total,
        ];
    }

    /**
     * Apply document-level discount.
     *
     * @return array{discount_amount: string, total: string}
     */
    public function applyDocumentDiscount(
        string $subtotal,
        ?string $discountPercent = null,
        ?string $discountAmount = null
    ): array {
        /** @var numeric-string $subtotal */
        /** @var numeric-string $totalDiscount */
        $totalDiscount = '0';

        if ($discountPercent !== null) {
            /** @var numeric-string $discountPct */
            $discountPct = $discountPercent;
            if (bccomp($discountPct, '0', $this->scale()) > 0) {
                $percentDiscount = bcmul($subtotal, bcdiv($discountPct, '100', 4), $this->scale());
                $totalDiscount = bcadd($totalDiscount, $percentDiscount, $this->scale());
            }
        }

        if ($discountAmount !== null) {
            /** @var numeric-string $discountAmt */
            $discountAmt = $discountAmount;
            if (bccomp($discountAmt, '0', $this->scale()) > 0) {
                $totalDiscount = bcadd($totalDiscount, $discountAmt, $this->scale());
            }
        }

        if (bccomp($totalDiscount, $subtotal, $this->scale()) > 0) {
            $totalDiscount = $subtotal;
        }

        $total = bcsub($subtotal, $totalDiscount, $this->scale());

        return [
            'discount_amount' => $totalDiscount,
            'total' => $total,
        ];
    }

    /**
     * Get all quantity breaks for a product in a price list.
     *
     * @return Collection<int, PriceListItem>
     */
    public function getQuantityBreaks(string $priceListId, string $productId): Collection
    {
        return PriceListItem::where('price_list_id', $priceListId)
            ->where('product_id', $productId)
            ->orderBy('min_quantity')
            ->get();
    }

    /**
     * Bulk price lookup for multiple products.
     *
     * @param  array<int, string>  $productIds
     * @return array<string, array{price: string, source: string, price_list_id: string|null}>
     */
    public function getBulkPrices(
        array $productIds,
        ?string $partnerId = null,
        string $currency = 'USD',
        ?\DateTimeInterface $date = null
    ): array {
        $prices = [];

        foreach ($productIds as $productId) {
            $prices[$productId] = $this->getPrice($productId, $partnerId, '1.00', $currency, $date);
        }

        return $prices;
    }
}
