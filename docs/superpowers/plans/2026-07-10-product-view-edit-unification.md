# Product View/Edit Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Do not dispatch parallel work against `ProductForm.tsx`.

**Goal:** Render the same canonical product sections in view and edit modes, in the order `hero`, `general`, `pricing`, `inventory`, `suppliers`, `media`, while preserving edit behavior and rendering pricing exactly once.

**Architecture:** A new `features/products/sections` package owns the ordered registry, shared section shells, discriminated view/edit adapter contracts, and section components. `ProductForm` creates the edit adapter; `ProductDetailPage` creates the view adapter. Automotive and edit-only pharmacy, loyalty, and variants remain page-owned extension sections, so this FE-only phase does not change backend DTOs.

**Tech Stack:** React 19, TypeScript strict, react-hook-form 7, TanStack Query 5, Vitest/Testing Library, Tailwind 4 design tokens, react-i18next.

## Global Constraints

- Work only on `feat/product-view-edit-unify` in `/Users/houssamr/Projects/syneriva/apps/erp.viewunify`; do not merge to `dev` or push `origin/dev`.
- Tests run by explicit path only. After a Vitest hang, inspect `ps aux | grep 'node (vitest'` and terminate workers before continuing.
- Every production change follows red-green-refactor. Every M1/section extraction receives `claude -p --model claude-opus-4-8` adversarial review; reconcile all BLOCKER/MAJOR findings before continuing.
- Shared section order is exactly `hero`, `general`, `pricing`, `inventory`, `suppliers`, `media`.
- Pharmacy, loyalty, and variants remain edit-only. Automotive stays edit-by-vertical and view-by-data-presence.
- The edit pricing adapter owns all margin/HT/TTC derived state, focus/draft state, blur commits, and `setValue('sale_price', ...)` writes in one hook. `sale_price` remains canonical HT.
- `pricing.view_cost_prices` gates WAC, purchase/last cost, margin-only information, cost-derived floors, and stock valuation in both modes.
- Hero contains identity, enrichment, and primary image only. Barcode lookup, scanner handoff, manual refresh, image upload/buffering, and enrichment remain edit-owned. Media remains a dedicated section.
- New or touched user-facing text uses `t()`; new section files use design tokens and no `any`.
- Commit subjects follow `Phase <major.minor.patch>: <imperative summary>` and every commit ends with `Co-Authored-By: Codex <noreply@openai.com>`.

---

### Task 1: M1 Shared Registry and Adapter Contract

**Files:**
- Create: `apps/web/src/features/products/sections/sectionRegistry.ts`
- Create: `apps/web/src/features/products/sections/types.ts`
- Create: `apps/web/src/features/products/sections/index.ts`
- Test: `apps/web/src/features/products/sections/sectionRegistry.test.ts`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-sections-m1-opus-review.md`

**Interfaces:**
- Produces `PRODUCT_SECTION_KEYS`, `PRODUCT_SECTION_DEFINITIONS`, `ProductSectionKey`, `ProductSectionMode`, `ProductSectionsViewAdapter`, `ProductSectionsEditAdapter`, and `ProductSectionsAdapter`.
- The edit form contract contains typed RHF `control`, `register`, `watch`, `setValue`, and `errors`; mode-specific fields are inaccessible after discriminating on `adapter.mode`.

- [ ] Write `sectionRegistry.test.ts` asserting the exact six keys and `section-${key}` DOM ids, plus compile-time discrimination checks with `expectTypeOf`.
- [ ] Run `pnpm exec vitest run src/features/products/sections/sectionRegistry.test.ts`; expect failure because the package does not exist.
- [ ] Add the immutable registry and strict adapter types. Use frontend-only view models; do not edit generated backend types.
- [ ] Rerun the M1 test and `pnpm --filter @autoerp/web typecheck`; expect zero failures.
- [ ] Invoke Opus against the M1 diff and governing spec, save its complete review, reconcile every BLOCKER/MAJOR, and rerun the M1 path/typecheck.
- [ ] Commit M1 with the required trailer.

### Task 2: General Section

**Files:**
- Create: `apps/web/src/features/products/sections/ProductGeneralSection.tsx`
- Test: `apps/web/src/features/products/sections/ProductGeneralSection.test.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-general-section-opus-review.md`

**Interfaces:**
- Edit consumes RHF name, SKU, unit, category, description, active, and ecommerce fields plus existing prefill clearing callbacks.
- View consumes resolved values and renders the same labels/grid/card chrome as read-only values.

- [ ] Add tests for `mode="edit"` controls and `mode="view"` values under `#section-general`, including active/ecommerce status and no duplicate view registry id.
- [ ] Run the test path and observe the missing-component failure.
- [ ] Implement the shared section with `EditorSectionCard` and adapter discrimination; replace only the existing general blocks in both pages.
- [ ] Run the new path plus `ProductForm.test.tsx` and `ProductDetailPage.test.tsx` by path.
- [ ] Invoke Opus, save/reconcile the review, rerun paths/typecheck, and commit.

