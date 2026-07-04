# Adversarial Spec Review — Margin Hierarchy Design (2026-06-27)

> Reviewer: adversarial design reviewer (read-only). Target spec:
> `docs/superpowers/specs/2026-06-27-margin-hierarchy-design.md`.
> Companion approach review: `docs/superpowers/audits/2026-06-27-margin-hierarchy-approach-review.md`.
> Every claim below is grounded in a file:line opened during this review.

## STEP 2 — proof of reading the real spec

Actual section headers, verbatim:

- `# Spec — Editable, hierarchy-aware product margin (company → category → product)`
- `## 1. Goal & owner requirements`
- `## 2. Locked decisions (owner)`
- `## 3. Non-goals / boundaries (explicit)`
- `## 4. Backend design`
  - `### 4.1 Data model changes`
  - `### 4.2 Enums (no magic strings — rule 9)`
  - `### 4.3 \`MarginResolver\` (new, Product module) — owns the hierarchy`
  - `### 4.4 minimum ≤ target invariant (BLOCKER fix)`
  - `### 4.5 Pricing mode — state machine (SHOULD-FIX fix)`
  - `### 4.6 Validation (rule 19 — percent = 2dp, NOT currency-scaled)`
  - `### 4.7 N+1 / performance (SHOULD-FIX fix)`
  - `### 4.8 Company default exposure`
  - `### 4.9 Category margin persistence`
  - `### 4.10 Module boundaries (SHOULD-FIX fix)`
  - `### 4.11 Queue-safe scale resolution (rule 20 — verified gap)`
  - `### 4.12 Serialization to the frontend`
- `## 5. Frontend design`
  - `### 5.1 Fix the formula + go float-free (BLOCKER fix)`
  - `### 5.2 Editable bidirectional field`
  - `### 5.3 Category margin-defaults UI`
  - `### 5.4 i18n`
- `## 6. Branching & environment`
- `## 7. Data migration / backfill (BLOCKER fix)`
- `## 8. Testing (TDD — write tests first)`
- `## 9. Acceptance bar`

Header inventory matches the sanity anchors exactly (sections 1–9, 4.1–4.12, 5.1–5.4).

Three verbatim quotes from different sections:

1. §2 D3: "Editing `sale_price` directly = **"price is the truth"**: store the price, do **not** pin a margin override."
2. §4.4: "the resolver **clamps** `minimum = min(minimum, target)` and sets `minimum_clamped = true`."
3. §7.2: "Idempotent artisan command `products:backfill-pricing-mode` (runnable per tenant) flips a row to `auto` **only when** `cost_price > 0` AND `sale_price` equals `priceFromMargin(cost_price, effective_target_margin)` at the currency money scale".

---

## PART A — verdicts on the approach-review findings vs the actual spec

