# Adversarial Design Review: Product Pricing Panel + Discount Policy Cascade

Verdict: **not implementation-ready**. The product pricing panel is feasible, but the discount policy part has several blockers at the POS/fiscal boundary. The spec promises “never lose on a sale” and “offline-first POS enforcement,” yet it defers the real POS gate to Phase 2, relies on currently retired server receipt paths, under-specifies stale policy snapshots and override replay, leaks cost-derived information through sync payload design, and risks violating the precision and module-boundary contracts unless the design is tightened before code starts.

| ID | Severity | One-line | Area |
|---|---:|---|---|
| S1-01 | BLOCKER | Phase 1 can ship without the actual retail gate, contradicting the “never lose” guarantee | Internal contradictions |
| S1-02 | BLOCKER | “No POS cost visibility” conflicts with `floor_price` / cap payloads and current POS sync leaking product cost fields | Internal contradictions / POS |
| S1-03 | MAJOR | `unitPrice` contract is ambiguous across POS TTC and document HT flows | Internal contradictions / edge cases |
| S1-04 | MAJOR | Spec names permissions that do not match existing seeded permissions | Internal contradictions / permissions |
| S2-01 | BLOCKER | POS fixed-amount discounts can bypass local percentage cap logic before fiscal sealing | Offline POS |
| S2-02 | BLOCKER | Floor staleness is accepted but not bounded or snapshotted for replay | Offline POS |
| S2-03 | MAJOR | Floor override flow is not permissioned/logged/replayable enough for offline use | Offline POS |
| S2-04 | MAJOR | `floor_price` and `effective_max_discount_percent` leak cost-derived data to devices/network | Offline POS |
| S3-01 | BLOCKER | Existing discount API and POS UI use floats/`parseFloat` in discount enforcement surfaces | Precision |
| S3-02 | BLOCKER | Existing services use no-arg `getScale()` and would be unsafe in queued ingestion audit | Precision |
| S3-03 | MAJOR | Pricing service mixes percent checks with currency scale and no-arg currency scale | Precision |
| S3-04 | MAJOR | New percent/company fields need explicit regex/max/enum validation; existing company margin rules are not enough | Precision |
| S4-01 | BLOCKER | Proposed Pricing service risks direct Product/Category/Company model access across modules | Module boundaries |
| S4-02 | MAJOR | “Same concern pattern” would copy an existing `app()` dependency anti-pattern | Module boundaries |
| S5-01 | BLOCKER | Mapping `allow_below_cost_sales=true` to `Warn` changes current semantics | Migration |
| S5-02 | MAJOR | Current API does not round-trip `allow_below_cost_sales`, so backfill data may not reflect tenant intent | Migration |
| S5-03 | MAJOR | Default/backfill rules are ambiguous for null, false, and permission-gated tenants | Migration |
| S6-01 | BLOCKER | Tax-inclusive POS prices vs document net prices can make floor comparisons wrong | Edge cases |
| S6-02 | MAJOR | Variant `price_override` is not represented in policy resolution | Edge cases |
| S6-03 | MAJOR | Refunds/credit notes need original sale policy snapshot, not current floor | Edge cases |
| S6-04 | BLOCKER | Promotions/coupons/loyalty stacking can push effective price below floor after validation | Edge cases |
| S7-01 | BLOCKER | Retroactive override/floor evidence cannot be added to sealed fiscal events | Fiscal hash chain |
| S7-02 | MAJOR | Ingestion audit against current policy creates false positives after floor edits | Fiscal hash chain |
| S8-01 | BLOCKER | New permissions are absent and cache reset requirements are real under Spatie teams | Multi-tenant permissions |
| S8-02 | MAJOR | Operator discount-permission lookup is tenant-scoped, not branch/company/terminal-authoritative | Multi-tenant permissions |
| S8-03 | MAJOR | Company-wide POS sync may leak floor/cap policy across branch/location boundaries | Multi-tenant permissions |

## 1. Internal Contradictions Within The Spec

### S1-01 — BLOCKER — Phase split contradicts the “never lose on a sale” guarantee

**What’s wrong:** The spec’s origin promises a per-product maximum discount with a “never lose on a sale” guarantee, and Stream B explicitly includes offline-first POS enforcement (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:5`, `:13`). But rollout Phase 1 ships the policy service plus Document enforcement while Phase 2 ships POS sync/device enforcement/ingestion audit (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:143-144`). The real retail sale gate is the device-authored POS flow: `POST /pos/receipts` is retired and new receipts are device-authored then ingested via fiscal events (`apps/api/app/Modules/POS/routes.php:145-151`).