### Task 3: Canonical Pricing Section and Controller

**Files:**
- Create: `apps/web/src/features/products/sections/useProductPricingEditAdapter.ts`
- Create: `apps/web/src/features/products/sections/ProductPricingSection.tsx`
- Test: `apps/web/src/features/products/sections/useProductPricingEditAdapter.test.tsx`
- Test: `apps/web/src/features/products/sections/ProductPricingSection.test.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Modify: `apps/web/src/features/products/editor/components/ProductHero.tsx`
- Modify: `apps/web/src/features/products/editor/components/ProductEditHero.tsx`
- Retire from page composition: `apps/web/src/features/inventory/components/pricing/PricingIntelligencePanel.tsx`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-pricing-section-opus-review.md`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-pricing-section-opus-rereview.md`

**Interfaces:**
- `useProductPricingEditAdapter` returns literal draft values, focused-field identity, derived HT/TTC/margin values, and `commitCost`, `commitMargin`, `commitPriceHt`, `commitPriceTtc`.
- Only that hook writes `purchase_price`, `opening_unit_cost`, or canonical HT `sale_price` for the interlock.
- View pricing consumes `DiscountPolicyVerdict`, formatters, and permission state; non-cost HT/TTC/tax/max-discount remain visible to all users.

- [ ] Write controller tests proving literal text remains while focused, blur commits margin to HT, TTC back-solves HT, non-focused fields re-derive, and HT is the submitted `sale_price`.
- [ ] Write component holder/non-holder tests in both modes and assert one `#section-pricing` and no hero pricing/WAC/margin text.
- [ ] Run both test paths and observe failures against missing canonical section/hook.
- [ ] Implement the single controller hook and shared pricing card; remove the ready-to-sell pricing strip and page-level `PricingIntelligencePanel` composition.
- [ ] Run pricing paths, `ProductForm.test.tsx`, `ProductFormWacEditMode.test.tsx`, `PricingIntelligencePanel.test.tsx`, `ProductHero.test.tsx`, and `ProductDetailPage.test.tsx` by explicit path.
- [ ] Invoke Opus, reconcile BLOCKER/MAJOR findings, rerun the focused paths, invoke the extra pricing rereview, reconcile again, rerun/typecheck, and commit.

### Task 4: Inventory Section and Stock Cost Gate

**Files:**
- Create: `apps/web/src/features/products/sections/ProductInventorySection.tsx`
- Test: `apps/web/src/features/products/sections/ProductInventorySection.test.tsx`
- Modify: `apps/web/src/features/inventory/components/ProductStockLevels.tsx`
- Test: `apps/web/src/features/inventory/components/ProductStockLevels.test.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-inventory-section-opus-review.md`

**Interfaces:**
- Edit consumes units-per-pack, shelf location, reorder values, batch gate, quantity precision, and opening-stock presentation.
- View embeds stock totals/locations without nested card chrome. `canViewCostPrices` is required to render stock value, WAC, or any cost multiplication.

- [ ] Write view/edit tests and ProductStockLevels holder/non-holder tests proving quantities remain visible while stock value/WAC are hidden without permission.
- [ ] Run the paths and observe the ungated/missing-section failures.
- [ ] Implement the section, an embedded stock-level presentation, and explicit cost gating; replace both page inventory blocks.
- [ ] Run the focused paths plus batch/opening ProductForm tests.
- [ ] Invoke Opus, save/reconcile, rerun/typecheck, and commit.

### Task 5: Suppliers Section

**Files:**
- Create: `apps/web/src/features/products/sections/ProductSuppliersSection.tsx`
- Test: `apps/web/src/features/products/sections/ProductSuppliersSection.test.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-suppliers-section-opus-review.md`

**Interfaces:**
- Both modes render the same translated managed-in-Purchases explanation and `/purchases/suppliers` link; no unsupported product supplier fields are invented.

- [ ] Write both-mode tests for shared id, text, and route.
- [ ] Run the test path and observe failure.
- [ ] Implement and replace both page blocks.
- [ ] Run focused section and page tests.
- [ ] Invoke Opus, save/reconcile, rerun/typecheck, and commit.

### Task 6: Dedicated Media Section

