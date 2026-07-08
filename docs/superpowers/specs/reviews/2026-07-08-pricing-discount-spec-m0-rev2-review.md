# M0 Adversarial Review — Pricing/Discount Spec **Rev 2** (fresh review before Codex dispatch)

**Date:** 2026-07-08
**Reviewers:** 4 parallel adversarial agents (cost/margin · authz/tenancy · price-resolution/API · architecture/precision/migration), each verifying Rev 2 against current code with `file:line`, Phase-1 (Web, advisory) scope only.
**Target:** `docs/superpowers/specs/2026-07-08-product-pricing-discount-policy-design.md` Rev 2 (`9c1c13634`).
**Outcome:** **CHANGES-REQUESTED → Rev 3 required before dispatch.** Foundations are solid (precision contract clean, permission catalog accurate, citation discipline unusually good). But an agent handed Rev 2 as-is would ship a production behavior change on the authoritative B2B document path, build a second margin-guard system that fights the existing one, and hit several boundary/target ambiguities.

---

## What held up (verified accurate — do NOT re-litigate)
- **Precision (clean).** `CurrencyScale::bcformatStrict(string,int)` (`app/Shared/Domain/CurrencyScale.php:130`); `CurrencyScaleResolverInterface::getScale(?string)` (`app/Shared/Contracts/CurrencyScaleResolverInterface.php:25`); no-arg `getScale()` genuinely throws `UnboundCompanyContextException` (`CurrencyScaleResolver.php:36-58`); `getScaleSafe` fallback confirmed. Percent-2dp vs money-at-resolver-scale consistent.
- **Permission catalog.** `pricing.view_cost_prices` + `pricing.sell_below_cost` seeded (`PermissionSeeder.php:145-146`); no `pricing.view_costs`.
- **WAC facts.** WAC on `products.cost_price` written by `WeightedAverageCostService`; `last_purchase_cost`/`cost_updated_at`; migration `2025_12_02_064541`. WAC is **net cost incl. non-recoverable VAT** (via `LandedCostService`), so the net-to-net premise holds. Column is now `decimal(19,6)` (widened `2026_05_30`).
- **Migration facts.** `allow_below_cost_sales` never validated by `UpdateCompanyRequest` nor returned by `formatCompany`, seeded `default(false)` → "was never user-set, default all tenants" is factually justified.
- **Concern to avoid.** `AppliesDiscountToleranceRule` uses `app(CompanyContext::class)` (`:51`) — correctly flagged don't-copy.
- **Variant / discount facts.** `PricingService` variant `price_override` first (`:55-67`); POS caps most-restrictive-wins, reason>10% (`DiscountCalculationService`), already bc/string (NOT float debt); modal `parseFloat` + float `DiscountController` (`:221-230`) confirmed.

---

## Converged themes (fix in Rev 3)

### THEME 1 — Parallel margin-floor system collides with the existing one *(the central flaw — needs owner decision)*
Flagged independently by **3 of 4** reviewers.
- **[BLOCKER B1]** The migration does **not** preserve behavior. Old `allow_below_cost_sales` gates selling **below WAC** (LEVEL_RED, `MarginService.php:355-371`). The new floor = `WAC × (1 + margin_floor_buffer_percent/100)` with **default buffer 10.00** — a price *above* WAC. Mapping `false → Block` (§5) therefore **blocks the entire `WAC … WAC×1.10` band** every tenant previously sold freely. Because §4.4.1 makes Documents authoritative/blocking in Phase 1, **every B2B invoice/order line under 10% markup returns 422 at go-live** — brand-new blocking on a surface that never enforced a margin floor. "Behavior preserved" is false.
- **[MAJOR A/M2]** `margin_floor_buffer_percent` (default 10, floor `WAC×1.10`) **duplicates** `companies.default_minimum_margin` (default 10, same formula, `2025_12_02_064506:17`), which already drives `MarginResolver` → `MarginService` LEVEL_ORANGE gated by `pricing.sell_below_minimum_margin` (`MarginService.php:43,316-321`). Two "minimum margin" numbers, different math (markup buffer vs margin%), different permission, no statement of which is authoritative.
- **[MAJOR B]** New Document 422 floor is not reconciled with the **existing** `canSellAtPrice` margin gate already wired (advisory) into B2B line entry at `LineEntryController.php:131`, `PricingController.php:527`. Same economic event, two paths, three permissions (`sell_below_cost` / `sell_below_minimum_margin` / new `override_discount_floor`).
- **Internal contradiction:** §0 Decision 1 says "Phase 1 is advisory"; §4.4.1 says "Documents (Phase 1) authoritative/blocking." The Document blocking is the risk surface.
- **Also (M6):** verdict fields `policyVersion`/`policyAsOf` have **no Phase-1 source** (only unrelated Fiscal-payload `policy_version` exists) — define a Phase-1 origin or defer.