**Why it matters:** Phase 1 can be “complete” while the actual POS can still sell below floor offline. That is the exact workflow the guarantee is supposed to prevent.

**Concrete fix/question:** Make Phase 1 explicitly advisory-only, or move POS sync fields, local enforcement, override evidence, and ingestion replay into the first enforceable release. The spec should define the first release that is allowed to claim “never lose on a sale.”

### S1-02 — BLOCKER — “No POS-side cost visibility” conflicts with cost-derived payloads and current sync behavior

**What’s wrong:** The spec says POS-side cost visibility is out of scope (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:17`) and says raw WAC never ships to devices (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:96`). But the proposed POS payload ships `floor_price` and `effective_max_discount_percent`, both cost-derived. Worse, the current POS sync path maps each `Product` using `$product->toArray()` (`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:81-90`), while `Product` exposes `cost_price` and `last_purchase_cost` as cast attributes (`apps/api/app/Modules/Product/Domain/Product.php:50-54`, `apps/api/app/Modules/Product/Domain/Product.php:156-164`). The regular `ProductData` DTO also includes `purchase_price` and `cost_price` (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:30-32`, `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:79-82`).

**Why it matters:** Even if raw WAC is not displayed, it can leak over the network or into device storage. A floor or max discount also reveals a lower bound on cost, and if buffer/regulatory basis is known, it can approximate cost.

**Concrete fix/question:** Replace `Product::toArray()` in POS sync with an explicit POS catalog DTO that redacts all cost fields. Decide whether floor enforcement can be expressed as a sealed policy token/verdict instead of raw floor/cap values, or accept and document cost-derived leakage.

### S1-03 — MAJOR — `unitPrice` contract is ambiguous across TTC POS and HT documents

**What’s wrong:** The policy contract is `resolve(productId, unitPrice, quantity, DiscountPolicyContext $ctx)` (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:73`), and the spec says `sale_price` semantics stay unchanged with HT/TTC only as presentation (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:127`). But the precision contract says `unit_price` is tax-inclusive in B2C POS and net/HT in B2B Documents (`docs/architecture/precision-contract.md:73-85`; also summarized in `CLAUDE.md:77`). Current POS receipt creation treats `unit_price` as gross, discounts it, then extracts VAT from the discounted gross amount (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:243-318`). Document requests validate `lines.*.unit_price` as document-line HT input (`apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:119`).

**Why it matters:** A floor computed against net cost but compared to TTC sale price can falsely pass below-cost sales. The opposite can falsely block legal sales.

**Concrete fix/question:** Add explicit price basis to `DiscountPolicyContext`: `GrossInclTax` vs `NetExclTax`, currency, tax configuration/rate, and whether comparison should happen against net effective unit price.

### S1-04 — MAJOR — Spec names permissions that do not exist

**What’s wrong:** The spec introduces `pricing.view_costs` and `pricing.override_discount_floor` (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:58-62`). Existing seeded permissions include `pricing.view_cost_prices`, `pricing.sell_below_cost`, `pricing.manage_pricing_rules`, etc., but not those names (`apps/api/database/seeders/PermissionSeeder.php:138-147`). `RolesAndPermissionsSeeder` only lists broad `pricing.view` and `pricing.manage` in the excerpted pricing group (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:71-74`).

**Why it matters:** A mismatched permission name silently hides UI or blocks overrides, and Spatie’s cache can preserve stale permission state.

**Concrete fix/question:** Either reuse `pricing.view_cost_prices` or explicitly migrate/alias it. Add `pricing.override_discount_floor` to all seeders, role assignments, tests, and deployment docs.

## 2. Offline-POS Enforcement Holes

### S2-01 — BLOCKER — Fixed-amount discounts can bypass local cap/floor logic

**What’s wrong:** The POS line discount modal computes `percentageExceeded` only when `discountType === 'percentage'`; fixed discounts are only checked for `numericValue > 0` locally (`apps/pos/src/components/organisms/LineDiscountModal/LineDiscountModal.tsx:113-118`). The transaction discount modal has the same pattern (`apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx:112-118`). Server-side legacy receipt creation derives a percent for fixed discounts (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1030-1049`), but that new-sale endpoint is retired (`apps/api/app/Modules/POS/routes.php:145-151`). Device-authored fiscal events are later ingested, and the ingestor’s invariant is “device is NEVER blocked” for server-side anomalies (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:45-51`).