**Files:**
- Create: `apps/web/src/features/products/sections/ProductMediaSection.tsx`
- Test: `apps/web/src/features/products/sections/ProductMediaSection.test.tsx`
- Modify: `apps/web/src/features/products/components/ProductImageSection.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Modify stale tests: `apps/web/src/features/inventory/__tests__/ProductFormMediaSection.test.tsx`
- Modify stale tests: `apps/web/src/features/inventory/__tests__/ProductFormMediaSectionEditMode.test.tsx`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-media-section-opus-review.md`

**Interfaces:**
- Create-edit uses buffered `File[]`; persisted edit uses upload/manage gallery; view uses the same query/gallery read-only with upload/delete/reorder controls absent.

- [ ] Write tests for view read-only gallery, persisted edit management, create buffer, and preserved `#section-media`.
- [ ] Run paths and observe failures; record the existing stale barrel-mock failures as baseline replacements.
- [ ] Add `readOnly`/embedded presentation to the image section and shared media adapter without moving media into hero.
- [ ] Run new and legacy media paths.
- [ ] Invoke Opus, save/reconcile, rerun/typecheck, and commit.

### Task 7: Shared Hero Shell

**Files:**
- Create: `apps/web/src/features/products/sections/ProductHeroShell.tsx`
- Create: `apps/web/src/features/products/sections/ProductHeroSection.tsx`
- Test: `apps/web/src/features/products/sections/ProductHeroSection.test.tsx`
- Modify: `apps/web/src/features/products/editor/components/ProductHero.tsx`
- Modify: `apps/web/src/features/products/editor/components/ProductEditHero.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-hero-section-opus-review.md`
- Review: `docs/superpowers/specs/reviews/2026-07-10-product-hero-section-opus-rereview.md`

**Interfaces:**
- The shell owns identical image/identity/enrichment geometry.
- Edit content owns lookup hook/scanner callback, name/barcode changes, manual refresh, persisted upload/create buffer, and enrichment states. View content accepts no callbacks.

- [ ] Write view purity and edit flow tests covering lookup callback, scanner handoff, refresh, upload toggle/buffer, and enrichment; assert zero price/cost/margin/stock facts.
- [ ] Run paths and observe failures.
- [ ] Implement the shell and mode contents, preserving exact existing edit callbacks and read-only primary image behavior.
- [ ] Run hero, barcode, ProductForm, and ProductDetail paths.
- [ ] Invoke Opus, reconcile, rerun, invoke extra hero rereview, reconcile again, rerun/typecheck, and commit.

### Task 8: Page Integration, Extensions, and Parity

**Files:**
- Create: `apps/web/src/features/products/sections/ProductSectionStack.tsx`
- Test: `apps/web/src/features/products/sections/ProductSectionParity.test.tsx`
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Modify: `apps/web/src/features/inventory/ProductDetailPage.tsx`
- Modify or remove obsolete view registry: `apps/web/src/features/products/editor/viewSections.ts`
- Test: `apps/web/src/features/inventory/ProductDetailPage.test.tsx`
- Test: `apps/web/src/features/inventory/ProductForm.test.tsx`

**Interfaces:**
- Both page adapters feed the same stack. Optional extension slots keep automotive after inventory and edit-only pharmacy/loyalty before suppliers, with variants after media.

- [ ] Write parity tests asserting identical shared `data-product-section-key` values/order in view and edit, exactly one pricing section, and extension gates for default/Otospex/parapharmacy/loyalty.
- [ ] Run parity path and observe failure.
- [ ] Integrate the shared stack, delete duplicate local shared registries/renderers, and preserve only extension gating at page level.
- [ ] Run all changed product test files by explicit paths and typecheck.
- [ ] Commit integration with the required trailer.

### Task 9: Final Verification, Browser Trace, Handoff, and Push

**Files:**
- Create: `docs/sessions/HANDOFF-product-view-edit-unify.md`
- Modify only if verification finds an in-scope regression: files already named above.

- [ ] Run every changed/relevant Vitest file by explicit path; confirm zero failures and no orphan Vitest workers.
- [ ] Run `pnpm --filter @autoerp/web typecheck` and `pnpm --filter @autoerp/web lint`.
- [ ] Run `npx react-doctor@latest --verbose --diff`; fix any score regression in changed files and repeat relevant checks.
- [ ] Start the app using the repository-supported local services and use the in-app browser to verify view→edit section geometry/order, one pricing section, non-holder cost hiding, and caret-stable margin/price editing.
- [ ] Write the handoff with shipped sections, review artifact list, exact test outputs, browser trace, baseline failures resolved, and deferred pharmacy/loyalty/variants view work.
- [ ] Scan changed files for `TODO`, placeholder text, hardcoded user-facing strings, `any`, and duplicate pricing surfaces.
- [ ] Commit the handoff, verify branch/status/log, push only `feat/product-view-edit-unify`, and leave it unmerged for Claude final assembly.
