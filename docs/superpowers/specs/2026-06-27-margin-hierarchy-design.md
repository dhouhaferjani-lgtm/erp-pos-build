# Spec — Editable, hierarchy-aware product margin (company → category → product)

> Date: 2026-06-27 · Status: DESIGN (pre-plan) · Supersedes the open question in `MarginService::getEffectiveMargins` (TODO at lines 107-108).
> Companion adversarial review of the approach: `docs/superpowers/audits/2026-06-27-margin-hierarchy-approach-review.md` (all findings folded in below).

## 1. Goal & owner requirements

Build a **proper margin feature across the whole system**:

- A **default target/minimum margin per company**, overridable per **category**, overridable per **product**.
- An **editable, bidirectional margin field** on the product editor.
- The full resolution chain is **respected and reflected everywhere** margin is shown or used.

**Resolution chain (the owner requirement):**
`product override → nearest category ancestor with an override → company default → hardcoded fallback (target 30 / minimum 15)`.

**Margin model = markup on cost** (canonical, already in `MarginService`):
`price = cost × (1 + margin/100)`, `margin% = ((sell − cost) / cost) × 100`.

## 2. Locked decisions (owner)

| # | Decision |
|---|---|
| D1 | Ship the **full feature**: backend slice + frontend editable field. |
| D2 | Saving a product **without touching the margin keeps it inheriting** (`target_margin_override` stays NULL). |
| D3 | Editing `sale_price` directly = **"price is the truth"**: store the price, do **not** pin a margin override. |
| D4 | Add a per-product **pricing mode** (`auto` \| `manual`) + a guard so manual prices survive cost changes. Industry-aligned (Odoo/Lightspeed/NetSuite). |

## 3. Non-goals / boundaries (explicit)

- **No fiscal mutation.** Changing product-master `sale_price`, margins, or modes affects **future** price selection/defaults only. It must **never** rewrite SALE_RECEIPT/document line `unit_price`, canonical fiscal bytes, or hash-chain rows. (Verified: receipt lines persist request `unit_price`; finalization hashes are immutable.)
- No change to the markup-on-cost formula or `MARGIN_SCALE = 2`.
- No new pricing UI beyond the product editor margin field and the category margin-defaults section. (Bulk re-pricing UI is out of scope; the backend "recalculate now" command is in scope as the seam.)
- No promotions/pricelist/customer-tier pricing (separate concern).

---

## 4. Backend design

### 4.1 Data model changes

**Categories** — new migration (tenant): `categories.target_margin_override`, `categories.minimum_margin_override` — both `nullable decimal(5,2)`, cast `decimal:2`, added to `$fillable`.

**Products** — new migration (tenant): `products.pricing_mode` — non-null, **DB default `'manual'`** (the safe default; see §4.5 backfill), cast to the new `PricingMode` enum, added to `$fillable`.

**Product margin override columns stay `decimal:3`** (existing, post widen-to-scale-3 migration). Deliberate: the precision contract's requirement is that percent **values** are 2dp and not currency-scaled — enforced at **validation** (§4.6). Storage width ≥ value precision is compliant; a narrowing migration to `decimal(5,2)` is **rejected** as unnecessary risk (would require per-tenant pre-checks / truncation handling per `precision-contract.md:20`). Category columns are new, so they are created at the correct `decimal(5,2)` directly.

### 4.2 Enums (no magic strings — rule 9)

- `App\Modules\Product\Domain\Enums\PricingMode`: `Auto = 'auto'`, `Manual = 'manual'`. **Distinct** from `Catalog\…\PricingMode` and `Workshop\…\BundlePricingMode` (different domains; do not reuse).
- `App\Modules\Product\Domain\Enums\MarginSource`: `Product = 'product'`, `Category = 'category'`, `Company = 'company'`, `Default = 'default'`. Used for per-field provenance and serialized to the FE.

### 4.3 `MarginResolver` (new, Product module) — owns the hierarchy

New `App\Modules\Product\Application\Services\MarginResolver` (constructor-injected, rule 13). It is the **single owner** of company→category→product resolution. `MarginService` delegates to it; no other module learns the hierarchy.

**`resolve(Product $product): EffectiveMargins`** returns a DTO:

```
EffectiveMargins {
  target_margin: numeric-string (2dp)
  minimum_margin: numeric-string (2dp)            // post-clamp (see §4.4)
  target_source: MarginSource
  target_source_category_id: int|null             // set only when target_source === Category
  minimum_source: MarginSource
  minimum_source_category_id: int|null            // set only when minimum_source === Category
  minimum_clamped: bool                            // true if minimum was clamped down to target
}
```