**Why it matters:** The device can commit and seal a fixed discount that exceeds the cap or crosses the floor before the server ever sees it. A later non-blocking audit does not enforce the sale.

**Concrete fix/question:** Implement local effective-price calculation for both percentage and fixed discounts before fiscal sealing. Fixed discount must be converted to effective per-line net/gross basis with Big.js and compared to synced policy. Add tests for fixed amount, percentage, transaction-level, and stacked discounts.

### S2-02 — BLOCKER — Floor staleness is accepted but not bounded or replayable

**What’s wrong:** The spec says stale device floors are accepted and ingestion audit catches misses (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:124`). Existing permission caching has an explicit 24-hour TTL and fails closed when stale (`apps/pos/src/lib/discountPermissions.ts:3`, `apps/pos/src/lib/discountPermissions.ts:35-49`, `apps/pos/src/lib/discountPermissions.ts:97-110`). The proposed floor payload has no equivalent `policy_as_of`, TTL, version, or fail-closed rule.

**Why it matters:** A floor can change after product sync. Without a bounded staleness window and a policy snapshot, the device can keep selling under an obsolete floor indefinitely, or the server can later flag legitimate sales as violations against a floor that did not exist when the sale was sealed.

**Concrete fix/question:** Add `discount_policy_version`, `policy_as_of`, `floor_valid_until`, and per-product policy hash to the sync payload and receipt payload. Define fail-open vs fail-closed by `discount_floor_mode`, tenant setting, and terminal offline age.

### S2-03 — MAJOR — Override abuse is not sufficiently permissioned, logged, or replayable

**What’s wrong:** Current POS override scopes are `discount_limit_override`, with event types `LINE_DISCOUNT_LIMIT_OVERRIDE` and `DISCOUNT_LIMIT_OVERRIDE` (`apps/pos/src/components/organisms/LineDiscountModal/LineDiscountModal.tsx:151-181`, `apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx:151-179`). There is no floor-specific approval scope in the current code. Cached operator records store permissions, `company_ids`, `terminal_ids`, and `approval_scopes` (`apps/pos/src/lib/db/repositories/operatorPinRepository.ts:6-25`), but the code comments document the prior risk: a manager mirrored before suspension kept a valid local PIN and could approve offline because the offline path checks client-side bcrypt and never reaches the server (`apps/pos/src/lib/db/repositories/operatorPinRepository.ts:293-301`).

**Why it matters:** Floor breaches are higher-risk than generic discount-limit overrides because they can create below-cost or illegal sales. Reusing generic discount override evidence makes it hard to prove who overrode what policy, under which synced permission version, and whether the approval was valid at sale time.

**Concrete fix/question:** Add a distinct scope/event type such as `discount_floor_override` / `DISCOUNT_FLOOR_OVERRIDE`, include policy hash, floor basis, effective unit price, reason, supervisor id, terminal id, company id, and permission snapshot in the canonical receipt or a linked pre-sale audit event.

### S2-04 — MAJOR — Floor and effective cap leak cost data

**What’s wrong:** The spec says raw WAC never ships, but ships `floor_price` and `effective_max_discount_percent` (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:96`). Since profit floor is `WAC × (1 + buffer/100)` (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:88`), a device or network observer can infer WAC if the buffer is known. Even if the exact buffer is hidden, `effective_max_discount_percent` reveals the floor relative to sale price.

**Why it matters:** The spec’s own out-of-scope item is POS-side cost visibility. Cost-derived sync fields are still cost visibility to anyone with device DB access or traffic logs.

**Concrete fix/question:** Either document this as an accepted leak, encrypt/sign policy payloads at rest, or send only opaque policy-verdict constraints that the device can enforce without revealing the raw floor. If local enforcement requires the numeric floor, add threat-model language.

## 3. Precision-Contract Violations

### S3-01 — BLOCKER — Existing discount surfaces use floats and `parseFloat`

**What’s wrong:** The precision contract forbids JS/PHP floats touching money or quantity (`docs/architecture/precision-contract.md:13`, `docs/architecture/precision-contract.md:51-55`; `CLAUDE.md:71-78`). Current POS discount modals use `parseFloat(value)` (`apps/pos/src/components/organisms/LineDiscountModal/LineDiscountModal.tsx:113`, `apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx:112`). The discount permissions endpoint casts max percentages to floats and returns a float effective limit (`apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:111`, `apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:221-230`). POS cached discount limits are modeled as `number` and combined with `Math.min` (`apps/pos/src/lib/discountPermissions.ts:7-10`, `apps/pos/src/lib/discountPermissions.ts:79-93`).

**Why it matters:** Floor enforcement depends on exact effective price and percent comparisons. Float drift at the POS device can make the fiscal payload disagree with server replay.

**Concrete fix/question:** Convert POS discount values to Big.js string arithmetic and return policy/permission numbers as numeric strings. Do not extend `GET /pos/discount-permissions` with new verdict fields until its existing float contract is fixed or isolated behind a new string-safe response version.

### S3-02 — BLOCKER — No-arg `getScale()` appears in services relevant to POS and pricing

**What’s wrong:** The spec explicitly promises explicit currency scale and no no-arg `getScale()` in queued/ingestion contexts (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:88`). The precision contract says queued listeners, console, and domain transitions must pass entity currency or use `getScaleSafe`; no-arg `getScale()` throws without `CompanyContext` (`docs/architecture/precision-contract.md:24`; `CLAUDE.md:74-85`). Existing `DiscountCalculationService` uses no-arg `getScale()` (`apps/api/app/Modules/POS/Domain/Services/DiscountCalculationService.php:38-41`), `ReceiptCreationService` does the same (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:82-85`), and `PricingService` does the same (`apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:24-27`).

**Why it matters:** The spec’s ingestion audit listener is exactly the kind of queued/fiscal projection context that may not have `CompanyContext`. Reusing these services will fail or silently depend on ambient request state.

**Concrete fix/question:** Make the policy contract carry explicit currency. All policy arithmetic must call `getScale($currency)` or `getScaleSafe($currency, 3)`. Add a test that clears `CompanyContext` before running the ingestion audit listener.

### S3-03 — MAJOR — Existing Pricing service mixes percent checks with currency scale

**What’s wrong:** `PricingService::calculateLineTotal()` and `applyDocumentDiscount()` compare percentage values using `$this->scale()` and compute percentage discounts at currency scale (`apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:287-330`, `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:338-373`). The precision contract says percentages are fixed 2dp and not currency-scaled (`docs/architecture/precision-contract.md:36`). The spec says the future discount policy service lives in Pricing (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:67-70`).

