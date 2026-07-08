# Product Pricing Panel + Discount Policy Cascade — Design Spec

**Date:** 2026-07-08
**Status:** **Rev 3** — reconciled against the M0 fresh adversarial review (`docs/superpowers/specs/reviews/2026-07-08-pricing-discount-spec-m0-rev2-review.md`, 4 parallel code-verified reviewers). Owner decisions D5/D6 recorded in the Rev 3 block below. **The "Rev 3 — M0 reconciliation" block OVERRIDES any conflicting earlier section — read it first.** (Rev 2 reconciled the earlier Codex review, `2026-07-08-pricing-discount-spec-adversarial-review.md`, 14 BLOCKER / 16 MAJOR.)
**Origin:** Client demo feedback — the product page must surface WAC, last purchase price, margin, price HT (some clients: TTC), enable price-setting via margin or manual rounding, and enforce a per-product maximum discount with a "never lose on a sale" guarantee.

---

## Rev 3 — M0 reconciliation (2026-07-08, owner-confirmed). **THESE OVERRIDE ANY EARLIER SECTION.**

M0 fresh adversarial review (`specs/reviews/2026-07-08-pricing-discount-spec-m0-rev2-review.md`, 4 parallel code-verified reviewers) supersedes Rev 2 wherever it conflicts. **Owner decisions this round: (D5) reuse the EXISTING minimum-margin system for the floor — do NOT build a parallel buffer; (D6) Phase-1 B2B document enforcement is advisory/Warn by default, hard-Block opt-in per company.**

**R3-1 — Floor = existing minimum-margin cascade (supersedes §3.1 buffer row, §4.3 buffer formula).**
- DROP the new `companies.margin_floor_buffer_percent` column. The "never lose" net floor is the EXISTING minimum-margin: `companies.default_minimum_margin` cascaded product→category→company via `MarginResolver` (`MarginResolver.php:187-209`) — the same threshold `MarginService` already enforces (LEVEL_ORANGE, `MarginService.php:43,316-321`). The Product-module provider surfaces the resolved minimum-margin net floor into `DiscountPolicySubject`; `DiscountPolicyService` does NOT recompute a buffer.
- The existing below-**cost** gate (`allow_below_cost_sales` + `pricing.sell_below_cost`, `MarginService.php:355-371`) is UNCHANGED and remains authoritative for below-cost. The new floor concept = below-**minimum-margin**, layered above it.