Resolution algorithm (target and minimum resolved **independently**, per D-owner, then clamped):

1. **product override?** → source `product`.
2. else walk **product's own category first, then `category->getAncestors()` reversed (nearest ancestor first)**; first category with a non-null override for that field wins → source `category`, record its id.
   - Note: `Category::getAncestors()` returns root→…→parent and **excludes self**; the resolver must prepend the product's own category and iterate nearest-first.
3. else **company default** (`default_target_margin` / `default_minimum_margin`) → source `company`.
4. else hardcoded **30 / 15** → source `default`.

**`resolveMany(Collection<Product>): array<product_id, EffectiveMargins>`** — batch variant for list/report contexts (§4.7).

### 4.4 minimum ≤ target invariant (BLOCKER fix)

- **Write-time:** when a single level (company, category, or product) sets **both** target and minimum in the same request, validate `minimum ≤ target` (422 otherwise).
- **Resolve-time clamp:** because split-level resolution can still yield `minimum > target` (e.g. target from product, minimum from a category), the resolver **clamps** `minimum = min(minimum, target)` and sets `minimum_clamped = true`. This guarantees the band logic in `MarginService::getMarginLevel` (checks minimum before target) can never invert. The FE surfaces a subtle "minimum adjusted to target" hint when `minimum_clamped`.

### 4.5 Pricing mode — state machine (SHOULD-FIX fix)

`updateSalePrice(Product)` gains **one guard**: return early (no reprice, no write) when `pricing_mode === Manual`. `auto` products keep auto-repricing from the resolved effective target margin (this is how inheritance flows downward).

**API contract — mode is driven by explicit client intent, never inferred from "did `sale_price` change".** This is critical because the margin-edit path *also* sends a recomputed `sale_price`. Rules, in priority order, applied on product create/update:
1. If the payload contains `pricing_mode` → trust it (authoritative). The editor always sends it: margin-edit sends `pricing_mode: auto` (+ `target_margin_override` when changed); price-edit sends `pricing_mode: manual` (+ `sale_price`).
2. Else if `sale_price` is present **without** `pricing_mode` (generic API / import / upsert) → treat as a direct price intent → `manual`.
3. Else (neither) → mode unchanged.

| Trigger | `pricing_mode` sent / resulting | `target_margin_override` | `sale_price` |
|---|---|---|---|
| Product create via editor, margin untouched | `auto` | NULL | computed from effective target |
| Editor: user edits **margin field** | `auto` (re-arm) | persisted **only if ≠ inherited effective**, else NULL | recomputed from margin |
| Editor: user edits **`sale_price`** directly | `manual` | unchanged (stays NULL if it was) | as entered |
| Generic product update / import / upsert with `sale_price`, no `pricing_mode` | `manual` (rule 2) | unchanged | as entered |
| WAC purchase/return/adjustment (`updateSalePrice`) | unchanged | unchanged | recomputed **iff `auto`**; skipped iff `manual` |
| Category/company default change | unchanged | unchanged | not eager-recomputed (auto products recompute on their next cost event or via explicit recalc) |
| **Explicit "recalculate price now"** command | set to `auto` | unchanged | recomputed from effective target; **may reprice `manual` products** because the user explicitly asked |

**Explicit recalc seam:** a Product-module application command (`RecalculateSalePriceCommand` / public service method) that recomputes from the effective target and sets mode `auto`. This is the *only* sanctioned way to reprice a `manual` product. (Wiring it to a bulk UI is out of scope; the command + a covering test are in scope.)

**Re-arm rule:** `auto` is re-armed by editing the margin field or by the explicit recalc command. There is no silent path out of `manual` — acceptable and intended (manual = "I chose this price").

**Enforcement seam (required — the contract has no home today).** `ProductController::update` blanket mass-assigns `$validated` (ProductController.php:689) and `ProductService::upsert`/import never set `pricing_mode` (ProductService.php:54-63). The §4.5 rules must be realized in **one** place, not scattered: a `ProductPricingIntentService` (or an explicit method on `ProductService`) that, given the validated payload, decides `pricing_mode` and whether to null/persist `target_margin_override` (compare to the inherited effective margin via `MarginResolver`). `pricing_mode` and the override columns must be **removed from blind mass-assignment** and routed through this seam on both create and update (and import/upsert → `manual`). Without this, editing margin to a value equal to the inherited effective would wrongly pin an override, and imports would never become `manual`.