**Why it matters:** If the new service copies current Pricing arithmetic, percent comparisons will vary by currency scale and violate the contract.

**Concrete fix/question:** Keep percent arithmetic at scale 2 for comparisons and higher fixed intermediate scale for rate math. Do not reuse `PricingService` discount helpers for policy enforcement until they are corrected or wrapped.

### S3-04 — MAJOR — New field validation must be stricter than existing company settings precedent

**What’s wrong:** The spec correctly says percent columns need `decimal(5,2)`, regex, and range 0-100 (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:46`). Existing document requests follow this pattern for `discount_percent` (`apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:127`, `apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php:105`). But existing company margin defaults only have `min:0` and regex, no `max:100` (`apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:50-52`).

**Why it matters:** New `default_max_discount_percent`, `margin_floor_buffer_percent`, and product/category caps cannot rely on the company-settings precedent. Invalid high percentages could effectively disable floors or caps.

**Concrete fix/question:** Every new percent field needs `numeric`, `min:0`, `max:100`, fixed 2dp regex, and regex messages. `discount_floor_mode` and `price_entry_mode` must be PHP enums and FormRequest enum rules.

## 4. Module-Boundary Violations

### S4-01 — BLOCKER — DiscountPolicyService design risks direct cross-module model imports

**What’s wrong:** `CLAUDE.md` rule 6 says cross-module communication must use `Shared/Contracts`, events, or a module’s public service class, and never direct model imports across modules (`CLAUDE.md:30-32`). The spec puts `DiscountPolicyService` in Pricing and says it resolves product/category/company caps and regulatory floors (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:67-70`, `docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:84-89`). Current `PricingService` already imports `Product` directly (`apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:7-10`), POS sync imports Product/Category/Treasury models directly (`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:17-20`), and Document tax resolver imports Company/Product/TaxConfiguration directly (`apps/api/app/Modules/Document/Application/Services/DocumentLineTaxResolver.php:7-10`).