| # | Approach-review finding | Verdict | Resolving spec text |
|---|---|---|---|
| 1 | [BLOCKER] `auto` backfill clobbers hand-set prices | **RESOLVED** | §2 D4 (pricing mode + guard), §4.1 ("Products … `pricing_mode` — non-null, **DB default `'manual'`** (the safe default)"), §4.5 (`updateSalePrice` "return early … when `pricing_mode === Manual`"), §7.2 (backfill flips to `auto` only when provably auto-priced; "All other rows stay `manual`"). |
| 2 | [BLOCKER] percent scale contradicts contract / storage | **RESOLVED (reasoned deviation)** | §4.1: "Product margin override columns stay `decimal:3` … the precision contract's requirement is that percent **values** are 2dp … enforced at **validation** … a narrowing migration to `decimal(5,2)` is **rejected** as unnecessary risk". §4.6 regex `/^\d+(\.\d{1,2})?$/`. Sound: contract enforces 2dp on values, storage width ≥ value precision is compliant; verified against `precision-contract.md` (widening non-destructive, narrowing needs per-column pre-check). |
| 3 | [BLOCKER] `minimum > target` band inversion | **RESOLVED** | §4.4 write-time `minimum ≤ target` (422) + resolve-time clamp + `minimum_clamped` flag. Verified the clamp protects `MarginService::getMarginLevel`, which checks minimum before target (MarginService.php:270 then :280). |
| 4 | [BLOCKER] FE must eliminate all float math, not just formula | **RESOLVED (in spec text)** | §5.1: "markup-on-cost … **Float-free**: string props + `lib/decimal.ts` … **No** `parseFloat`, `Number(...)`, native `+ - * /` … or `Math.round`". §8 asserts "**no** `parseFloat`/`Number`/native arithmetic/`Math.round` in the touched components". (Execution caveat — see Part B-5: `ProductPricingCard` is mis-scoped.) |
| 5 | [SHOULD-FIX] N+1 ancestor queries | **RESOLVED** | §4.3 `resolveMany`; §4.7 "loads all needed categories in **one** `whereIn('id', …)` query"; §8 "N+1: `resolveMany` issues a bounded number of queries … (assert query count)". Confirmed the risk is real: `Category::getAncestors()` runs a per-call `whereIn(...)->get()` (Category.php:194). |
| 6 | [SHOULD-FIX] single `source` can't describe split provenance | **RESOLVED** | §4.3 DTO carries per-field `target_source`/`target_source_category_id` and `minimum_source`/`minimum_source_category_id`; supersedes the single-field `getMarginSource` (MarginService.php:360). |
| 7 | [SHOULD-FIX] pricing-mode state machine incomplete | **PARTIAL** | §4.5 adds a full transition table incl. import/upsert and explicit recalc. Contract is complete on paper, but the **enforcement seam is unspecified** and the current write paths cannot realize two of its rules — see Part B-2. |
| 8 | [SHOULD-FIX] module-boundary coupling deepened | **RESOLVED (scoped)** | §4.10: hierarchy "lives entirely in `MarginResolver`"; WAC "continues to call the **public** `MarginService`"; "pre-existing coupling (WAC importing the `Product` model) is **not deepened** and is out of scope". Matches reality: WAC constructor-injects `MarginService` and calls `updateSalePrice` at three sites (WeightedAverageCostService.php:288/580/762). |
| 9 | [SHOULD-FIX] enum coverage for mode/source | **RESOLVED** | §4.2 adds `Product\Domain\Enums\PricingMode` and `MarginSource`, explicitly "**Distinct** from `Catalog\…\PricingMode` and `Workshop\…\BundlePricingMode`". |
| 10 | [NICE] pin fiscal line immutability | **RESOLVED** | §3: "**No fiscal mutation.** … It must **never** rewrite SALE_RECEIPT/document line `unit_price`, canonical fiscal bytes, or hash-chain rows." |

