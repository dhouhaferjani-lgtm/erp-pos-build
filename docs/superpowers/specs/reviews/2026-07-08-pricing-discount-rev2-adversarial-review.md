# Adversarial Review (Pass 2) — Pricing Panel + Discount Policy Cascade, Rev 2

**Target:** `docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md` (Rev 2)
**Mandate:** catch what Rev 2 missed or what Rev 2's own changes newly broke. Not re-litigating closed S*-items unless the fix is wrong.
**Method:** every finding verified against code at file:line. Read-only.

Rev 2 did a genuinely thorough job on the 30 prior findings — the phase split, TTC/HT basis, permission names, migration truth table, module-boundary DTO, snapshot-before-seal, and cost redaction are all materially addressed. The blockers below are NEW: they are consequences of the very floor model Rev 2 formalized, and they were invisible in Pass 1 because Pass 1 never checked the floor against the ERP's **existing** margin-floor machinery.

---

## BLOCKER

### N1 — The spec reinvents a floor the ERP already ships; two sources of truth for "minimum margin"
**Spec:** §3.1 new `companies.margin_floor_buffer_percent decimal(5,2) default 10.00`; §4.3 `profit floor = WAC_net × (1 + buffer/100)`; §4.2/§7 new `DiscountCapResolver` "mirroring `MarginResolver`."
**Code:** The ERP **already** has this exact floor.
- `MarginService::calculateMargin()` = `((sell − cost) / cost) × 100` — markup on cost (`apps/api/app/Modules/Product/Application/Services/MarginService.php:271-276`).
- `priceFromMargin()` = `cost × (1 + margin/100)` (`MarginService.php:146-155`).
- `getMarginLevel()` returns `LEVEL_ORANGE` "Below minimum margin" when actual markup < `minimum_margin` (`MarginService.php:315-324`) and `LEVEL_RED` "Below cost" when `sell < cost` (`MarginService.php:301-311`).
- `canSellAtPrice()` gates ORANGE behind `pricing.sell_below_minimum_margin` and RED behind `allow_below_cost_sales` + `pricing.sell_below_cost` (`MarginService.php:356-391`).
- `minimum_margin` cascades product→category→company via `MarginResolver` (`MarginResolver.php:187-208`), backed by `companies.default_minimum_margin decimal(5,2) default 10.00` (`migrations/tenant/2025_12_02_064506_add_inventory_costing_settings_to_companies_table.php:16`).

The spec's `floor = WAC × (1 + buffer/100)` with `buffer default 10.00` is **mathematically identical** to the existing `minimum_margin` floor (`WAC × (1 + minimum_margin/100)`), with the **same default value (10.00)** and the **same product→category→company cascade need**. The spec never once mentions `minimum_margin`, `default_minimum_margin`, `minimum_margin_override`, `MarginResolver`'s minimum field, `LEVEL_ORANGE`, or `pricing.sell_below_minimum_margin`.
**Failure scenario:** Ship both and a product page shows the new `margin_floor_buffer` floor while `MarginService` enforces `minimum_margin` — two independently-editable 10% knobs that silently disagree. The client's "never lose margin" ask is *already modeled*; building a parallel system guarantees a demo where the displayed floor and the enforced floor diverge.
**Fix:** Adopt `minimum_margin` (resolved by `MarginResolver`) as the floor basis and delete `margin_floor_buffer_percent`; extend `DiscountPolicyService` to *consume* `MarginResolver`/`MarginService` (via a Product-module contract) rather than mirror it. If a distinct buffer is truly wanted, the spec must explicitly reconcile the two 10% defaults and state which wins.

### N2 — Migration maps a *below-cost* flag onto a *below-cost+buffer* floor → silent threshold tightening + permission collision
**Spec:** §5 truth table maps `allow_below_cost_sales=false/null → discount_floor_mode=Block`, `true → WarnRequiresPermission`; §4.4.1 Documents enforce Block by 422.
**Code:** `allow_below_cost_sales` gates **only** `LEVEL_RED` = `sell < cost` (`MarginService.php:301-311, 356-364`). The band `[cost, cost×(1+minimum_margin)]` is `LEVEL_ORANGE`, gated by a *different* permission `pricing.sell_below_minimum_margin` (`MarginService.php:375-384`).
**Failure scenario:** After migration, `discount_floor_mode=Block` blocks below `WAC×1.10` (§4.3), but the source flag only ever governed below-`WAC`. So a B2B document at +5% markup (above cost, positive margin) — previously permitted, or only ORANGE-gated by `pricing.sell_below_minimum_margin` — now hard-422s. Two permissions (`pricing.sell_below_minimum_margin` and the new `pricing.override_discount_floor`) now govern the identical band with no defined precedence. Rev 2's truth table reasons only about preserving below-*cost* semantics and never notices the threshold moved up by the buffer or that it double-gates the existing minimum-margin band.
**Fix:** Resolve N1 first (single floor). Then the migration must map to the correct threshold and state the permission precedence between the existing min-margin gate and the new override. If the intended floor is truly below-*cost* (not below-cost+buffer), set `buffer=0` on migrated tenants and document it.

