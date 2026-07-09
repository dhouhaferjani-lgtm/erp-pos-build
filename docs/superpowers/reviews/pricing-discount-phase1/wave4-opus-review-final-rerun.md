NO BLOCKER/MAJOR FINDINGS.

All enumerated gate risks verified clean:

- **Advisory default** — `shouldReject()` returns `false` for `DiscountFloorMode::Advisory` unconditionally; warning still emitted when `requiresPermission !== null`. Covered by `test_advisory_mode_warns_but_does_not_reject_below_floor_invoice` (cashier → 201 + warning meta).
- **Block / WarnRequiresPermission permission behavior** — both non-Advisory modes gate on `! $hasPermission` (reject non-holder, warn holder). Reuses existing `pricing.sell_below_minimum_margin`, no new permission. All four combinations tested (cashier rejected, manager allowed+warned) for both modes.
- **Route-gating** — `POLICY_ROUTES` limited to `invoices.store/update`, `orders.store/update`; `isDiscountPolicyDocumentRoute()` matches on route name. Quote exclusion proven by `test_quote_route_skips_discount_policy_validation` (`assertJsonMissingPath`).
- **Single resolveMany batch** — one `resolveMany($contexts)` call after the loop; `test_document_validator_batches_product_lines` asserts `resolveManyCalls === 1`, 2 contexts.
- **No app() in validator/requests** — validator uses constructor-injected `DiscountPolicyInterface`, `CompanyContext`, `CurrencyScaleResolverInterface`; both FormRequests constructor-inject the validator. (Pre-existing `app(CompanyConfigService::class)` in `HandlesDocuments::isVehicleModuleEnabled` is unchanged context, outside this work.)
- **Warnings meta** — `documentResponse` appends `discount_policy_warnings` only when non-empty; `store`/`update` forward `$request`; `show`/`index` don't (no warnings on read). Backward-compatible meta shape.
- **SalesOrder discounted net persistence** — both the totals loop and line-create loop switched from `bcmul($quantity, $unitPrice)` to `DocumentLine::computeLineTotal(...)` with discount args. `test_sales_order_persists_the_same_discounted_net_price_checked_by_policy` asserts `line_total === '115.000'` (120 − 5).
- **SO→invoice conversion carries line_total once** — `test_sales_order_to_invoice_conversion_carries_discounted_net_line_total_once` asserts invoice `line_total === '115.000'` (not 110, not re-multiplied).
- **No fiscal/POS work** — diff touches only Document Presentation controllers/trait, the two FormRequests, the new validator, and the feature test. No POS, SALE_RECEIPT, hash-chain, or fiscal-projection code.

MINOR (non-gating observations):

- **Block and WarnRequiresPermission are behaviorally identical in this layer** — both reject non-holders / warn holders; the mode distinction (`requiresReason`, `overridable` on the verdict) is not consumed here. Tests encode this as intended, so it's a deliberate collapse, but the two enum values are indistinguishable at the request boundary.
- **`variantId: null` hardcoded** — variant-specific floors would resolve at product granularity only; acceptable for Phase-1 web line shape (no `variant_id` in lines), worth a note for the variant phase.
- **DB-level default for `discount_floor_mode`** is not in this diff (schema landed in an earlier commit); the "Advisory default" guarantee for unconfigured companies rests on that migration, not verifiable here — tests always set the mode explicitly.