**Why it matters:** Existing violations are not permission to add more. A central Pricing service that directly reads Product, Category, Company, POS Terminal, User, and Document line state becomes a module-boundary sink.

**Concrete fix/question:** Define a Product-module public service or Shared contract that returns a `DiscountPolicySubject` DTO: product id, category chain ids/caps, cost basis, tax config, company settings, currency. Pricing should consume DTO/contracts, not Product/Category/Company models.

### S4-02 — MAJOR — “Same concern pattern” copies an existing dependency-injection violation

**What’s wrong:** The spec says Document enforcement should use the “same concern pattern” as `AppliesDiscountToleranceRule` (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:94`). That trait uses `app(CompanyContext::class)` inside the method (`apps/api/app/Modules/Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php:51`). `CLAUDE.md` rule 13 says constructor injection only and never use `app()` (`CLAUDE.md:51-52`).

**Why it matters:** Copying that pattern for a new policy service would add more service-locator usage in validation code and make testing/replay harder.

**Concrete fix/question:** Do not copy the trait’s DI pattern. Use FormRequest `withValidator()` plus injected rule objects where possible, or create a dedicated validation rule with constructor-injected dependencies registered through the container.

## 5. Migration Risk

### S5-01 — BLOCKER — `allow_below_cost_sales=true` does not mean “Warn”

**What’s wrong:** The flag exists in the tenant migration (`apps/api/database/migrations/tenant/2025_12_02_064506_add_inventory_costing_settings_to_companies_table.php:14-18`) and on the Company model (`apps/api/app/Modules/Company/Domain/Company.php:101-104`, `apps/api/app/Modules/Company/Domain/Company.php:259-304`). It is consumed by `MarginService::canSellAtPrice()`: if false, below-cost sale is blocked; if true, the user still needs `pricing.sell_below_cost` (`apps/api/app/Modules/Product/Application/Services/MarginService.php:355-371`). The spec maps `allow_below_cost_sales = true` to `discount_floor_mode = Warn` (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:91`).

**Why it matters:** Current true means “below-cost may be allowed if user has permission.” It does not mean “warn only for everyone.” Mapping true to Warn can loosen enforcement and lose the existing permission-gated behavior.

**Concrete fix/question:** Introduce separate migrated state: `Block`, `WarnRequiresOverride`, or `Warn`, or preserve permission gating in Warn mode. Add migration tests for both company flag values and users with/without `pricing.sell_below_cost`.

### S5-02 — MAJOR — Current API may not reflect tenant intent for the flag

**What’s wrong:** `UpdateCompanyRequest` validates margin defaults but not `allow_below_cost_sales` (`apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:50-52`). `CompanyController::update()` only updates validated fields (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:206-215`). `formatCompany()` returns margin defaults but does not return `allow_below_cost_sales` (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:562-599`).

**Why it matters:** A tenant may think the setting was changed in UI, but the backend may have ignored it. Backfilling current DB values could silently map stale defaults rather than user intent.

**Concrete fix/question:** Before migration, audit actual writes and UI behavior for `allow_below_cost_sales`. If it was not round-tripped, do not assume false equals tenant decision. Consider an explicit owner/admin migration prompt or default all tenants to Block with release note.

### S5-03 — MAJOR — Default/backfill semantics are ambiguous

**What’s wrong:** New `discount_floor_mode` defaults to Block (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:43`), but legacy true maps to Warn (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:91`). The spec does not define how to handle missing company rows in tests, historical tenants created before the margin migration, or tenants where the flag was never exposed.

**Why it matters:** Discount policy migration is business-behavior migration, not a pure schema migration. Ambiguous defaults can harden or loosen live selling behavior without explicit tenant consent.

**Concrete fix/question:** Write the migration spec as a truth table: existing flag value, existing permissions, new mode, override requirement, expected user-visible behavior, rollback behavior.

## 6. Missing Edge Cases

### S6-01 — BLOCKER — Tax-inclusive sale price can invalidate floor comparisons

**What’s wrong:** POS discounts are applied against gross inclusive line totals and VAT is extracted afterwards (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:243-318`). The precision contract explicitly warns that POS `unit_price` is TTC while Documents use HT (`docs/architecture/precision-contract.md:73-85`; `CLAUDE.md:77`). The spec’s edge case says sale price semantics stay unchanged and HT/TTC is presentation-only (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:127`).

**Why it matters:** Cost floor is typically net. Comparing a net floor to gross TTC effective price can permit a net loss whenever VAT is material.

