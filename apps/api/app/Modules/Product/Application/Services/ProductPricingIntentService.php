<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;

/**
 * Enforcement seam: decides pricing_mode and whether to persist or null-out
 * a product's target_margin_override / minimum_margin_override based on
 * the validated payload.
 *
 * Mutates $product in-place but does NOT save.
 */
final class ProductPricingIntentService
{
    public function __construct(private readonly MarginResolver $resolver) {}

    /**
     * @param  array<string,mixed>  $validated
     */
    public function applyIntent(Product $product, array $validated): void
    {
        // ── Step 1: Determine pricing_mode ─────────────────────────────────
        // Explicit intent wins; else sale_price-only implies Manual.
        if (array_key_exists('pricing_mode', $validated) && $validated['pricing_mode'] !== null && $validated['pricing_mode'] !== '') {
            $product->pricing_mode = PricingMode::from((string) $validated['pricing_mode']);
        } elseif (array_key_exists('sale_price', $validated) && $validated['sale_price'] !== null && $validated['sale_price'] !== '') {
            $product->pricing_mode = PricingMode::Manual;
        }

        // ── Step 2: Set sale_price when present in payload ─────────────────
        if (array_key_exists('sale_price', $validated)) {
            $product->sale_price = $validated['sale_price'];
        }

        // ── Step 3: Handle target_margin_override ──────────────────────────
        // Correctness fix: exclude the product's OWN current override before
        // computing the inherited baseline — otherwise an UPDATE would compare
        // the new value against the *old* override, not the category/company chain.
        if (array_key_exists('target_margin_override', $validated)) {
            $value = $validated['target_margin_override'];
            if ($value === null || $value === '') {
                $product->target_margin_override = null;
            } else {
                // Null the override first so resolve() sees the inherited baseline
                // (category chain → company default), not the product's own value.
                $product->target_margin_override = null;
                $inherited = $this->resolver->resolve($product)->target_margin;
                // Store null when equal to inherited (keep inheriting); else persist.
                $numValue = is_numeric((string) $value) ? (string) $value : '0';
                $numInherited = is_numeric($inherited) ? $inherited : '0';
                $product->target_margin_override = bccomp($numValue, $numInherited, 2) === 0 ? null : (string) $value; // precision-ok: percent margins compared at fixed 2dp, not currency-scaled
            }
        }

        // ── Step 4: Handle minimum_margin_override ─────────────────────────
        if (array_key_exists('minimum_margin_override', $validated)) {
            $min = $validated['minimum_margin_override'];
            $product->minimum_margin_override = ($min === null || $min === '') ? null : (string) $min;
        }
    }
}