Net: 8 RESOLVED, 1 RESOLVED-with-reasoned-deviation (#2), 1 PARTIAL (#7). The spec folded the approach review thoroughly; the only carried-over gap is the pricing-mode enforcement seam.

---

## PART B — residual problems (verified against code)

### B-1 [SHOULD-FIX] Cost-basis mismatch: FE editor margins use `purchase_price`, backend uses `cost_price`

**Failure scenario.** §5.2 fixes the editor's cost basis to `purchase_price`: "The 'cost' basis is `purchase_price` (HT) … edit **margin** → recompute `sale_price` (HT) = `purchase_price × (1 + margin/100)`". But every server-side margin computation uses `cost_price` (the moving WAC, cast `decimal:6` — Product.php:149):
- `getSuggestedPrice` reads `$product->cost_price` (MarginService.php:153),
- `updateSalePrice` reads `$product->cost_price` (MarginService.php:177),
- `getMarginLevel` reads `$product->cost_price` (MarginService.php:239),
- §7.2 backfill compares to `priceFromMargin(cost_price, …)`.

`purchase_price` (decimal:3, Product.php:143) and `cost_price` (WAC) are different columns and routinely differ. Consequences:
1. A user sets a product to "40%" in the editor → `sale_price = purchase_price × 1.40`. On the next purchase receipt the product (if `auto`) is repriced by WAC to `cost_price × 1.40` (MarginService.php:189 via WeightedAverageCostService.php:288) — the price jumps away from what the editor showed.
2. The editor's color band (computed on `purchase_price`) can disagree with the server band returned by `getMarginLevel`/`canSellAtPrice` (computed on `cost_price`) for the same product — a permission-sensitive divergence (`pricing.sell_below_minimum_margin`, MarginService.php:330).

**Recommended spec change.** Pick ONE canonical cost basis for margin and align FE + BE. Given `cost_price` is the WAC truth that drives auto-repricing, the editor should display/seed margin from `cost_price` (falling back to `purchase_price` only when WAC is absent), OR §5.2 must explicitly document the dual basis and state that auto-reprice and server bands are `cost_price`-based while the editor field is an HT-list convenience. As written, §5.2 and the backend silently disagree.

### B-2 [SHOULD-FIX] §4.5 has no enforcement seam — the current write paths cannot realize two of its rules

**Failure scenario.** `ProductController::update` does a blanket `$productModel->update($validated)` (ProductController.php:689); `ProductService::upsert` builds an explicit attribute array that never includes `pricing_mode` (ProductService.php:54-63). With only mass-assignment:
- **§4.5 rule 2** ("Generic product update / import / upsert with `sale_price`, no `pricing_mode` → `manual`") is **not realized for existing rows** via import/upsert — `pricing_mode` is simply left unchanged. (New imported rows land on the DB default `'manual'` by luck, not by rule.)
- **§4.5 / table row 2** ("`target_margin_override` … persisted **only if ≠ inherited effective**, else NULL") has **no owner**. A blanket mass-assign stores whatever the FE sends; if the user types the inherited value into the margin field, an override is pinned — contradicting the spirit of D2 ("Saving a product without touching the margin keeps it inheriting").

**Recommended spec change.** Name the enforcement seam (a Product-module write service / model observer / controller derivation step) and state which layer (a) derives `pricing_mode` from intent per the rule-1/2/3 priority, and (b) nulls `target_margin_override` when it equals the resolved effective target. The state-machine table is correct; the spec just never says who executes it, and mass-assignment will not.

### B-3 [SHOULD-FIX] 2dp validation vs `decimal:3` cast → self-inflicted 422 on edit-and-resave

**Failure scenario.** Existing product overrides are cast `decimal:3` (Product.php:151-152) and `ProductData` serializes the raw cast value (`(string) $product->target_margin_override`, ProductData.php:86-87), i.e. the FE receives `"30.000"`. §4.6's regex `/^\d+(\.\d{1,2})?$/` rejects three decimals. So: open a pre-existing product that has a stored override, change only the name, and resave with the seeded `"30.000"` → **422 on `target_margin_override`** even though the user never touched it. §5 mandates float-free string components but never requires normalizing the seeded override to 2dp before submit.

**Recommended spec change.** Add an explicit FE rule: seed/normalize percent override fields to 2dp before they enter the form state and before submit (e.g. `bcformat(value, 2)` via `decimal.ts`). Optionally add a backend test that round-trips a `decimal:3`-stored override through the 2dp validator to prove no regression.

### B-4 [NICE-TO-HAVE] §4.11 is correct, but "repricing will throw" overstates the *current* exposure; tighten the eager-load requirement

The no-arg `getScale()` is real and latent: `MarginService::scale()` calls `$this->scaleResolver->getScale()` with no argument (MarginService.php:44), and the interface throws when neither a currency arg nor a bound `CompanyContext` is present (CurrencyScaleResolverInterface.php:25; precision-contract service tier). The fix (source currency from `$product->company->currency`, pass `getScaleSafe($currency, 3)`) is right.

However, I found **no currently-live queued/console path that reaches `updateSalePrice`**: the only `ShouldQueue` listener in Inventory, `ApplyStockAdjustmentsOnCountingCompleted`, drives the *Domain* `StockAdjustmentService` quantity path and does **not** call `WeightedAverageCostService::recordCostAdjustment`/`updateSalePrice` (no such call in StockAdjustmentService.php — only doc references). The three `updateSalePrice` sites (WeightedAverageCostService.php:288/580/762) are reached from in-request controllers (GoodsReceipt/Return/Delivery), where `CompanyContext` is bound. So §4.11 is sound **defensive hardening** — and it becomes load-bearing the moment §4.5's `RecalculateSalePriceCommand` runs from console/queue — but the spec's "repricing will throw" reads as a live crash; it is latent today.

**Recommended spec change.** (a) Reword §4.11 to "is latent today and will throw the moment any of these paths (notably the new recalc command) runs headless." (b) §4.7/§7 mention eager-loading `categories` for `resolveMany`/backfill but §4.11 only mentions eager-loading `company` on the "repricing path" — explicitly require `resolveMany`/backfill to eager-load `company` too, or currency resolution reintroduces an N+1.

### B-5 [NICE-TO-HAVE] `ProductPricingCard` is mis-scoped in §5.1 (not a `PriceInputWithMargin` caller) and has its own formula inconsistency

§5.1 says the float-free rewrite "changes the component's public interface (number → string props); **callers must be updated** (`ProductPricingCard.tsx` and any other consumer)." But `ProductPricingCard.tsx` does **not** consume `PriceInputWithMargin` — it imports only `MarginBadge`/`MarginIndicator` (ProductPricingCard.tsx:3) and does its own float math: `parseFloat` on cost/list/margins (lines 34-38). Worse, it is **internally inconsistent**: `currentMargin` uses markup-on-cost `((listPrice - costPrice) / costPrice) * 100` (line 42) while `suggestedPrice` uses the *gross-margin* inverse `costPrice / (1 - targetMargin / 100)` (line 57) — the same wrong inverse §5.1 is fixing in `PriceInputWithMargin`. Treating it as "a caller needing a prop-type update" understates the work: it needs its own independent float-free rewrite and a formula-consistency fix.

**Recommended spec change.** List `ProductPricingCard.tsx` in §5.1 as a **separate float-free + formula-consistency rewrite target** (markup-on-cost throughout, `decimal.ts`, no `parseFloat`/`toFixed`-based arithmetic), with its own §8 Vitest case, rather than as a downstream caller of `PriceInputWithMargin`.

---

## Where the spec is explicitly sound

- **§4.4 invariant** is genuinely robust: write-time validation + resolve-time clamp + `minimum_clamped` flag, and I confirmed all band logic flows through `getEffectiveMargins` (callers: MarginService.php:154/186/241; PricingController.php:514-525) — there is **no raw `minimum_margin_override` reader bypassing the clamp** in band/permission code (only `ProductData` serialization reads raw overrides, which is provenance display, not band logic).
- **§4.5 priority ordering** correctly disarms the trap the prompt flags: the margin-edit path also sends a recomputed `sale_price`, but rule 1 ("If the payload contains `pricing_mode` → trust it") wins over rule 2, so a margin edit stays `auto`. The contract is correct (only the *enforcement seam* is missing — B-2).
- **§4.10 module boundaries** match the codebase: hierarchy stays in `MarginResolver`, WAC keeps calling the public `MarginService` via constructor injection, no new cross-module model import.
- **§3 fiscal non-goal** is correctly bounded; product-master `sale_price` is read as a forward default only, not a historical rewrite.

---

## Ranked summary

| Finding | Severity | One-line |
|---|---|---|
| B-1 Cost-basis mismatch: editor uses `purchase_price`, backend uses `cost_price` | SHOULD-FIX | Editor-shown margin/band and auto-reprice diverge; auto products jump price on next WAC event. |
| B-2 §4.5 has no enforcement seam | SHOULD-FIX | Blanket mass-assign + `upsert` can't realize rule 2 or null-if-equal; pricing mode/override set only by literal FE payload. |
| B-3 2dp validation vs `decimal:3` cast | SHOULD-FIX | Editing+resaving a pre-existing product 422s on an untouched `"30.000"` override unless FE normalizes to 2dp. |
| B-4 §4.11 overstates current exposure; eager-load company on batch | NICE-TO-HAVE | Fix is correct but latent today (no live queued path to `updateSalePrice`); require `company` eager-load on `resolveMany`/backfill. |
| B-5 `ProductPricingCard` mis-scoped + own gross-margin inverse bug | NICE-TO-HAVE | Not a `PriceInputWithMargin` caller; needs its own float-free + formula-consistency rewrite and test. |

**Overall:** the spec is implementation-ready on all four original BLOCKERs and most SHOULD-FIXes. Before plan/build, close **B-2** (name the write seam — it is the one carried-over gap from approach finding #7) and **B-1** (define the canonical cost basis — currently a silent FE/BE disagreement). B-3/B-4/B-5 are quick spec clarifications.