**Concrete fix/question:** Policy verdict must state the comparison basis. POS must compare net effective unit price to net floor, or floor must be converted to gross using the product’s tax configuration before local enforcement.

### S6-02 — MAJOR — Variant pricing is not included in the policy contract

**What’s wrong:** Existing Pricing resolution prioritizes variant `price_override` before price lists and base product sale price (`apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:30-39`, `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:55-67`). POS variant sync sends `price_override` (`apps/api/app/Modules/POS/Presentation/Controllers/PosVariantController.php:62-77`). Variant DTO documents `cost_override` as advisory only; inventory WAC remains product-grain (`apps/api/app/Modules/Catalog/Application/DTOs/ProductVariantData.php:14-17`, `apps/api/app/Modules/Catalog/Application/DTOs/ProductVariantData.php:37-38`). The spec’s policy contract only takes `productId`, not `variantId` (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:73`).

**Why it matters:** A variant price override can be lower than the product base sale price. A product-level cap/floor verdict may be wrong for the actual sale line.

**Concrete fix/question:** Add optional `variantId` and `sellableType` to the policy context. Define whether product-grain WAC applies to all variants and how caps inherit when variant price overrides are present.

### S6-03 — MAJOR — Refunds and credit notes must use original sale policy snapshot

**What’s wrong:** The existing Document tolerance concern explicitly bypasses CreditNote because it moves money outward and is not a skimming vector (`apps/api/app/Modules/Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php:15-24`). The spec does not define refund/credit-note behavior for floors edited after the original sale.

**Why it matters:** A refund or credit note after a floor increase could be falsely blocked or reported as a violation if evaluated against current policy. Conversely, allowing edits to old events would violate immutability.

**Concrete fix/question:** Define that returns/credit notes reference the original sale’s policy snapshot and effective price. Do not enforce current floor on money-out reversal flows unless the reversal creates a new sale line.

### S6-04 — BLOCKER — Promotion/discount stacking can push final effective price below floor

**What’s wrong:** The spec explicitly excludes promotion engine changes (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:17`) but claims a discount-time floor guard. Current receipt creation validates manual transaction discount before resolving the full discount breakdown, then calls the orchestrator for promotions + manual + coupon + loyalty (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:374-405`). Line-level discount validation happens before later promotion application (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:997-1053`).

**Why it matters:** A manual discount can individually pass, then coupon/loyalty/promo stacking can make the final effective unit price fall below floor. That is still a below-floor sale.

**Concrete fix/question:** Floor enforcement must run after all discount sources are stacked and allocated to lines. If promotion engine changes are out of scope, the guarantee must be narrowed to “manual discount only,” which contradicts the current stated guarantee.

## 7. Fiscal Hash Chain And Event Immutability

### S7-01 — BLOCKER — Override/floor evidence must exist before sealing

**What’s wrong:** `CLAUDE.md` says events are immutable forever (`CLAUDE.md:36-38`). Fiscal events store canonical bytes plus previous/current hashes (`apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php:43-45`), and the envelope includes `previousHash`, `currentHash`, and verbatim `canonicalBytes` (`apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php:99-103`). The fiscal event model documents the table as immutable chain truth with projection state separate (`apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php:14-21`).

**Why it matters:** If a floor override is approved after the sale is sealed, or if the server later decides an override was required, you cannot add that evidence to the original canonical sale without breaking the hash chain.

**Concrete fix/question:** Require floor verdict and override evidence to be authored before the SALE_RECEIPT fiscal event is sealed, either embedded in canonical payload or linked by a prior immutable audit event whose id is referenced by the sale.

### S7-02 — MAJOR — Re-running policy against current state creates non-replayable violations

**What’s wrong:** The spec says ingestion audit re-runs `resolve()` on synced offline receipts and emits violations without rejecting (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:97`). The ingestion layer verifies hash/linkage and stores/quarantines events without mutating device-authored canonical state (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:165-181`, `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:208-241`). But the spec does not require the receipt to carry the original floor/policy snapshot.

**Why it matters:** If product cost, buffer, regulatory rule, or company mode changed between sale and ingestion, a current-state `resolve()` can produce a violation that was not a violation at sale time, or miss one that was.

**Concrete fix/question:** Store `policy_version`, `policy_as_of`, `floor_price_at_sale`, `floor_basis_at_sale`, `max_discount_percent_at_sale`, and override evidence in the sealed sale payload. Ingestion audit should replay against the sale snapshot and separately report “would violate current policy” only as advisory.