### THEME 2 — New read endpoints leak a cost lower-bound, ungated
- **[MAJOR F3/leak]** `POST /pricing/discount-policy` returns `floorPriceNet` (derived from WAC) with only `['api','auth:sanctum',SetPermissionsTeam]` — **no `can:` gate**, and §4.8's stack drops `EnforceTokenTenantClaim` that every live Pricing route carries (`Pricing/routes.php:19`). Any authenticated tenant user could infer cost. → gate `can:pricing.view_cost_prices`, add `EnforceTokenTenantClaim`, register in the **existing** Pricing group.
- **[MAJOR F4]** Extending `GET /pos/discount-permissions` (`POS/routes.php:176`, no `can:` guard) with a floor field would expose cost to cashiers — contradicts §1 "no POS cost visibility" and "POS shows nothing new in Phase 1." → the floor/verdict extension is **Phase-2 only**; if any Phase-1 change, additive string keys only (safe: device parses via schema-less TS interface `discountApi.ts:3-22`), never mutate existing float keys.

### THEME 3 — New permissions have no role assignment → silent 403/blocked demo
- **[BLOCKER F1]** `pricing.override_discount_floor` added only to `PermissionSeeder` reaches **admin only** (`RolesAndPermissionsSeeder.php:423` `Permission::all()`); every other role needs an explicit line. A `manager` doing a below-floor B2B invoice gets a hard 422 with no override. → grant to `manager` (+ roles holding `pos.approve_discount_limit_override`), with a DENY-path test.
- **[MAJOR F2]** `pricing.view_cost_prices` is currently **admin-only** and **not enforced on the web today** (strip renders unconditionally, `ProductForm.tsx:918-925,1327`). Newly gating the marquee panel behind it hides WAC/margin from `manager` and any non-admin demo account. → grant to `manager`; call out this is a *new* enforcement point, confirm the demo account's role.
- **[MAJOR F6]** Deploy owes `permission:cache-reset` **per tenant DB** (global tenant-blind cache key `config/permission.php:192`, 24h TTL) — the §7 "fails before reseed, passes after" test must be a merge blocker.

### THEME 4 — Verdict cap sourcing crosses the module boundary
- **[BLOCKER]** §4.1 verdict `maxDiscountPercent = min(user, terminal, product→category→company)`, but user/terminal caps live in the **POS module** (`Terminal.max_discount_percent`, `DiscountCalculationService:203-236`), while §4.2 wires only a Product-module provider. Honoring `min()` forces Pricing→POS coupling (violates §4.2 / rule 6). Phase-1 web has no terminal/operator context anyway. → **Phase-1 web verdict = product→category→company only**; the user/terminal `min()` belongs to the Phase-2 device.
- **[MAJOR M4]** `DiscountCapResolver` must operate **only** on the `DiscountPolicySubject` DTO (category lives in Product module); the Product-module provider assembles the full category-chain caps into the DTO, mirroring `MarginResolver.php:198-208`.
- **[MAJOR M5]** Pricing already imports Product/Company models (`PricingService.php:7,10`, `PricingController.php:15,16`) — the agent will copy the wrong pattern. State the new service is held to DTO-only regardless of neighbors; don't bolt the advisory endpoint onto the Product-coupled `PricingController`.

