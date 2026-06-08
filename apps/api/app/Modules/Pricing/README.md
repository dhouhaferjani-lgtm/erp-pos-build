# Pricing Module

## `PricingService::getPrice` — Resolution Order

The method signature is:

```php
public function getPrice(
    string $productId,
    ?string $partnerId = null,
    string $quantity = '1.00',
    string $currency = 'USD',
    ?\DateTimeInterface $date = null,
    ?string $variantId = null,
): array  // returns array{price: string, source: string, price_list_id: string|null}
```

### 6-Step Price Resolution (spec §5.3 / §9.2)

**NEVER REORDER — LOCKED BY SPEC §9.2**

| Step | Condition | Source returned |
|------|-----------|-----------------|
| 1 | `$variantId` is given AND the variant (via `ProductVariantLookup`) has a non-null `priceOverride` | `variant_override` |
| 2 | `$partnerId` is given AND a partner price list has a `price_list_items` row with `variant_id = $variantId` matching `min_quantity` | `partner_price_list` |
| 3 | `$partnerId` is given AND a partner price list has a `price_list_items` row with `variant_id IS NULL` matching `min_quantity` | `partner_price_list` |
| 4 | The default price list (tenant-scoped, currency match) has a `price_list_items` row with `variant_id = $variantId` matching `min_quantity` | `default_price_list` |
| 5 | The default price list has a `price_list_items` row with `variant_id IS NULL` matching `min_quantity` | `default_price_list` |
| 6 | Fallback to `products.sale_price` | `base_price` |

### Within-List Preference

Inside each price list (steps 2–5), `getPriceFromList` tries the **variant-specific row first**
(`variant_id = $variantId`), then falls back to the **variant-agnostic row** (`variant_id IS NULL`)
at the same `min_quantity` tier. The higher-priority list is tried completely before moving to the
next list in the partner priority order.

### Backward Compatibility

Callers that do **not** pass `$variantId` (or pass `null`) behave exactly as before Task 17:

- Step 1 is skipped entirely.
- All price-list lookups search only variant-agnostic rows (`variant_id IS NULL`).
- The `source` values `partner_price_list`, `default_price_list`, and `base_price` are unchanged.

### T11 Note

Per spec §9.2, T11-impl-B's `PricingStrategyResolver` must pass `$variantId` as the trailing
named argument to `getPrice` when it wraps this service. That cross-track wiring is T11's
responsibility, not T2's.
