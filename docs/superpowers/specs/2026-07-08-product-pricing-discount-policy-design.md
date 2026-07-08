# Product Pricing Panel + Discount Policy Cascade — Design Spec

**Date:** 2026-07-08
**Status:** Approved by owner (brainstorm 2026-07-07/08); pending adversarial review
**Origin:** Client demo feedback — product page must surface WAC, last purchase price, margin, price HT (some clients: TTC), enable price-setting via margin or manual rounding, and enforce a per-product maximum discount with a "never lose on a sale" guarantee.

---

## 1. Scope

**In scope (this spec):**
- **Stream A — Pricing-intelligence panel** on the web product page: cost reference (WAC + last purchase price), bidirectional calculator (margin % / coefficient / price HT / price TTC), HT/TTC entry-mode toggle, rounding helper, margin traffic-light + floor display.
- **Stream B — Discount policy cascade**: per-product/category/company maximum discount, margin-floor guard (WAC × (1+buffer)), country-driven legal floors (FR/TN revente-à-perte), warn-vs-block company mode, permission-gated override with mandatory reason, offline-first POS enforcement, ingestion audit.

**Designed-for but NOT built (Stream C):** wholesale/retail price tiers. The dormant `Pricing` module (`price_lists`, `price_list_items`, `partner_price_lists`, `PricingService::getPrice()`) already models tiers; this spec only keeps the UI/data shapes tier-compatible. Activation (routing POS/Document/Cart pricing through `PricingService`) is a separate future spec.

**Out of scope:** POS-side cost visibility (cashier product page shows no costs), partner `discount_percentage` auto-application, promotion engine changes, price history tab.

## 2. Current-state facts this design builds on

| Fact | Location |
|---|---|
| WAC stored on `products.cost_price`, written by `WeightedAverageCostService` | `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` |
| Last purchase price on `products.last_purchase_cost` (+ `cost_updated_at`) | migration `2025_12_02_064541` |
| Margin overrides cascade product → category chain → company → hardcoded fallback | `Product/Application/Services/MarginResolver.php:200-224` |
| Margin guard levels GREEN/YELLOW/ORANGE/RED | `Product/Application/Services/MarginService.php:44-52`, `canSellAtPrice():347-401` |
| Product form already has a bidirectional cost/margin/HT/TTC calculator strip | `apps/web/src/features/inventory/ProductForm.tsx:148-207,762-781` |
| Discount caps exist per terminal (`pos_terminals.max_discount_percent`) and per user (`users.max_discount_percent`, `can_discount`); most-restrictive-wins; reason required > 10% | `POS/Domain/Services/DiscountCalculationService.php` |
| NO per-product discount cap exists; NO code relates discounts to margin/cost | verified 2026-07-07 investigation |
| Treasury `DiscountToleranceBoundary` covers Document invoices/orders but NOT the POS receipt path | `Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php` |
| POS is offline-first: device SQLite + outbox sync; receipts fiscally signed on device, immutable after chain inclusion | POS architecture |
| Money = bcmath via `CurrencyScale::bcformatStrict` + constructor-injected `CurrencyScaleResolverInterface`; scale per currency (TND=3, EUR=2); hardcoded scales banned (PHPStan) | precision contract |

## 3. Data model

### 3.1 New columns
| Table | Column | Type | Notes |
|---|---|---|---|
| `products` | `max_discount_percent` | `decimal(5,2)` nullable | null = inherit |
| `categories` | `max_discount_percent` | `decimal(5,2)` nullable | resolved own → ancestors, nearest-first |
| `companies` | `default_max_discount_percent` | `decimal(5,2)` nullable | null = no company cap (user/terminal caps still apply) |
| `companies` | `margin_floor_buffer_percent` | `decimal(5,2)` default `10.00` | 0 allowed = break-even floor |
| `companies` | `discount_floor_mode` | string(8) + enum `DiscountFloorMode { Warn, Block }` | default **Block** |
| `companies` | `price_entry_mode` | string(4) + enum `PriceEntryMode { Ht, Ttc }` | default **Ht** |

Percent columns are NOT currency-scaled: `decimal(5,2)` + FormRequest regex `/^\d+(\.\d{1,2})?$/`, range 0–100.

### 3.2 Country regulatory rules (seeded tenant table)
`country_pricing_regulations`: `id uuid`, `country_code char(2)`, `rule_type` string + enum `RegulatoryRuleType { BelowCostFloor, PharmaMarginSchedule }`, `params jsonb` (typed DTO per rule type), `active bool`, timestamps. Unique `(country_code, rule_type)`.

Seeds:
- `FR / below_cost_floor` — basis: `last_purchase_cost` (revente à perte, Code de commerce L442-5 anchors to invoice cost, not WAC).
- `TN / below_cost_floor` — same basis (Tunisian below-cost prohibition).
- `TN / pharma_margin_schedule` — 4-tier regressive max-margin table in `params` (42.9% ≤ 2.890 TND / 38.9% ≤ 7.754 / 35.1% ≤ 23.974 / 31.6% above); **inactive until Phase 3** (requires regulated-product flag, shipped then).

