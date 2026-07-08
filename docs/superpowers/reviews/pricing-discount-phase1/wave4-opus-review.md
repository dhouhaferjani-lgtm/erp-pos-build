I invoked the review directly as requested (adversarial findings-only pass over the Wave 4 diff). Verdict against the gate criteria first, then findings.

## Gate criteria — all satisfied
- **Advisory default / Block opt-in:** `shouldReject()` returns `false` for `Advisory` regardless of permission; only non-Advisory + missing permission rejects (`DiscountPolicyDocumentValidator.php:139-146`). Tests confirm (`test_advisory_mode_warns_but_does_not_reject_below_floor_invoice`, `test_block_mode_rejects_user_without_sell_below_minimum_margin`).
- **Route-gated to invoice/order store+update; quote skips:** `POLICY_ROUTES` (`:22-27`) + `isDiscountPolicyDocumentRoute()` (`:131-135`). Double-guarded: quotes are also never handed `$request` in their controller. `test_quote_route_skips_discount_policy_validation` covers it.
- **Single batch resolve:** one `resolveMany($contexts)` call (`:97`); `test_document_validator_batches_product_lines` asserts `resolveManyCalls === 1`, 2 contexts.
- **Existing permissions only:** validator does not hardcode a permission; it uses `$verdict->requiresPermission` from the policy and gates via `$user->can(...)` (`:104`). Tests assert `pricing.sell_below_minimum_margin`.
- **Constructor injection, no `app()`:** validator ctor injects all three deps (`:29-33`); no `app()` in new code. (Pre-existing `app(CompanyConfigService::class)` in `HandlesDocuments::isVehicleModuleEnabled` is untouched.)
- **Warnings in meta:** `$request->attributes->set('discount_policy_warnings', …)` (`:126`) surfaced in `documentResponse` meta (`HandlesDocuments.php` new block). Same FormRequest instance reaches the controller, so the attribute round-trips correctly.
- **No fiscal/POS scope:** confirmed — no fiscal recompute, hash-chain, or POS paths touched.

**NO BLOCKER FINDINGS.**

## MAJOR

1. **Order floor-check evaluates a different price than the order actually charges.** The validator computes `effectiveUnitPrice = computeLineTotal(qty, unitPrice, discount_percent, discount_amount) / qty` — i.e. **net of line discount** (`DiscountPolicyDocumentValidator.php:74-84`). Invoices persist `line_total` the same way, so they are consistent. But `SalesOrderController::store`/`update` persist `line_total = bcmul($quantity, $unitPrice, …)` — **ignoring `discount_percent`/`discount_amount`** (unchanged context lines in `SalesOrderController.php`). So for an order line carrying a discount, the policy floor-check runs against a *lower* net price than the order will actually bill (gross). This can produce false Block/Warn on orders that in fact charge above the floor. Either apply discounts consistently in the order controller, or have the validator mirror order line-total semantics for order routes. (Invoices are fine.)

## MINOR

2. **Dead branch in warning-inclusion condition.** `if (! $this->shouldReject(...) || $hasPermission)` (`:120`): when `shouldReject` is true for a non-Advisory mode, `hasPermission` is necessarily `false` (since `shouldReject = !hasPermission`), so `|| $hasPermission` can never contribute. The condition reduces to `! shouldReject`. Harmless but misleading for maintainers — simplify.

3. **`variant_id` flows into `DiscountPolicyContext` unvalidated.** `:87` reads `$line['variant_id']` but neither FormRequest adds a rule for it (no `ScopedExists`, no `uuid`). `product_id` is properly scoped, and `companyId` scopes `resolveMany`, so cross-tenant leakage is prevented — but a same-company mismatched/foreign `variant_id` reaches policy resolution unchecked. Add a scoped rule or cross-check `variant_id` belongs to `product_id`.

4. **Any prior validation error suppresses all policy warnings.** `$validator->errors()->isNotEmpty()` early-return (`:37`) means an unrelated field error hides below-floor warnings. This correctly prevents `numericString()` from throwing on unvalidated input (rules run before `after`), and the document won't be created anyway, so there's no bypass — but the UX is "you get one error at a time." Acceptable; noting the tradeoff.

5. **`$lineIndexesByKey[$lineKey]` assumes the policy echoes exact keys.** `:99` will `Undefined array key` if a real `resolveMany` impl returns a renamed/extra key. The spy honors the contract, but a defensive `?? continue`/guard would harden against a misbehaving implementation.

6. **Warning ordering follows `resolveMany` return order, not line order.** `:118` appends in verdict-iteration order; clients wanting line-ordered warnings can't rely on array position. Cosmetic — each entry carries `line`/`field`.

No regressions in the trait signature change: `?Request $request = null` defaults keep all existing `documentResponse`/`documentCreatedResponse` callers (incl. `show()` and other document controllers) working with no warnings emitted.