## 8. Multi-Tenant Permission Model

### S8-01 — BLOCKER — Permissions are absent and cache reset is not optional

**What’s wrong:** The spec correctly notes Spatie permission cache reset is owed (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:62`). Spatie teams are enabled with tenant id as team id (`apps/api/config/permission.php:127-134`), but the cache uses one global key and 24-hour expiry (`apps/api/config/permission.php:177-192`). The required new permissions are not in the current seeders; existing names differ (`apps/api/database/seeders/PermissionSeeder.php:138-147`).

**Why it matters:** Under tenant-scoped permissions, stale or missing permissions can make override behavior inconsistent by tenant and process. A deployment that adds checks before cache reset can block all floor overrides or expose cost panels incorrectly.

**Concrete fix/question:** Add migrations/seeders/tests for the new permissions and make `permission:cache-reset` part of the deploy checklist for every tenant DB. Add a test that permission checks fail before reseed and pass after reseed/cache reset.

### S8-02 — MAJOR — Operator permission lookup is only tenant-scoped

**What’s wrong:** `GET /pos/discount-permissions` resolves terminal by current company and code (`apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:65-77`), but if `operator_id` is supplied, it loads a user by tenant and id only (`apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:88-104`). Authorization docs describe multi-company and location-level access as distinct concerns (`docs/conventions/03-AUTHORIZATION.md:141-173`). Cached operators can carry `company_ids`, `terminal_ids`, and `approval_scopes` (`apps/pos/src/lib/db/repositories/operatorPinRepository.ts:6-18`), but the permission endpoint excerpt does not validate operator membership to the selected company/terminal/location.

**Why it matters:** A same-tenant operator from another branch or company can potentially have their discount permissions queried and cached for the wrong terminal context.

**Concrete fix/question:** Validate operator membership against company, branch/location, terminal, and approval scope server-side. Include those scoped claims in the signed/synced permission snapshot and reject overrides when the terminal is not in scope.

### S8-03 — MAJOR — POS sync is company-scoped, not branch/location-scoped

**What’s wrong:** POS sync fetches all active products for the current company (`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:66-72`) and all active terminals for the company (`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:116-132`). The variant POS feed explicitly documents that it is company-scoped and not terminal-scoped (`apps/api/app/Modules/POS/Presentation/Controllers/PosVariantController.php:20-24`). Authorization docs separately recognize location-level access (`docs/conventions/03-AUTHORIZATION.md:156-173`).

**Why it matters:** If floor/cap policy differs by branch or if managers are branch-scoped, a company-wide floor payload can leak policy and enable overrides outside the intended location.

**Concrete fix/question:** Decide whether discount policy is company-wide or branch/terminal-aware. If branch-aware, sync floors/caps only for the authenticated terminal/location and include location access checks. If company-wide, document that branch isolation is intentionally not part of the policy.

## Open Questions For Spec Author

1. Is the first release allowed to claim “never lose on a sale,” or is Phase 1 only advisory until POS device enforcement ships?

2. Are `floor_price` and `effective_max_discount_percent` considered acceptable cost-derived leakage to POS devices? If yes, who has accepted that risk?

3. Should `allow_below_cost_sales=true` migrate to Warn for everyone, or to a permission-gated warn/override mode that preserves today’s `pricing.sell_below_cost` semantics?

4. What exact policy snapshot must be sealed into a POS SALE_RECEIPT so ingestion replay is deterministic after cost/floor/company-mode edits?

5. Does the floor compare against net HT effective unit price, gross TTC effective unit price, or a context-specific basis? What basis should Documents and POS each use?

6. How should transaction-level discounts, coupons, loyalty rewards, and promotions be allocated to lines before floor comparison?

7. Are refunds, returns, exchanges, and credit notes exempt from current floor enforcement, or should they replay the original sale’s policy snapshot?

8. Does floor override require a new scope/event type distinct from `discount_limit_override`? What exact evidence must be present to prove the override was valid offline?

9. Should the discount policy support `variantId` now, given current variant `price_override` support, or should variant pricing be explicitly out of scope?

10. Are FR/TN legal-floor claims authoritative enough to seed enforcement rules? The spec itself labels some legal grounding as “unverified-but-primary-sourced” (`docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md:148-150`), so implementation should not hard-code legal enforcement until counsel/product owner signs off.

