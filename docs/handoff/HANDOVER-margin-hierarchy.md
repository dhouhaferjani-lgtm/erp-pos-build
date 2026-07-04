# HANDOVER — Editable, two-way-linked margin with company → category → product hierarchy

> Paste into a fresh session. Read-then-brainstorm-then-writing-plans-then-TDD. Build a **proper margin feature across the whole system**: a default margin per **company**, overridable per **category**, overridable per **product**, with an **editable, two-way-linked margin field** on the product editor. Respect and reflect the full resolution chain everywhere margin is shown/used.

## The hierarchy (owner requirement — must be respected and reflected)
**company default → category override → product override.** Resolution: product override if set, else walk the category tree (nearest ancestor with an override wins), else company default, else hardcoded fallback.

## Status of each level (verified)
| Level | Exists? | Where | Gap |
|---|---|---|---|
| **Company default** | ✅ | `companies.default_target_margin` (default 30%) + `default_minimum_margin` (10% migration / 15% factory). `app/Modules/Company/Domain/Company.php:102-103,260-261`; migration `…2025_12_02_064506_add_inventory_costing_settings_to_companies_table.php:16-17` | **Not exposed via `GET /company`** — `CompanyController::formatCompany()` (~line 562-592) omits both fields. FE `InventorySettings.tsx:22-24` already expects them. |
| **Category override** | ❌ **MISSING — build this** | `app/Modules/Product/Domain/Category.php` has `parent_id`, `path`, `depth`, `default_tax_rate` — **no margin columns** | Add `target_margin_override` + `minimum_margin_override` (nullable, decimal(5,2)). Category is a **nested tree** (`getAncestors()`, `path` = "/1/5/12") → resolution must walk ancestors. |
| **Product override** | ✅ | `products.target_margin_override` / `minimum_margin_override` nullable, cast `decimal:3`. `Product.php:48-49,110-111,151-152`; migration `…2025_12_02_064541_add_cost_and_margin_fields_to_products_table.php:16-17` | Exists; just not yet edited/persisted from the new editor. |

## Backend logic that already exists (extend, don't rebuild)
- **`MarginService`** `app/Modules/Product/Application/Services/MarginService.php`:
  - Margin model = **markup on cost**: `priceFromMargin` (137-145) `price = cost × (1 + margin/100)`; `calculateMargin` (214-229) `margin% = ((sell − cost) / cost) × 100`. Use these — they are the canonical formulas.
  - `getEffectiveMargins(Product)` (103-126) **already does 2-level** (product override → company default → hardcoded 30/15) and returns a `source` ('product'|'company'). **Lines 107-108 contain a literal TODO: "skip category since it doesn't exist … Will implement product → category → company when categories are added."** → This is exactly the seam to fill.
- **Resolver pattern to mirror:** `app/Modules/Procurement/Application/ProcurementPolicyResolver.php:14-20` (persisted override → vertical default). Build the category resolution in the same shape (walk product → nearest category ancestor with override → company default).

## Frontend state
- **Editable field spec already written:** `.superpowers/sdd/task-editable-margin-brief.md` (in the izipos-product-editor worktree). Math (markup on **`purchase_price` HT**, bridged to TTC `sale_price` via tax rate), bidirectional rules, seeding from company default, validation `['sometimes','nullable','numeric','min:0','regex:/^\d+(\.\d{1,2})?$/']` (percent = 2dp, NOT currency-scaled), and a full test list. **Read it — don't re-derive the math.**
- **Current FE is read-only:** `ProductPricingCard.tsx:85-103` (worktree) shows indicative margins as props; no editable input.
- **⚠️ BUG to fix:** `apps/web/src/components/molecules/PriceInputWithMargin/PriceInputWithMargin.tsx` uses the WRONG formula — `((sale − cost)/sale)×100` (gross margin on sell) and inverse `cost/(1−m/100)`. Must be markup on cost: `((sale − cost)/cost)×100` and `cost×(1+m/100)` to match `MarginService`. Fix as part of this work.
- **Float-free math helpers:** `apps/web/src/lib/decimal.ts` (`bcadd/bcsub/bcmul/bcdiv/bccomp`, big.js, half-up). Use these — no `parseFloat` (rule 19).

## Branching (coordination — read carefully)
This spans the whole system AND the new product editor. **Split BE from FE:**
- **Backend slice → branch off `origin/dev`** (`feat/margin-category-override`): the category columns + migration, `MarginService` 3-level resolution (fill the TODO), expose `default_target_margin`/`default_minimum_margin` via `formatCompany()`, and the category-margin edit surface (category settings UI). Independently mergeable; benefits the whole app.
- **FE editable field → on top of the izipos editor branch** `feat/izipos-theme-product-editor` (the pricing card + `PriceInputWithMargin` only exist/were-touched there). It consumes the backend (effective margin + company default) and persists `target_margin_override`. Rebase onto the backend slice (or origin/dev after it lands) so the 3-level `source` is available.

## Scope & method
1. **Brainstorm + writing-plans first** — this is a system feature, not a one-file change. Settle: category-tree resolution semantics (nearest ancestor vs. immediate parent only), what `source` reports for category ('category:<id>'), and where category margins are edited (category settings page).
2. **Backend (TDD):**
   - Migration: `categories.target_margin_override`, `minimum_margin_override` (nullable decimal(5,2)).
   - `MarginService::getEffectiveMargins` → product override → walk `category->getAncestors()` for nearest override → company default → fallback; extend `source`. Tests for each tier + tree walk.
   - Expose company defaults in `formatCompany()` + `typescript:transform`.
   - Persist `target_margin_override` on product create/update (validation per brief); persist category overrides (validation). Custom envelope → `AssertsApiValidation`.
3. **Frontend (TDD):**
   - Fix `PriceInputWithMargin` formula; make margin an **editable** input two-way-linked to `sale_price` via `purchase_price` + tax rate (HT↔TTC), float-free.
   - Seed the field from the **resolved effective margin** (product→category→company), not just company. Show the margin color band vs target/minimum. Guard divide-by-zero (`purchase_price ≤ 0`) and feedback loops.
   - Category settings: an editable target/minimum margin override per category.
4. **Reflect everywhere margin is shown/used** — audit for other read sites (pricing displays, reports, price suggestions, `updateSalePrice`) and make sure they consume the resolved effective margin, not the old 2-level path.

## Worktree env facts (memory `project_izipos_worktree_backend_env_gotchas`)
- Backend tests **BY PATH** only; **never the full suite** (laptop crash). Verify the worktree `vendor` is a real copy, not the symlink (see the opening-balance handover for the one-liner check). Validation envelope `{error:{errors}}` → `AssertsApiValidation`. `typescript:transform` needs `CACHE_STORE=array …`. Reconcile with `origin/dev` before finishing (rule 21). Preflight before commit.

## Acceptance bar
- Effective margin resolves **product → nearest category ancestor → company default**, with a clear `source`, and tests prove each tier (including the tree walk and an un-overridden product inheriting from a grandparent category).
- Product editor margin field is **editable and bidirectional**: edit margin → `sale_price` recomputes; edit `sale_price` → margin recomputes; edit `purchase_price` or tax → `sale_price` recomputes keeping margin; seeded from the resolved effective margin on create. Float-free; divide-by-zero safe.
- Company defaults are exposed to the FE; category overrides are editable; product override persists with 2dp validation.
- `PriceInputWithMargin` uses the correct markup-on-cost formula matching `MarginService`. Preflight green.