Rule providers read this table by the company's country. **No country logic in code branches.** Adding a country = seeding rows.

### 3.3 Permissions (Spatie, tenant-scoped)
- `pricing.view_costs` — render cost/margin blocks on web product form + detail page. Default: admin, manager.
- `pricing.override_discount_floor` — proceed past a Block-mode floor breach. Default: admin, manager.

Deploys owe permission reseed + `permission:cache-reset` (tenant-blind cache).

### 3.4 Explicitly no schema for
Coefficient (derived, never stored), rounding helper (frontend-only), per-user HT/TTC preference, wholesale tables (already exist, dormant).

## 4. Backend — DiscountPolicyService (Pricing module)

### 4.1 Contract
New `Shared/Contracts/DiscountPolicyInterface`, implemented by `Pricing/Domain/Services/DiscountPolicyService`. Constructor-injected: `DiscountCapResolver`, `CurrencyScaleResolverInterface`, iterable of `RegulatoryPricingRuleInterface` providers, company context/settings reader.

```
resolve(productId, unitPrice, quantity, DiscountPolicyContext $ctx): DiscountPolicyVerdict
DiscountPolicyVerdict {
  maxDiscountPercent: string   // min(user cap, terminal cap, product→category→company cap); "100.00" if none
  floorPrice: ?string          // max(WAC×(1+buffer), active regulatory floors); null if no cost data
  floorBasis: ?FloorBasis      // ProfitBuffer | LegalBelowCost | PharmaSchedule
  mode: DiscountFloorMode      // company setting
  overridable: bool            // caller holds pricing.override_discount_floor
  requiresReason: bool         // override OR discount > existing 10% reason threshold
}
```

### 4.2 DiscountCapResolver
Mirrors `MarginResolver::resolveField`: product `max_discount_percent` → category chain (nearest first) → company `default_max_discount_percent` → null. Combined with user/terminal caps by **min()** (most-restrictive-wins, consistent with existing `getEffectiveDiscountLimit`).

### 4.3 Floor computation
- Profit floor = `WAC × (1 + buffer/100)`; bcmath at resolver scale +1, rounded once at boundary via `bcformatStrict` with explicit entity currency (safe in queued/ingestion contexts — never no-arg `getScale()`).
- Each active `RegulatoryPricingRuleInterface::floorFor(product, ctx): ?FloorContribution` contributes; final floor = max of contributions. `floorBasis` records the winner.
- **No cost data** (`cost_price` = 0/null AND `last_purchase_cost` null): `floorPrice = null`, guard disabled for that product; UI shows "no cost data" hint. No division by zero; margin renders "—".
- Legacy `companies.allow_below_cost_sales = true` is data-migrated to `discount_floor_mode = Warn` (no tenant silently hardens). `MarginService::canSellAtPrice` remains the price-setting guard; the policy service is the discount-time guard — both read the same margin config.

### 4.4 Enforcement points
1. **Documents (authoritative, blocking):** invoice/order FormRequests consult the contract (same concern pattern as `AppliesDiscountToleranceRule`). Block-mode breach → 422 carrying the verdict DTO in the standard `{error:{errors}}` envelope. Override path validates `pricing.override_discount_floor` + mandatory reason; emits audit event.
2. **POS server write-path (online):** `DiscountCalculationService.validateLineDiscount/validateTransactionDiscount` extended to consult the contract alongside existing terminal/cashier checks.
3. **POS device (offline — the real retail gate):** product sync payload gains `effective_max_discount_percent` (server-resolved) + `floor_price` (server-computed). Raw WAC never ships to devices. Device SQLite stores both; discount modal enforces before commit; override uses synced permission flags + mandatory reason (reuses `discount_reason`). Floors are as fresh as the product sync itself; staleness accepted (same class as price staleness).
4. **Receipt ingestion audit (non-blocking):** listener re-runs `resolve()` on synced offline receipts; violations emit `DiscountPolicyViolationDetected` → violations report. Never rejects — fiscal hash chain is immutable.

This wiring also closes the pre-existing inconsistency where the Treasury tolerance boundary covers Documents but not POS: both flows now pass one policy brain (tolerance boundary remains separately enforced as today; unifying it is out of scope).

### 4.5 Read API
- Extend `GET /api/v1/pos/discount-permissions` response with the verdict fields.
- New `POST /api/v1/pricing/discount-policy` (product, price, qty → verdict) for the product form's advisory display. Route middleware `['api','auth:sanctum',SetPermissionsTeam::class]`.

## 5. Frontend — `<ProductPricingPanel>`

Extract the pricing strip from `ProductForm.tsx` (1,646 lines — extraction also pays down the file) into `features/products/pricing/ProductPricingPanel.tsx` with `editable` and `readonly` modes; reused on `ProductDetailPage` (replacing the ad-hoc header price cards).