### 4.6 Validation (rule 19 — percent = 2dp, NOT currency-scaled)

Percent fields (product/category/company target & minimum overrides):
`['sometimes','nullable','numeric','min:0','regex:/^\d+(\.\d{1,2})?$/']`.

`pricing_mode` (when present on product write): `['sometimes', new Enum(PricingMode::class)]`.

Custom validation envelope is `{error:{errors}}` → tests use `Tests\Traits\AssertsApiValidation`.

### 4.7 N+1 / performance (SHOULD-FIX fix)

`ProductController::index` paginates up to 2000 rows. List/report code paths must use `MarginResolver::resolveMany()`, which:
1. collects distinct `category_id` + their `path` ancestor ids across the page,
2. loads all needed categories in **one** `whereIn('id', …)` query,
3. resolves each product in memory.

Single-product paths (editor, single product show) may use `resolve()`. `getEffectiveMargins(Product)` keeps working (delegates to `resolve`) but must **not** be called per-row in high-cardinality loops. `resolveMany()` and the §7 backfill must **eager-load `company`** (for currency/defaults) and the needed categories up front — never lazy-load per row (this also satisfies the §4.11 currency requirement off-context).

### 4.8 Company default exposure

`CompanyController::formatCompany()` adds `default_target_margin` and `default_minimum_margin` (formatted 2dp, `PERCENT_SCALE = 2` already present). Run `CACHE_STORE=array php artisan typescript:transform` to regenerate FE types. (`InventorySettings.tsx` already expects these keys.)

### 4.9 Category margin persistence

Category create/update endpoint (`Create/UpdateCategoryRequest`) accepts and persists the two override fields with §4.6 validation **and** the §4.4 write-time `minimum ≤ target` cross-field rule (when both are present in the request → 422 otherwise). Company-default writes (`InventorySettings`) enforce the same cross-field rule.

### 4.10 Module boundaries (SHOULD-FIX fix)

- Hierarchy resolution lives entirely in `MarginResolver` (Product module).
- Inventory's `WeightedAverageCostService` continues to call the **public** `MarginService` (already constructor-injected) — it gains **no** knowledge of categories or pricing-mode internals; the `manual` skip is internal to `updateSalePrice`.
- The pre-existing coupling (WAC importing the `Product` model) is **not deepened** and is out of scope to refactor here.

### 4.11 Queue-safe scale resolution (rule 20 — verified gap)

`MarginService::scale()` currently calls `$this->scaleResolver->getScale()` **with no argument** (MarginService.php:44). A no-arg `getScale()` **throws** when there is no `CompanyContext` (queued/console contexts) per rule 20. `updateSalePrice` is reachable from `WeightedAverageCostService` (3 call sites); if any of those run in a queue/console worker, repricing will throw.

Requirement: all scale resolution inside `MarginService`/`MarginResolver` that can run on the WAC/repricing path must be **currency-aware and context-safe**. Note: **`Product` has no `currency` attribute** — currency lives on the company (`Company::$currency`, ISO 4217). So the currency must be sourced from `$product->company->currency` (eager-load `company` on the repricing path) and passed explicitly: `getScaleSafe($product->company->currency, 3)` (or `getScale($currency)` when the currency is already known), **never** a bare no-arg `getScale()`. The `MARGIN_SCALE = 2` percent scale is unaffected (percent is not currency-scaled). Covered by the §8 test that clears `CompanyContext` before calling `updateSalePrice`/`resolve`.

### 4.12 Canonical cost basis (consistency fix — supersedes the handover's "purchase_price" basis)

The server margin system computes **everything on `cost_price`** (the WAC, `decimal:6`): `getSuggestedPrice` (MarginService.php:153), `updateSalePrice` (:177), `getMarginLevel` (:239), and the Pricing controller. The handover proposed the editor compute margin on `purchase_price` — that would make the editor's margin/colour **disagree** with the server's permission-sensitive bands and auto-reprice for any product that has stock (where WAC `cost_price` ≠ last `purchase_price`).

**Decision: the margin basis is `cost_price` (WAC) when `cost_price > 0`, else `purchase_price` as a fallback** (a brand-new product has `cost_price = 0` — default `0`, not seeded from `purchase_price` — until its first stock movement establishes WAC). This single basis is used **identically** by the editor and the server, satisfying the owner's "respect the logic everywhere" requirement.