### THEME 5 — Document enforcement is mis-targeted and an N+1
- **[MAJOR M3]** There are **no "invoice/order FormRequests."** Documents are unified: hook `CreateDocumentRequest` / `UpdateDocumentRequest`, gated by route-name inference (`invoices.*`/`orders.*`) exactly like `AppliesDiscountToleranceRule::isPaymentDueDocumentRoute()`. `CreateDocumentRequest` already constructor-injects services and already has a `withValidator()->after()` closure (`:181+`) — the new floor check rides that closure with its rule injected via the request constructor (this is what avoids `app()`). Name these files or the agent hunts for nonexistent ones.
- **[MAJOR N+1]** Per-line subject resolution on a 30–50-line document = 30–50 category-chain walks. `MarginResolver` already solved this with `resolveMany()` (`:51-78`). → the provider must expose a **batch** method; the Document rule resolves all lines in one pass.

### THEME 6 — `sale_price` HT/TTC basis is undefined in the codebase
- **[MAJOR C]** Backend auto-pricer writes `sale_price = cost × (1+target/100)` with **no tax → HT** (`MarginService::updateSalePrice:235,248`), but `ProductForm` reads/writes `sale_price` as **TTC** (`:761,773`). For an auto-priced product the advisory panel would render margin ≈ wrong-by-VAT-rate. §6's "single stored price" assertion has no backing. → Rev 3 must **pin the canonical basis (recommend HT** — matches backend + WAC) and flag the `updateSalePrice`↔`ProductForm` disagreement as an in-scope fix; the panel/traffic-light must use the same tax resolution the backend contract uses (`default_tax_configuration_id`, fallback `tax_rate`), not `tax_rate`-only.
- **[MINOR]** Do **not** reuse `MarginService::getMarginLevel` for the panel's no-cost case — it returns LEVEL_GREEN with "No cost data" (`:293-296`), which would paint a costless product green; the spec wants "—"/floor-disabled.

### THEME 7 — Citation / rationale corrections (mechanical)
- `MarginResolver` cascade is `:187-209`, not `:200-224` (file ends at 210).
- `PermissionSeeder` perms at `:145-146`, not `138-147`.
- **S3-03 rationale is factually wrong:** `PricingService` converts percent at **scale 4** (`:305,351`), not currency scale — no corruption. The *conclusion* (don't reuse) stands, but for the real reasons: (a) those are money-discount-amount helpers, not percent-cap/net-floor helpers; (b) `PricingService::scale()` uses **no-arg** `getScale()` (`:24-27`) that throws in queue/ingestion (the true S3-02 hazard). Restate it.
- `Shared/Contracts/` literal path is **`app/Shared/Contracts/`** (e.g. `ProductServiceInterface`, `CatalogLookupInterface`) — there is no `app/Modules/Shared/`. Pin it so the agent doesn't create the wrong dir. The DTO-via-public-service pattern itself is idiomatic.
- WAC wording: "net cost incl. non-recoverable tax," not "WAC_net (HT)"; note cost basis is 6dp. Profit-buffer contribution is **skipped** (not zero) when WAC ≤ 0 so a 0 never masquerades as a real floor.

---

## Rev 3 reconciliation plan
- **THEME 1 → owner decision** (floor mechanism + Phase-1 Document enforcement level + migration default). Everything else is a mechanical/architectural edit I can make deterministically once Theme 1 is settled (it reshapes §3.1/§3.3/§4.1/§4.3/§4.4/§5).
- Themes 2–7 → direct spec edits (gating, role grants, DTO-only cap resolver + batch, Document request targeting, HT basis, endpoint scope, citation/rationale fixes).
