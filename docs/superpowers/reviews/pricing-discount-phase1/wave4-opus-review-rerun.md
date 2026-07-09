## Wave 4 Re-Review — Product Pricing Panel + Discount Policy Cascade (Phase 1)

I verified the diff against every gate item. Findings below.

### Gate compliance (verified)
- **Advisory default / Block opt-in / permission-gated warn** — `shouldReject()` returns `false` for `Advisory`, else rejects when `!hasPermission`. Tests cover all three modes. ✓
- **Only existing permissions** — validator never hardcodes a permission; it defers to `verdict->requiresPermission` and calls `$user->can(...)`. The check surface is the service's (Phase 1.0.3), not this diff. Test asserts `pricing.sell_below_minimum_margin`. ✓
- **Route-gated** — `POLICY_ROUTES` = `invoices.store/update`, `orders.store/update`; `test_quote_route_skips_discount_policy_validation` confirms quotes skip. ✓
- **Single batch** — one `resolveMany($contexts)` call; `test_document_validator_batches_product_lines` asserts `resolveManyCalls === 1`, 2 contexts. ✓
- **Constructor injection, no `app()`** — validator injects `DiscountPolicyInterface`, `CompanyContext`, `CurrencyScaleResolverInterface`. No `app()` in new production code. (`app(...)` in the test is setup, and the pre-existing `app(CompanyConfigService::class)` in `HandlesDocuments` is unchanged/out of scope.) ✓
- **Warnings in meta** — set on `$request->attributes` in the validator; read back in `documentResponse()`; same FormRequest instance flows into the controller closure. ✓
- **No fiscal/POS scope** — touches only Document presentation layer + validation. ✓

---

### MAJOR

**M1 — SalesOrder line/subtotal semantics silently changed gross → net-of-discount.**
`SalesOrderController.php` store/update previously computed `$lineSubtotal = bcmul($quantity, $unitPrice, ...)` and `$lineTotal = bcmul($quantity, $unitPrice, ...)` (discount ignored in totals). Both are now `DocumentLine::computeLineTotal(..., discount_percent, discount_amount, ...)`. This satisfies the gate ("SO and invoices persist line totals consistently with the discounted price checked by policy"), but it is a behavioral change to persisted financial totals in an existing flow, bundled into a "policy" wave. Any downstream consumer that reads SO `line_total`/`subtotal` expecting **gross** — SO→invoice conversion, confirm/stock-reservation valuation, fiscal recompute-on-confirm, reporting — will now see net values and could double-apply the discount or mis-total. `test_sales_order_persists_the_same_discounted_net_price_checked_by_policy` only proves the persisted value; it does not exercise conversion/confirm. Verify no consumer relied on gross before merge.

---

### MINOR

**m1 — `Block` and `WarnRequiresPermission` are behaviorally identical.**
`DiscountPolicyDocumentValidator.php:151-158` — both non-Advisory modes reject iff `!hasPermission`. `test_block_mode_allows_user_with_existing_minimum_margin_permission` confirms Block lets permission-holders through, i.e. Block is not a hard stop. The three-value enum collapses to two behaviors. If Block is meant to be a hard block regardless of permission, this is wrong; if intended (per the reconciled "permission-based" decision), fine — but confirm, since the redundant enum value invites future misuse.

**m2 — Warning meta leaks floor/margin data to low-privilege users.**
`DiscountPolicyDocumentValidator.php:107-118` puts `floor_price_net` and `max_discount_percent` into `meta.discount_policy_warnings` for any user who can create a document (e.g. a cashier in Advisory mode). Those values are cost/margin-derived. Consider gating the sensitive fields behind a pricing-view permission, or emitting only the reason for users lacking `pricing.*`.

**m3 — `numericString()` guard is effectively dead code.**
Because `validate()` early-returns when `$validator->errors()->isNotEmpty()`, all values reaching `numericString()` (quantity/unit_price/discount_*) have already passed their `regex` rules, so the `InvalidArgumentException` throw at `DiscountPolicyDocumentValidator.php:170-172` is unreachable in normal flow. Harmless, but it can never surface a real problem — either drop it or document it as pure defense-in-depth.

---

**No BLOCKER findings.** The one item worth gating on is **M1**: the SO gross→net total change is required by the gate but ships as a silent semantics change to an existing financial flow — confirm downstream (SO→invoice conversion + confirm valuation) before merge.