Consequences for the editor (amends §5.2): the bidirectional field operates on the resolved **cost basis**, not raw `purchase_price`. For an established product, editing `purchase_price` does **not** move `sale_price` (WAC drives cost, not last purchase price); it only does so for a new product where the basis fallback is `purchase_price` and `cost_price` is still 0. New-product previews on `purchase_price` are intentional UX; once stock arrives, editor and server align on WAC.

### 4.13 Serialization to the frontend

The resolved `EffectiveMargins` (target, minimum, **per-field** `target_source`/`target_source_category_id`, `minimum_source`/`minimum_source_category_id`, `minimum_clamped`) and the product's `pricing_mode` must be **serialized to the FE** via the product show/detail payload (extend `ProductData` DTO) and the company defaults via `formatCompany`. Run `CACHE_STORE=array php artisan typescript:transform` so the generated types carry these fields — otherwise the editor's seeding, provenance display, and `minimum_clamped` hint cannot render. `pricing_mode` is an explicit, validated field on `Store/UpdateProductRequest` (§4.6), sent explicitly by the editor (§4.5), never inferred from a changed price.

**2dp round-trip (verified edge):** product override columns are `decimal:3`, so existing values serialize as e.g. `"30.000"`, but the §4.6 validation regex accepts only 2dp → blindly re-POSTing a loaded product would 422. The editor must **normalize override values to 2dp on load** (`formatPercent`) so untouched re-saves send `"30.00"`. (Existing stored values are 2dp-valued — the columns were originally `decimal(5,2)`, later widened to 3 — so normalization is lossless.)

---

## 5. Frontend design

### 5.1 Fix the formula + go float-free (BLOCKER fix)

`PriceInputWithMargin.tsx` currently uses **gross-margin-on-sale** (`((sale−cost)/sale)×100`, inverse `cost/(1−m/100)`) and `parseFloat`/`Math.round`/`number` props. Rewrite to:

- **markup-on-cost**: `margin% = ((sale−cost)/cost)×100`, `sale = cost×(1+m/100)` — matching `MarginService`. `cost` = the canonical cost basis (§4.12), **not** raw `purchase_price`.
- **Float-free**: string props + `lib/decimal.ts` (`bcadd/bcsub/bcmul/bcdiv/bccomp`, half-up). **No** `parseFloat`, `Number(...)`, native `+ - * /` on money/percent, or `Math.round` in any touched pricing component.
- This changes the component's public interface (number → string props); **callers must be updated**.
- **`ProductPricingCard.tsx` is a SEPARATE target, not merely a caller** (verified): it has its own `parseFloat` usage and an internal formula inconsistency (markup-on-cost at line 42 vs a gross-margin inverse at line 57). It needs its own float-free + formula-correction rewrite **with its own test**, not just a prop-type update.

### 5.2 Editable bidirectional field

On the product editor pricing card:

- The cost basis is the **canonical cost basis** (§4.12): `cost_price` (WAC) when `> 0`, else `purchase_price`. `sale_price` in the **product-master / B2B editor context is net/HT** (NOT the TTC POS cart price — confirmed; do not apply POS TTC semantics here). Margin is computed on HT cost basis vs HT sale.
- Bidirectional (let `basis` = canonical cost basis):
  - edit **margin** → recompute `sale_price` (HT) = `basis × (1 + margin/100)`.
  - edit **`sale_price`** → recompute displayed margin = `((sale − basis)/basis)×100`.
  - edit **`purchase_price`** → recompute `sale_price` keeping margin **only when `purchase_price` is the active basis** (i.e. `cost_price = 0`, new product); for an established product (basis = WAC `cost_price`) editing `purchase_price` does not move `sale_price` (§4.12).