### N3 — Phase-1 Document enforcement is *blocking*, but the owner locked Phase-1 web as advisory-only
**Spec:** §0 Decision 1 records "Phase 1 (web) is advisory **for POS**"; but §1 and §4.4.1 make "Document-side enforcement (online B2B, **authoritative/blocking**)" with a 422 in Phase 1.
**Owner lock (task context):** "Phase-1 web is ADVISORY only; the hard guarantee is Phase-2."
**Code:** Documents do **not** call `MarginService`/`canSellAtPrice` today (grep across `apps/api/app/Modules/Document/` returns nothing). So §4.4.1 is net-new *blocking* margin enforcement on B2B documents — the first time a below-floor B2B line is hard-rejected.
**Failure scenario:** If the owner's "advisory only" lock is literal, Phase 1 must not hard-block anything on the web; a blocking 422 on B2B invoices ships enforcement the owner deferred to Phase 2 and can break a live B2B quote during the demo (a normal low-margin negotiated line → 422). The spec has internally reinterpreted the lock as "advisory *for POS*, blocking for Documents" without an explicit owner sign-off recorded for that narrowing.
**Fix:** Get explicit owner confirmation. Either downgrade Phase-1 Document enforcement to advisory/warn (honoring the literal lock) or record a new owner decision authorizing Phase-1 B2B blocking. Do not leave the spec asserting a lock it then contradicts.

---

## MAJOR

### N4 — Scale-3 floor precision is fiction: the cost/price source columns are `decimal(N,2)`
**Spec:** §0 "decimals are currency-driven (TND=3, EUR=2)"; §4.3 floor computed "at resolver scale +1, rounded once."
**Code:** `products.cost_price decimal(12,2)`, `products.last_purchase_cost decimal(12,2)` (`migrations/tenant/2025_12_02_064541_add_cost_and_margin_fields_to_products_table.php:14,17`); `products.sale_price decimal(15,2)` (`2025_11_30_052910_create_products_table.php:23`). All 2dp.
**Failure scenario:** For a TND tenant (scale 3, the parapharmacy demo currency), the WAC cost basis is already truncated to 2dp *at rest*, so a floor "computed at scale 3+1" is falsely precise — its 3rd decimal is meaningless and the net-floor comparison can only be honest to 2dp. Rule 19 says money at rest is `decimal(N,3)` floor; these columns predate it and violate it, and the spec's entire scale-correct-floor claim consumes them directly.
**Fix:** State the real precision envelope: floor accuracy is bounded by the 2dp cost columns until they are widened. Either widen `cost_price`/`last_purchase_cost`/`sale_price` to `(N,3)` (a separate migration + realignment-log entry) or drop the scale-3 floor language and compute at 2dp for these tenants. Do not claim a precision the inputs cannot deliver.

### N5 — Variant floor uses product-grain WAC while the tested price is the variant override → variant-cost blind spot in the guarantee
**Spec:** §4.1/§6 "`variantId` in context; product-grain WAC is the cost basis; variant `price_override` is the tested price."
**Code:** Variant `cost_override` is advisory-only and inventory WAC is product-grain (`apps/api/app/Modules/Catalog/Application/DTOs/ProductVariantData.php:14-17,37-38`); variant `price_override` is resolved ahead of base price (`Pricing/Domain/Services/PricingService.php:30-39,55-67`).
**Failure scenario:** A variant whose real cost differs from the product WAC (e.g. a larger pack size) sold at its own lower `price_override` is measured against the product-grain floor. A sale genuinely below *that variant's* cost passes the guard — the exact "lose on a sale" case the feature exists to stop, now for the variant grain. Rev 2 documented this as product-grain, but that documentation is a scoping note, not a resolution — the guarantee simply doesn't hold at the variant grain.
**Fix:** Acceptable for v1 only if the spec states explicitly, in the guarantee section, that "never lose" is product-grain and variant-cost divergence is a known, accepted gap. Otherwise fold `cost_override` into the floor basis when present.