Layout, top to bottom:
1. **Cost reference row** — gated by `pricing.view_costs`: WAC (labeled as margin basis), last purchase price, cost-updated date. Ungated users see only price fields.
2. **Calculator** — one price, four coupled inputs: Margin % | Coefficient | Price HT | Price TTC. Existing bidirectional bc-string helpers extended with coefficient (= priceHT ÷ cost; input mode only, never stored). Company `price_entry_mode` picks the visually-primary price field; inline toggle swaps HT/TTC prominence.
3. **Rounding helper** — one-tap chips (`.99` / `.90` / round) on the primary price; never auto-applied; derived values recompute.
4. **Guard rail strip** — existing GREEN/YELLOW/ORANGE/RED levels as traffic light + floor price with basis label ("floor 12.400 — legal minimum"). Saving a price below floor follows the same warn/block company mode.
5. **Discount cap field** — `max_discount_percent` with inherited-value placeholder + source ("inherited: 10% from Cosmétiques"), like margin-override fields. Same field on category form + company settings.

Mechanics: `<MoneyInput>`/percent inputs emit strings (no parseFloat — ESLint-enforced); `tenantScopedKey` query keys; all text via `t()` (additions to `pricing` namespace); design tokens exclusively (new directory → error-level lint); types regenerated via `php artisan typescript:transform` after DTO changes.

Tier-readiness: the price block is structured to become a per-price-list row later ("Détail" = default list); no tier UI now.

## 6. Edge cases

- **No cost data** → floor disabled + hint (see 4.3).
- **Percent vs currency scale** — percents fixed 2dp; money via resolver; intermediates scale+1, round once.
- **Stale device floors** → device enforces synced values; ingestion audit catches misses.
- **Zero/null caps** — cap null at every level → only user/terminal caps apply (today's behavior preserved).
- **Admin bypass** — existing `DiscountPermissionResolver` admin pin to 100% remains for caps; floor still applies to admins in Block mode but they hold the override permission.
- **`sale_price` semantics unchanged** — remains the single stored price; HT/TTC is a presentation/entry concern converting through the product's tax config (`default_tax_configuration_id`, fallback `tax_rate`).

## 7. Testing (TDD; suites run BY PATH only)

- `DiscountCapResolver` unit tests mirroring `MarginResolver` test shape (product / category chain / company / null).
- `DiscountPolicyService`: floor arithmetic at TND(3) and EUR(2) scales; winner selection (buffer vs legal vs pharma); warn/block verdicts; override permission; no-cost-data path. Real models, `RefreshDatabase`, `RolesAndPermissionsSeeder`, valid UUIDs.
- Regulatory providers: per rule type against seeded rows (FR, TN, rule-less country).
- Document FormRequest 422 (verdict in envelope, `AssertsApiValidation` trait) + override-with-reason happy path.
- POS `DiscountCalculationService` extension tests; ingestion-audit listener (violation → event, receipt untouched).
- Frontend Vitest: calculator round-trips (all four modes incl. coefficient), rounding chips, HT/TTC swap, permission-gated rendering, inherited placeholder. Rendered output, not class names. Kill orphaned vitest workers after any hang.
- Migration test: `allow_below_cost_sales` → `Warn` mapping.

## 8. Rollout phases (progressive; adversarial review at every milestone)

| Phase | Delivers | Deploy owes |
|---|---|---|
| **1 — Web** | Panel extraction + 4-mode calculator + rounding + HT/TTC toggle + cap cascade + `DiscountPolicyService` + Document enforcement + FR/TN below-cost rules + permissions | `tenants:migrate`, perm reseed, `permission:cache-reset`, regulatory seeder |
| **2 — POS** | Sync payload fields + device enforcement + override flow + ingestion audit | device app release + same server checklist |
| **3 — TN pharma** | Regulated-product flag + schedule provider activation | migrate + seeder update |
| **4 — Wholesale** | Separate spec: `PricingService` wiring into POS/Documents/Carts, tier UI, partner `discount_percentage` reconciliation | — |

## 9. Research grounding (2026-07-07 deep-research runs)

Verified (3-0 unless noted): Lightspeed markup/margin as distinct modes + Round To/Always Round Up + dual cost fields with one margin basis; Odoo simple→advanced price-rule tiers; Henrri HT/TTC toggle (per-document + global, HT default, other derived); Memsoft psychological-rounding rules engine on HT or TTC; Sphinx Manager (auto-parts) PUMP/WAC + instant margin + last purchase price inline; taux de marge vs taux de marque + coefficient = 100/(100−marque); TN pharma 4-tier regressive schedule; NN/g progressive disclosure + conditional fields. Coefficient-by-category table (2-1, directional). Unverified-but-primary-sourced (verification hit usage limits): per-product max discount is add-on territory in Odoo (hard-block, no override — we exceed baseline); Dynamics margin guard = warn-only traffic light w/ configurable cost basis; FR L442-5 floor anchored to invoice cost; TN below-cost prohibition; Winpharma "Fixer PVTTC" TTC-first mode.

Refuted (do not build on): universal "pharmacy price = cost × coefficient then VAT" formula; Geskopro linked pricing-chain claim.