- **Seed** the margin field from the resolved **effective** margin (`target_margin` from the new API payload), not just company default.
- **Guards:** divide-by-zero when `basis ≤ 0` (margin display = blank/"—", no recompute); feedback-loop guard (don't re-fire recompute on programmatic value set).
- **Color band** vs resolved target/minimum (reuse existing green/yellow/orange/red semantics). Show provenance (e.g. "from category X" / "company default") from the per-field source. Surface `minimum_clamped` hint when set.
- Persistence intent follows §4.5: editing margin → sends `target_margin_override` (+ implies `auto`); editing price → sends `sale_price` only (+ implies `manual`); untouched → sends neither override (stays inheriting).

### 5.3 Category margin-defaults UI

Add a "Margin defaults" section to `CategoryForm.tsx`: optional target & minimum override inputs (2dp), with the same float-free input treatment and inline `minimum ≤ target` validation feedback. Empty = inherit.

### 5.4 i18n

All new labels/hints via `t()` (rule 11); add keys to the relevant namespaces (`inventory`/`categories`/`settings`). Onboarding-safe (no hard failure if a namespace 403s during onboarding).

---

## 6. Branching & environment

- **Backend slice** → branch `feat/margin-category-override` off `origin/dev` (independently mergeable; benefits the whole app). Includes migrations, `MarginResolver`, enums, `MarginService` delegation + `manual` guard, recalc command, company exposure + `typescript:transform`, category persistence, backfill command.
- **Frontend field** → on `feat/izipos-theme-product-editor`, rebased onto the backend slice (or `origin/dev` after it lands) so the per-field `source` + effective margin are available.
- Work in a `git worktree` (rule 21). Verify worktree `vendor` is a real copy, not the symlink. Tests **by path only** (never full suite — laptop crash). `typescript:transform` needs `CACHE_STORE=array`. Reconcile with `origin/dev` before finishing; preflight before commit.

## 7. Data migration / backfill (BLOCKER fix)

1. Schema migration adds `products.pricing_mode` with **DB default `'manual'`** → zero existing prices can be auto-clobbered immediately.
2. Idempotent artisan command `products:backfill-pricing-mode` (runnable per tenant) flips a row to `auto` **only when** `cost_price > 0` AND `sale_price` equals `priceFromMargin(cost_price, effective_target_margin)` at the currency money scale (i.e. provably auto-priced). It resolves each product's effective target via `MarginResolver::resolveMany` (batch, to avoid per-row ancestor queries). All other rows stay `manual`. Logs counts + sampled affected ids; safe to re-run. Note: flipping a currently-matching row to `auto` is safe by construction — its price already equals the effective-target computation, so the next cost-driven reprice keeps it consistent; a non-matching row stays `manual` and is preserved. Scale resolution uses the entity currency (§4.11), not a no-arg `getScale()`.
3. New products created through the editor set mode explicitly per §4.5 (untouched-margin save → `auto`).

## 8. Testing (TDD — write tests first)

**Backend (PHPUnit, by path):**
- Resolver: product override wins; category override (immediate parent); **grandparent** category inheritance (tree walk); company default; hardcoded fallback. Each for target and minimum **independently**.
- Split-level: target from product + minimum from category → `minimum_clamped` true and `minimum == target`; per-field `*_source`/`*_source_category_id` correct.
- Write-time `minimum ≤ target` invariant (company/category/product) → 422 via `AssertsApiValidation`.
- `updateSalePrice`: `auto` reprices; `manual` is skipped (no write). Called from a WAC context with **no CompanyContext** → pass explicit currency to scale resolution (rule 20); `CompanyContext::clear()` in setUp where projections/queues are simulated.
- `RecalculateSalePriceCommand`: reprices a `manual` product and sets `auto`.
- Backfill command: auto-priced row → `auto`; hand-set row → `manual`; `cost_price = 0` row → `manual`; idempotent on re-run.
- Company exposure: `default_target_margin`/`default_minimum_margin` present in `formatCompany` output.
- Category persistence + validation.
- N+1: `resolveMany` issues a bounded number of queries for an N-product page (assert query count).
- Enum casts present; no magic strings.

**Frontend (Vitest):**
- Bidirectional recompute both directions (margin↔price↔purchase_price), markup-on-cost values exact.
- Seeding from effective margin (product/category/company source).
- Editing price → emits `manual` intent + no override; editing margin → emits override + `auto`; untouched → emits neither.
- Divide-by-zero (`purchase_price ≤ 0`) safe; no feedback loop.
- Assert **no** `parseFloat`/`Number`/native arithmetic/`Math.round` in the touched components (lint + unit).
- `minimum_clamped` hint renders.

## 9. Acceptance bar

- Effective margin resolves **product → nearest category ancestor → company default → fallback**, target & minimum independently, with **per-field provenance** and a `minimum ≤ target` guarantee; tests prove each tier incl. grandparent inheritance and the split-level clamp.
- Product editor margin field is **editable and bidirectional**, seeded from the **resolved effective** margin, float-free, divide-by-zero safe; editing price sets `manual`, editing margin sets `auto`+override-if-changed, untouched stays inheriting.
- A `manual` product's price **survives** WAC/cost changes; an `auto` product reprices; backfill never clobbers a historical hand-set price.
- Company defaults exposed to FE; category overrides editable; product override persists with 2dp validation.
- `PriceInputWithMargin` uses correct markup-on-cost formula; no fiscal/document/receipt bytes touched. Preflight green.