### N6 — Redacted POS DTO (§4.6) omits the tax basis the device needs to do the net comparison it mandates (§4.1/§4.4.2)
**Spec:** §4.1/§4.4.2 device must "convert gross to net via the product's tax config" then compare to `floor_price_net`; §4.6 replaces `Product::toArray()` with a DTO shipping "only `floor_price` + `effective_max_discount_percent` as signed constraints."
**Code:** POS sync today ships the whole product incl. `tax_rate`/`default_tax_configuration_id` via `Product::toArray()` (`SyncController.php:82`; product has `tax_rate`, `default_tax_configuration_id` — `2025_11_30_052910_create_products_table.php:25`, `2026_03_22_100000_add_default_tax_configuration_id_to_products.php:14`).
**Failure scenario:** If §4.6's redaction is taken literally ("ships only floor_price + effective_max_discount_percent"), the DTO drops the per-line tax basis, and the device cannot perform the gross→net conversion §4.1 requires — the net floor comparison is impossible offline, or worse, the device silently compares a gross effective price to a net floor (the S6-01 bug Rev 2 claims to have closed, reintroduced at the DTO layer). Multi-rate carts with transaction-level discount allocation make this concrete.
**Fix:** §4.6 must explicitly retain the tax basis (rate + config id) in the POS catalog DTO — redaction removes *cost* fields only, not the tax fields the floor comparison depends on.

### N7 — Verdict `maxDiscountPercent = min(user, terminal, product→category→company)` folds float-based POS terminal/user caps into the new string-safe verdict
**Spec:** §4.1 `DiscountPolicyVerdict.maxDiscountPercent = "min(user, terminal, product→category→company)"` as a string; §4.7 promises new code is string/bc-only and does not inherit the float discount contract.
**Code:** The user/terminal caps originate in float-land: `DiscountCalculationService::scale()` uses no-arg `getScale()` (`POS/Domain/Services/DiscountCalculationService.php:38-41`) and `DiscountController` returns float effective limits (per Pass-1 S3-01, `DiscountController.php:221-230`).
**Failure scenario:** To compute `min(user, terminal, cap)` the new service must read the terminal/user limits, which are float today. Either the new "string" verdict is populated from floats (contract violated at the seam) or the spec silently requires refactoring `DiscountCalculationService`/`DiscountController` to strings — work not listed in §8 rollout and explicitly deferred in §0 ("existing float debt … separate remediation"). The verdict field mixes two concern-grains (per-product margin cap vs per-operator authority cap) that Rev 2 never separated.
**Fix:** Either compute the product/category/company cap in the new string-safe service and keep the user/terminal min-fold in the existing POS layer (don't cross the string/float boundary inside one verdict field), or add the `DiscountCalculationService`/`DiscountController` string conversion to Phase-1 scope explicitly.

---

## MINOR

### N8 — Field-name drift: `effective_max_discount_percent` (§4.6) vs `max_discount_percent` (§4.4.2) vs `max_discount_percent_at_sale` (§4.4.3)
Three names for the synced cap across the enforcement/seal path. Pick one canonical wire name to avoid an implementer shipping two fields. (Spec §4.4.2 line 145, §4.6 line 154, §4.4.3 line 146.)

### N9 — `country_pricing_regulations` is a per-tenant table keyed by `country_code`, but a tenant is effectively single-country
**Spec:** §3.2 seeds FR + TN rows per tenant. Under db-per-tenant each tenant DB carries all country rows and providers filter "by company country" (§3.2). Harmless but wasteful and invites a bug where a FR tenant's DB still holds active TN rules. Consider seeding only the tenant's own country, or documenting why all-countries-everywhere is intentional.

### N10 — `discount_floor_mode string(24)` enum longest value `WarnRequiresPermission` = 22 chars
Fits, but with zero headroom for a future value. Non-blocking; note for the migration author (§3.1 line 91).

---

## VERDICT

**BLOCK.**

Rev 2 correctly closed the Pass-1 findings, but in formalizing the floor it (a) duplicated a floor the ERP already ships and enforces with permissions (`minimum_margin` / `LEVEL_ORANGE` / `pricing.sell_below_minimum_margin`), (b) wrote a migration that shifts the enforcement threshold and collides two permissions on the same margin band, and (c) asserts a "Phase-1 advisory" lock while making Phase-1 B2B documents hard-blocking. N1–N3 must be reconciled with `MarginService`/`MarginResolver` and re-confirmed with the owner before implementation. N4–N7 need spec edits; N8–N10 are cleanups.

**Counts:** BLOCKER 3 (N1, N2, N3) · MAJOR 4 (N4, N5, N6, N7) · MINOR 3 (N8, N9, N10).

**Top action:** decide whether the "never lose margin" floor IS the existing `minimum_margin` (recommended — reuse it) or a genuinely new buffer; every other floor finding cascades from that one decision.