**R3-2 — Reuse existing permissions; no new `pricing.override_discount_floor` (supersedes §3.3 new-perm, §4.4).**
- Floor override reuses `pricing.sell_below_minimum_margin` and `pricing.sell_below_cost` — the perms `MarginService` already checks (`:365,376`). VERIFY both are seeded in `PermissionSeeder` and role-mapped; if `pricing.sell_below_minimum_margin` is missing from the catalog/roles, ADD it and grant to `manager`+`admin` with a DENY-path test.
- `pricing.view_cost_prices` gates the cost/margin panel AND `POST /pricing/discount-policy`. It is admin-only today and NOT web-enforced — GRANT it to `manager` (confirm the demo account's role) so the marquee panel is visible; this is a NEW enforcement point (F2).
- Deploy owes `permission:cache-reset` PER TENANT DB (F6, tenant-blind cache key `config/permission.php:192`); the "fails before reseed, passes after" test is a merge blocker.

**R3-3 — Per-product max-discount-% cap is the genuinely-new feature (refines §3.1/§4.1).**
- KEEP `products.max_discount_percent`, `categories.max_discount_percent`, `companies.default_max_discount_percent` (nullable, nearest-wins cascade) — the demo's "per-product max discount."
- Phase-1 web verdict cap = **product→category→company ONLY** (resolves the §4.1 `min(user,terminal,…)` BLOCKER): user/terminal caps live in the POS module and Phase-1 web has no terminal/operator context — that `min()` belongs to the Phase-2 device. `DiscountCapResolver` operates **only** on the `DiscountPolicySubject` DTO (no Product/Category/Company model access, M4); the Product-module provider assembles the category-chain caps into the DTO, mirroring `MarginResolver::resolveMany` (`:51-78`). New Pricing code is DTO-only regardless of the module's pre-existing Product/Company coupling (M5) — do NOT bolt the advisory endpoint onto the Product-coupled `PricingController`.

**R3-4 — Phase-1 Document enforcement is Warn/advisory by default, Block opt-in (D6, supersedes §4.4.1 "authoritative/blocking" and the §5 truth table).**
- Add `companies.discount_floor_mode` enum `{ Advisory, Block, WarnRequiresPermission }` default **`Advisory`** for ALL tenants. Migration does NOT remap `allow_below_cost_sales` and there is NO §5 truth table (B1 dissolved — no new blocking at go-live). Advisory = surface the verdict, do NOT 422 (matches today's advisory `LineEntryController.php:131`). Block / WarnRequiresPermission are per-company opt-in.
- The Document check hooks `CreateDocumentRequest` / `UpdateDocumentRequest` via their EXISTING `withValidator()->after()` closure with the rule injected through the request constructor (NOT `app()`), route-name-gated to `invoices.*`/`orders.*` exactly like `AppliesDiscountToleranceRule::isPaymentDueDocumentRoute()` (M3). It EXTENDS the existing `canSellAtPrice` verdict, not a parallel gate. Resolve ALL document lines in ONE batch pass via the provider's `resolveMany`-style method (N+1).

**R3-5 — Endpoint hardening (supersedes §4.8).**
- `POST /pricing/discount-policy`: register INSIDE the existing Pricing route group (`Pricing/Presentation/routes.php:19`) with its full stack `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` **+ `->middleware('can:pricing.view_cost_prices')`**. The verdict exposes `floorPriceNet` (a cost lower-bound) — never unguarded (F3).
- Do NOT extend `GET /pos/discount-permissions` with any floor/cost field in Phase 1 — it is unguarded + POS-facing and §1 says POS shows nothing new in Phase 1; floor-on-sync is Phase-2 (F4). If touched at all: additive string keys only, never mutate the existing float `maxDiscountPercent`/`userMaxDiscountPercent` (consumers `operatorStore.ts:138,146`, `discountPermissions.ts:79-80`).

**R3-6 — `sale_price` canonical basis = HT (resolves §6 ambiguity).**
- Pin `sale_price` as **HT (net)** — matches the backend auto-pricer (`MarginService::updateSalePrice:235,248`) and the WAC cost basis. `ProductForm` currently treats it as TTC (`:761,773`); reconciling that (store/read HT, derive TTC for display) is IN Phase-1 scope. Panel + traffic-light use the SAME tax resolution as the backend contract — `default_tax_configuration_id`, fallback `tax_rate` — not `tax_rate`-only. Do NOT reuse `MarginService::getMarginLevel` for the no-cost case (it returns LEVEL_GREEN "No cost data", `:293-296`); the panel shows "—"/floor-disabled.

**R3-7 — Corrections & clarifications.**
- `Shared/Contracts` literal path = **`app/Shared/Contracts/`** (siblings `ProductServiceInterface`, `CatalogLookupInterface`) — there is NO `app/Modules/Shared/`. The DTO-via-Product-module-public-service pattern is idiomatic.
- WAC is **net cost incl. non-recoverable VAT** (not pure HT), stored `decimal(19,6)` (6dp); floor intermediates at resolver-scale+1, round once; the min-margin contribution is SKIPPED (not zero) when cost ≤ 0.
- §S3-03 rationale is factually wrong: `PricingService` converts percent at scale 4 (`:305,351`), not currency scale — no corruption. Don't-reuse still holds because (a) those are money-discount-amount helpers and (b) `PricingService::scale()` uses no-arg `getScale()` (`:24-27`) that throws in queue/ingestion. New code: bc/string, percent-at-scale-2, explicit currency to `getScale($currency)`.
- `policyVersion`/`policyAsOf` have no Phase-1 store: derive `policyAsOf = company.updated_at`, `policyVersion = short hash of policy inputs`; full sealed versioning is Phase-2. Cite fixes: `MarginResolver:187-209`, `PermissionSeeder:145-146`.

---

## 0. Owner decisions (2026-07-08) & review reconciliation

**Decisions taken this round:**
1. **Phasing:** Phase 1 (web) is **advisory** for POS; the enforceable "never lose on a sale" guarantee ships in **Phase 2** with POS device enforcement + sealed fiscal snapshot. Phase 1 may NOT claim the guarantee. (resolves S1-01)
2. **Policy scope:** **company-wide** for v1, structured to be branch/terminal-ready later. Branch isolation explicitly deferred. (resolves S8-02/S8-03)
3. **Legal floors:** FR/TN below-cost rules seed as **advisory/warn only** until owner/counsel confirms the legal basis; not hard-blocking in v1. (resolves Q10 / research "unverified-but-primary-sourced")
4. **Variants:** the policy contract takes **`variantId`** now; product-grain WAC is the cost basis. (resolves S6-02)

**Money precision (owner correction):** all backend money math is **bcmath** via `CurrencyScale::bcformatStrict($v, $scaleResolver->getScale($currency))` with a constructor-injected `CurrencyScaleResolverInterface`; decimals are **currency-driven, never hardcoded** (TND=3, EUR=2). Frontend money is `<MoneyInput>` string emission; **POS device arithmetic uses Big.js strings** (never JS float / `parseFloat`). Percent columns are NOT currency-scaled — fixed 2dp.

**POS is offline-first & the sale is device-authored:** `POST /api/v1/pos/receipts` returns **410 Gone** (`apps/api/app/Modules/POS/routes.php:127-151`); receipts are authored on the device and ingested via `POST /api/v1/pos/sync/fiscal-events`; the ingestor never blocks the device (`Fiscal/Application/Services/OutboxIngestor.php:45-51`). Fiscal events are immutable (canonical bytes + hash chain, `Fiscal/Domain/Models/FiscalEvent.php`). These facts drive the enforcement architecture below.

**Review reconciliation table** (finding → resolution):

| Finding | Sev | Resolution |
|---|---|---|
| S1-01 phase split vs guarantee | B | §1 phasing: Phase 1 advisory; guarantee = Phase 2. Decision 1. |
| S1-02 / S2-04 cost leakage via payload & `Product::toArray()` sync | B/Maj | §4.6 redacted POS catalog DTO replaces `toArray()`; floor shipped as a signed constraint; threat-model note. |
| S1-03 / S6-01 TTC vs HT floor basis | Maj/B | §4.1 `DiscountPolicyContext.priceBasis`; compare **net** effective price to **net** floor. |
| S1-04 / S8-01 permission names + cache | Maj/B | §3.3 reuse `pricing.view_cost_prices`; add `pricing.override_discount_floor`; deploy owes `permission:cache-reset`. |
| S2-01 fixed-amount discount bypass | B | §4.4 device computes effective price for **fixed AND percent** before sealing. |
| S2-02 staleness unbounded | B | §4.4 `policy_version`/`policy_as_of`/`floor_valid_until` + fail rule by mode. |
| S2-03 override not floor-specific | Maj | §4.4 new `DISCOUNT_FLOOR_OVERRIDE` scope/event with policy hash + evidence. |
| S3-01 floats in existing discount surfaces | B | §4.7 new code is string/bc only; existing float helpers NOT reused; new string-safe response shape. |
| S3-02 no-arg `getScale()` in reused services | B | §4.3 policy carries explicit currency; `getScale($currency)`; ingestion test clears `CompanyContext`. |
| S3-03 PricingService percent-at-currency-scale | Maj | §4.7 do not reuse `PricingService` discount helpers; percent math at scale 2. |
| S3-04 loose percent validation | Maj | §3.1 every new percent field: `numeric,min:0,max:100`, 2dp regex, enum rules. |
| S4-01 cross-module model access | B | §4.2 `DiscountPolicySubject` DTO from a Product-module public service; Pricing consumes contracts, not models. |
| S4-02 `app()` in copied concern | Maj | §4.5 do NOT copy the trait's `app()` pattern; constructor-injected rule object. |
| S5-01 / S5-02 / S5-03 migration semantics | B/Maj | §5 migration truth table preserving `pricing.sell_below_cost` gate; no silent loosen. |
| S6-02 variant pricing | Maj | §4.1 `variantId` in context. Decision 4. |
| S6-03 refunds/credit notes | Maj | §6 returns replay the **original sale snapshot**; not blocked by current floor. |
| S6-04 promotion stacking below floor | B | §4.4 floor evaluated **after all discount sources stacked & allocated to lines**. |
| S7-01 pre-seal evidence | B | §4.4 policy snapshot + override evidence sealed **before** SALE_RECEIPT is chained. |
| S7-02 replay vs current policy | Maj | §4.4 ingestion audit replays against **sealed snapshot**; "would violate current" is separate advisory. |
| S8-02 operator lookup tenant-only | Maj | §3.3 validate operator↔company/terminal membership server-side (company-grain v1). |
| S8-03 sync company-scoped | Maj | Decision 2: company-wide v1, documented; branch-ready structure. |

**Explicitly deferred / pushed back** (not this spec): fixing the pre-existing float/`getScale`/module-boundary/`app()` debt in *existing* POS/Pricing/Document code beyond what our new code touches (separate remediation — our new code simply must not inherit it); branch/terminal policy isolation; hard-enforcing legal floors.

## 1. Scope & phasing

**Phase 1 — Web (advisory, demo-visible):** pricing-intelligence panel (WAC + last purchase price + 4-mode calculator + HT/TTC toggle + rounding); per-product/category/company max-discount cap fields; `DiscountPolicyService` + `DiscountPolicySubject` DTO boundary; **Document-side enforcement** (online B2B, authoritative/blocking); margin traffic-light + floor **display**. POS shows nothing new and is **not** gated. Phase 1 must not claim "never lose on a sale."

**Phase 2 — POS (the enforceable guarantee):** redacted POS catalog DTO carrying signed `floor_price`/`effective_max_discount_percent` + `policy_version`/`policy_as_of`; device-local enforcement for percentage **and** fixed discounts, evaluated **after** promotion/coupon/loyalty stacking; `DISCOUNT_FLOOR_OVERRIDE` scope + sealed override evidence; policy snapshot sealed into the SALE_RECEIPT; ingestion audit replaying the snapshot. Only Phase 2 delivers the guarantee.

**Phase 3 — TN pharma schedule:** regulated-product flag + 4-tier regressive provider (advisory→enforce on sign-off).

**Phase 4 — Wholesale/retail tiers:** separate spec — wire `PricingService::getPrice()` into POS/Document/Cart, tier UI, partner `discount_percentage` reconciliation. This spec only keeps shapes tier-ready.

**Stream C (wholesale) designed-for, not built:** the dormant `Pricing` module (`price_lists`, `price_list_items`, `partner_price_lists`) already models tiers; kept UI/data-compatible only.

**Out of scope:** POS-side cost visibility for cashiers; partner `discount_percentage` auto-application; promotion-engine changes (but floor is evaluated over promotion output — see §4.4); price-history tab; branch-scoped policy.

## 2. Current-state facts this design builds on

| Fact | Location (verified) |
|---|---|
| WAC on `products.cost_price`, written by WAC service; last purchase on `products.last_purchase_cost`, `cost_updated_at` | `Inventory/.../WeightedAverageCostService.php`; migration `2025_12_02_064541` |
| Margin overrides cascade product → category chain → company → hardcoded fallback | `Product/Application/Services/MarginResolver.php:200-224` |
| `MarginService::canSellAtPrice()` GREEN/YELLOW/ORANGE/RED; below-cost gated by `allow_below_cost_sales` AND `pricing.sell_below_cost` | `Product/Application/Services/MarginService.php:355-371` |
| Product form already has a bidirectional cost/margin/HT/TTC calculator strip | `apps/web/src/features/inventory/ProductForm.tsx:148-207,762-781` |
| Discount caps per terminal + per user; most-restrictive-wins; reason required > 10% | `POS/Domain/Services/DiscountCalculationService.php` |
| NO per-product cap; NO discount↔margin link | verified 2026-07-07 |
| Treasury tolerance boundary covers Document invoices/orders, NOT POS | `Document/.../Concerns/AppliesDiscountToleranceRule.php` |
| `POST /pos/receipts` = 410; sale is device-authored + ingested; ingestor never blocks device | `POS/routes.php:127-151`; `Fiscal/.../OutboxIngestor.php:45-51` |
| Fiscal events immutable (canonical bytes + prev/current hash) | `Fiscal/Domain/Models/FiscalEvent.php:43-45` |
| POS sync maps `Product::toArray()` → ships `cost_price`/`last_purchase_cost` today | `POS/Presentation/Controllers/SyncController.php:81-90`; `Product/Domain/Product.php:50-54,156-164` |
| Seeded pricing perms include `pricing.view_cost_prices`, `pricing.sell_below_cost` (NOT `pricing.view_costs`) | `database/seeders/PermissionSeeder.php:138-147` |
| Existing POS discount modals use `parseFloat`; discount-permissions endpoint returns float limits | `apps/pos/src/components/organisms/LineDiscountModal/LineDiscountModal.tsx:113`; `POS/.../DiscountController.php:221-230` |
| Variant `price_override` resolved before base price; POS variant sync ships it | `Pricing/Domain/Services/PricingService.php:30-39,55-67`; `POS/.../PosVariantController.php:62-77` |
| Existing POS permission cache: 24h TTL, fail-closed when stale | `apps/pos/src/lib/discountPermissions.ts:35-49,97-110` |

## 3. Data model

### 3.1 New columns
| Table | Column | Type | Notes |
|---|---|---|---|
| `products` | `max_discount_percent` | `decimal(5,2)` nullable | null = inherit |
| `categories` | `max_discount_percent` | `decimal(5,2)` nullable | own → ancestors, nearest wins |
| `companies` | `default_max_discount_percent` | `decimal(5,2)` nullable | null = no company cap |
| ~~`companies`~~ | ~~`margin_floor_buffer_percent`~~ | — | **SUPERSEDED (R3-1): column DROPPED — floor reuses the existing `default_minimum_margin` cascade** |
| `companies` | `discount_floor_mode` | string(24) + enum `DiscountFloorMode` | see §5 for values/migration |
| `companies` | `price_entry_mode` | string(4) + enum `PriceEntryMode { Ht, Ttc }` | default `Ht`; toggle always visible |

**Validation (every new percent field):** `numeric`, `min:0`, `max:100`, regex `/^\d+(\.\d{1,2})?$/` with message; enums via PHP enum + FormRequest enum rule. (Existing `companies` margin-default validation lacks `max:100` — new fields must not follow that precedent — S3-04.)

### 3.2 Country regulatory rules (seeded tenant table)
`country_pricing_regulations`: `id uuid`, `country_code char(2)`, `rule_type` enum `RegulatoryRuleType { BelowCostFloor, PharmaMarginSchedule }`, `params jsonb` (typed DTO), `enforcement` enum `{ Advisory, Block }` (**default Advisory** — Decision 3), `active bool`, timestamps. Unique `(country_code, rule_type)`.
Seeds: `FR/below_cost_floor` (basis `last_purchase_cost`, **Advisory**), `TN/below_cost_floor` (**Advisory**), `TN/pharma_margin_schedule` (4-tier table, `active=false` until Phase 3). Providers read by company country. No country logic in code branches.

### 3.3 Permissions (Spatie, tenant teams)
- **Reuse** `pricing.view_cost_prices` — gates cost/margin blocks on the **web** product form + detail page. (Do NOT invent `pricing.view_costs` — S1-04.)
- **New** `pricing.override_discount_floor` — proceed past a floor breach (Phase 2 device + Document). Add to `PermissionSeeder`, `RolesAndPermissionsSeeder` role maps, tests.
- Deploy owes permission reseed + **`permission:cache-reset`** per tenant DB (tenant-blind global cache key — S8-01). Add a test: check fails before reseed, passes after.
- Operator discount-permission lookup must validate operator↔company/terminal membership server-side (company-grain v1 — S8-02).

### 3.4 Explicitly no schema for
Coefficient (derived, input-mode only), rounding helper (frontend-only), per-user HT/TTC preference, wholesale tables (exist, dormant).

## 4. Backend — DiscountPolicyService (Pricing module)

### 4.1 Contract & context
`Shared/Contracts/DiscountPolicyInterface`, implemented by `Pricing/Domain/Services/DiscountPolicyService`. **Constructor-injected only** (no `app()`): `DiscountCapResolver`, `CurrencyScaleResolverInterface`, iterable `RegulatoryPricingRuleInterface`, a `DiscountPolicySubjectProvider` (Product-module public service — §4.2).

```
resolve(DiscountPolicyContext $ctx): DiscountPolicyVerdict

DiscountPolicyContext {
  productId, variantId?, sellableType,     // variant-aware (Decision 4 / S6-02)
  effectiveUnitPrice,                      // the price to test
  priceBasis: GrossInclTax | NetExclTax,   // S1-03/S6-01 — required
  currency, taxConfigurationId|taxRate,    // for net<->gross conversion
  quantity, userId, terminalId, companyId
}
DiscountPolicyVerdict {
  maxDiscountPercent,        // string; min(user, terminal, product→category→company); "100.00" if none
  floorPriceNet,             // string; net floor; null if no cost data
  floorBasis,                // ProfitBuffer | LegalBelowCost | PharmaSchedule
  floorEnforcement,          // Block | Advisory  (per company mode ∩ regulatory enforcement)
  mode, overridable, requiresReason,
  policyVersion, policyAsOf   // for snapshot/staleness (S2-02/S7-02)
}
```
**Comparison rule:** always compare **net** effective price to **net** floor. If `priceBasis = GrossInclTax` (B2C POS), convert to net via the product's tax config before comparing (S6-01). Percent math at scale 2; money at resolver scale, explicit currency (§4.3).

### 4.2 Module boundary — DiscountPolicySubject
Pricing must NOT read Product/Category/Company models directly (CLAUDE.md rule 6 — S4-01). A **Product-module public service** returns a `DiscountPolicySubject` DTO: product id, variant id, category-chain ids + caps, cost basis (WAC net, last purchase cost), tax config, company settings (buffer, mode, default cap), currency. Pricing consumes the DTO + contracts only.

### 4.3 Floor computation & precision
- Profit floor = `WAC_net × (1 + buffer/100)` in bcmath at resolver scale +1, rounded once via `bcformatStrict` with **explicit currency** from context — never no-arg `getScale()` (safe in queued/ingestion contexts — S3-02).
- Each active `RegulatoryPricingRuleInterface::floorFor(subject, ctx): ?FloorContribution` contributes; final net floor = max of contributions; `floorBasis` = winner; `floorEnforcement` = strictest of company mode and the winning rule's enforcement.
- **No cost data** (`cost_price` 0/null AND `last_purchase_cost` null): `floorPriceNet = null`, guard disabled; UI "no cost data" hint; margin "—"; no div-by-zero.

### 4.4 Enforcement points
1. **Documents (Phase 1, authoritative/blocking):** invoice/order FormRequests consult the contract via a **constructor-injected rule object** (NOT the `app()`-based tolerance concern pattern — S4-02). Block-mode breach → 422 carrying the verdict in the `{error:{errors}}` envelope; override validates `pricing.override_discount_floor` + reason; emits audit event.
2. **POS device (Phase 2, the real gate):** device computes effective per-line net price for **percentage AND fixed** discounts (S2-01), **after** the discount orchestrator stacks promotions/coupons/loyalty and allocates to lines (S6-04), using Big.js strings; compares to synced `floor_price` + `max_discount_percent`. Breach → block or warn per `floorEnforcement`; override requires the `DISCOUNT_FLOOR_OVERRIDE` scope.
3. **Sealed snapshot (Phase 2):** before the SALE_RECEIPT fiscal event is chained, the device seals a policy snapshot into canonical bytes: `policy_version`, `policy_as_of`, `floor_price_net_at_sale`, `floor_basis_at_sale`, `max_discount_percent_at_sale`, and — when overridden — override evidence (scope, supervisor id, terminal id, reason, permission snapshot, effective net price). Evidence must exist **pre-seal** (events immutable — S7-01).
4. **Staleness (Phase 2):** sync payload carries `policy_version`/`policy_as_of`/`floor_valid_until`; device fail rule keyed to `discount_floor_mode` + offline age, mirroring the existing 24h permission-cache fail-closed pattern (S2-02).
5. **Ingestion audit (Phase 2, non-blocking):** replays `resolve()` against the **sealed snapshot** (S7-02), not current policy; snapshot breach without valid override evidence → `DiscountPolicyViolationDetected`; "would violate *current* policy" is a separate advisory signal. Never rejects (chain immutable).

### 4.5 Document rule wiring
Dedicated validation rule with constructor-injected dependencies registered in the container; invoked via FormRequest `withValidator()`. Do not replicate `AppliesDiscountToleranceRule`'s `app(CompanyContext::class)` (S4-02).

### 4.6 POS sync — cost redaction
Replace `Product::toArray()` in `SyncController` with an explicit **POS catalog DTO** that omits `cost_price`, `last_purchase_cost`, `purchase_price` and ships only `floor_price` + `effective_max_discount_percent` as **signed** constraints (S1-02/S2-04). Threat-model note: a numeric floor still reveals a cost lower-bound; accepted for v1, documented, payload signed at rest; revisit opaque policy tokens later.

### 4.7 Precision guardrails for new code
New code uses bc/string arithmetic + explicit currency + percent-at-scale-2; **does NOT reuse** the existing float discount helpers or `PricingService` discount math (S3-01/S3-03). Extending `GET /pos/discount-permissions` must not inherit its float contract — add verdict fields as **numeric strings** in a string-safe response shape.

### 4.8 Read API
Extend `GET /pos/discount-permissions` (string-safe) and add `POST /pricing/discount-policy` (context → verdict) for the web panel's advisory display. Middleware `['api','auth:sanctum',SetPermissionsTeam::class]`.

## 5. Migration — `allow_below_cost_sales` → `discount_floor_mode`

> **SUPERSEDED by R3-4.** No remap of `allow_below_cost_sales` (it stays UNCHANGED and authoritative for below-cost). Add `companies.discount_floor_mode` default `Advisory` for all tenants. The truth table below is VOID — kept only for historical context.

`allow_below_cost_sales=true` today means "below-cost *permitted if* the user holds `pricing.sell_below_cost`" (`MarginService.php:355-371`) — NOT "warn for everyone." Flat `true→Warn` drops the permission gate (S5-01). Truth table:

| `allow_below_cost_sales` | New `discount_floor_mode` | Behavior preserved |
|---|---|---|
| `false` (or null) | `Block` | below-floor blocked unless `pricing.override_discount_floor` |
| `true` | `WarnRequiresPermission` | below-floor allowed only for holders of `pricing.sell_below_cost` (or the new override perm); others warned/blocked |

`DiscountFloorMode { Block, Warn, WarnRequiresPermission }`. Before migrating, audit whether the flag round-trips in the UI/API (`UpdateCompanyRequest` doesn't validate it; `formatCompany` doesn't return it — S5-02) — if it was never truly user-set, default all tenants to `Block` with a release note rather than inferring intent. Migration test covers both flag values × users with/without `pricing.sell_below_cost`.

## 6. Edge cases

- **No cost data** → floor disabled + hint (§4.3).
- **TTC vs HT** → net-to-net comparison; gross POS price converted via tax config (§4.1).
- **Variants** → `variantId` in context; product-grain WAC is cost basis; variant `price_override` is the tested price (S6-02).
- **Refunds / credit notes** → replay the **original sale's sealed snapshot**; not blocked by a floor raised after the sale; no mutation of old events (S6-03). Credit notes remain outside the Treasury tolerance concern as today.
- **Promotion stacking** → floor evaluated over final stacked+allocated line price (§4.4 / S6-04).
- **Percent vs currency scale** → percents 2dp; money via resolver; intermediates scale+1, round once.
- **Admin bypass** → existing `DiscountPermissionResolver` 100% cap pin remains for caps; floor still applies in Block mode; admins hold the override permission.
- **`sale_price` unchanged** — single stored price; HT/TTC is entry/presentation via the product's tax config (`default_tax_configuration_id`, fallback `tax_rate`).

## 7. Testing (TDD; suites run BY PATH only)

- `DiscountCapResolver` — product / category chain / company / null, mirroring `MarginResolver` tests.
- `DiscountPolicyService` — net floor at TND(3) & EUR(2); GrossInclTax→net conversion; winner selection (buffer/legal/pharma); Block vs Advisory vs WarnRequiresPermission; override permission; no-cost-data; variant override path. Real models, `RefreshDatabase`, `RolesAndPermissionsSeeder`, valid UUIDs, **`app(CompanyContext::class)->clear()` before ingestion-audit apply** (S3-02).
- Regulatory providers per rule type (FR, TN, rule-less country); Advisory-not-Block assertion.
- Document 422 (`AssertsApiValidation`) + override-with-reason happy path.
- Migration truth-table test (both flag values × permission holders).
- POS device (Phase 2): fixed + percentage + transaction-level + stacked-promotion discounts vs floor; staleness fail rule; override evidence sealed pre-seal; snapshot replay in ingestion audit.
- POS sync DTO test: cost fields absent from payload (S1-02).
- Permission test: fails before reseed/cache-reset, passes after (S8-01).
- Frontend Vitest: calculator round-trips (margin/coefficient/HT/TTC), rounding chips, HT/TTC swap, `pricing.view_cost_prices`-gated rendering, inherited-cap placeholder. Rendered output, not classes. Kill orphaned vitest workers after any hang.

## 8. Rollout

| Phase | Delivers | Deploy owes |
|---|---|---|
| **1 — Web (advisory)** | Panel + 4-mode calculator + rounding + HT/TTC toggle + cap cascade + `DiscountPolicyService` + `DiscountPolicySubject` + Document enforcement + FR/TN advisory rules + permissions | `tenants:migrate`, perm reseed, `permission:cache-reset`, regulatory seeder |
| **2 — POS (guarantee)** | Redacted sync DTO + signed floor/cap + snapshot + device enforcement (fixed+percent, post-stacking) + `DISCOUNT_FLOOR_OVERRIDE` + ingestion audit | device release + server checklist |
| **3 — TN pharma** | Regulated flag + schedule provider (advisory→block on sign-off) | migrate + seeder update |
| **4 — Wholesale** | Separate spec: `PricingService` wiring, tier UI, partner discount reconciliation | — |

Adversarial review at every phase milestone (owner standing rule).

## 9. Research grounding

2026-07-07 deep-research (verified 3-0 unless noted): Lightspeed markup/margin distinct modes + Round To/Always Round Up + dual cost fields with one margin basis; Odoo simple→advanced price-rule tiers; Henrri HT/TTC toggle (per-doc + global, HT default, other derived); Memsoft psychological-rounding engine on HT or TTC; Sphinx Manager (auto-parts) PUMP/WAC + instant margin + inline last purchase price; taux de marge vs marque + coefficient=100/(100−marque); TN pharma 4-tier regressive schedule; NN/g progressive disclosure + conditional fields. Coefficient-by-category (2-1, directional).

Supplementary (2026-07-07, extracted but verification hit rate/usage limits — treat as **primary-sourced, unverified**; do not hard-code legal enforcement without sign-off — Decision 3): per-product max discount is add-on territory in Odoo (hard-block, no override — our permissioned override exceeds baseline); Dynamics margin guard = warn-only traffic light, configurable cost basis; FR L442-5 below-cost floor anchored to **invoice cost** (→ our `last_purchase_cost` basis); TN below-cost prohibition; Winpharma "Fixer PVTTC" TTC-first mode.

Refuted (do not build on): universal "pharmacy price = cost × coefficient then VAT"; Geskopro linked pricing-chain.
