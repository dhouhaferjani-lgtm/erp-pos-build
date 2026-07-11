# Product View/Edit Unification — Design Spec (Track B)

**Date:** 2026-07-10
**Status:** **Rev 2** — reconciled against the Codex adversarial spec review (`reviews/2026-07-10-product-view-edit-unification-codex-review.md`, 3 BLOCKER/5 MAJOR/3 MINOR) + owner re-decisions. **Read the "Rev 2" block below first — it OVERRIDES conflicting sections.** Pending final owner review → then Codex dispatch.
**Origin:** Track B of the percent-precision/product-page-quality work. The product **view** and **edit** screens diverge badly and pricing renders twice; clicking Edit reflows the whole page. Codex produced a proposal-only design (`docs/sessions/HANDOFF-product-page-quality.md` §Track B) gated on owner sign-off; this spec formalizes it with the owner decisions + guardrails.

## 0. Owner decisions (2026-07-10, LOCKED)
1. **Empty vertical sections in VIEW → hidden** (layout parity applies to *populated* sections; don't render empty read-only shells).
2. **Pharmacy & loyalty → shown READ-ONLY in the detail view** when their vertical/module gate is active (previously edit-only).
3. **Variants → edit-only** (variant management needs a persisted product; no read-only variants in detail).
4. **Media → the hero media slot only** (no separate media section in the shared stack).
5. **Hero shows NO price summary** — all price/cost/margin facts live only in the single canonical pricing section.

## Rev 2 — Codex review reconciliation + owner re-decisions (2026-07-10). OVERRIDES conflicting sections.

**Owner re-decisions:**
- **R2-D4 (media) — supersedes Decision 4:** KEEP the existing dedicated `section-media` (live + tested, `ProductForm.tsx:184,1593`). Media is its OWN section in the shared stack (NOT hero-only). The hero shows the primary image READ-ONLY as identity only — never an editable/second media surface.
- **R2-D2 (pharmacy/loyalty in view) — DEFERRED to a follow-up phase.** The review found they're RHF-only with no read-only variant and absent from the view payload (`ProductDetailPage.tsx:41`, `ParapharmacyMetadataFields.tsx` RHF-only) → would need new read-only components + a backend view-payload change. Deferring keeps THIS phase a **self-contained FE refactor, no backend change.**

**Scope of THIS phase — shared sections only:** `hero`, `general`, `pricing`, `inventory`, `suppliers`, `media` (in that order). **Pharmacy, loyalty, variants stay EDIT-ONLY this phase** (variants already edit-only per D3; pharmacy/loyalty deferred). Automotive: unchanged — edit renders by vertical, view renders only when data exists (D1). The follow-up phase adds pharmacy/loyalty read-only-in-view.

**Corrections (deterministic):**
- **Pricing-controller hook (BLOCKER-2):** the extracted edit-adapter pricing hook OWNS exactly the margin↔HT↔TTC interlock currently at `ProductForm.tsx:194-195` (hero strip blocks), `:956,978` (strip inputs), `:1277,1299` (pricing-section MoneyInputs), plus the cross-field `setValue` writes at `:757,768,775` (handleMarginChange/handlePriceHtChange/handlePriceTtcChange). ALL of these stay in ONE hook — never split across children — so Track-C blur-commit is preserved.
- **Cost-leak gating (MAJOR):** `ProductHero` and `ProductStockLevels` currently show cost/margin WITHOUT `pricing.view_cost_prices` gating — the shared sections MUST gate them (fixes a pre-existing leak; do not carry forward). Holder/non-holder tests in BOTH view and edit.
- **"Pricing renders once" is a FIX, not status quo** — today the hero strip AND `PricingIntelligencePanel` both show pricing; the canonical pricing section replaces both.
- **Section keys/order:** enumerate against BOTH real registries (`EDITOR_SECTIONS` in `ProductForm.tsx`, `PRODUCT_DETAIL_SECTIONS` in `viewSections.ts`); the shared list for THIS phase is exactly `hero, general, pricing, inventory, suppliers, media` — do not invent keys.
- **Hero state ownership:** the shared hero shell delegates ALL interactive state (upload buffer, barcode lookup, scanner, manual refresh) to the EDIT-mode content component; VIEW-mode content is pure read-only (primary image + identity + enrichment status, no handlers).
- **Staged extraction:** `ProductForm.tsx` carries all three prior tracks — extract ONE section at a time (start `general`, then `pricing`), each behind green tests; never a single big rewrite; no parallel ProductForm work.
- Fix the cited paths per the review; drop the backend/DTO claim (this phase is FE-only after R2-D2 defer).

## 1. Goal
The product **view (detail)** renders the **same section layout as edit**, read-only with data, so switching edit↔view does NOT reflow the page — fields toggle editable in place. Pricing appears **once** in both modes. Cost confidentiality, vertical/module gating, Track C draft behavior, and the edit hero's interactive flows are all preserved.

## 2. Current state (verified, from the pre-dispatch review)
- Edit uses local `EDITOR_SECTIONS` + `HERO_BLOCKS` inside `apps/web/src/features/inventory/ProductForm.tsx`.
- View uses a SEPARATE `PRODUCT_DETAIL_SECTIONS` registry (`apps/web/src/features/products/editor/viewSections.ts`).
- Edit hero (`ProductEditHero`) is NOT passive layout — it owns barcode lookup, scanner handoff, manual refresh, image upload/buffering.
- View = `ProductHero` + a separate `PricingIntelligencePanel`; edit = `ProductEditHero` ready-to-sell strip + a separate editable "Pricing & Tax" section → the duplicated pricing surface.
- View automotive is data-presence gated; edit automotive is vertical gated; pharmacy/loyalty exist in edit but not the view registry.

## 3. Architecture — shared section package with mode adapters
Create a shared product-section package (do NOT reuse either existing registry directly).
- **Shared section keys + ordering (single source of truth):** `hero`, `general`, `pricing`, `inventory`, `automotive`, `pharmacy`, `loyalty`, `suppliers`, `media`, `variants`.
- Each section is a component taking a `mode: 'view' | 'edit'` prop + a mode-specific **adapter**:
  - **edit adapter:** supplies RHF `control`/`register`/`watch`/`setValue`, field errors, Track-C draft-commit handlers, image-buffer handlers, barcode-lookup callbacks.
  - **view adapter:** supplies resolved product values + read-only display formatters (percents via `formatPercent`, money via `formatCurrency`).
- **Hero = shared layout shell, mode-specific content:** edit content preserves `ProductEditHero` barcode lookup + upload/buffer + scanner + manual refresh; view content shows the same identity/enrichment/media slots read-only and links to existing image assets (no scanner/upload handlers). Per Decision 5 the hero shows NO price/cost/margin facts.
- **One canonical pricing section:**
  - view mode: WAC/cost + margin verdict/traffic-light + cost-derived floor **only when `pricing.view_cost_prices`** allows; HT/TTC/max-discount/tax are non-cost facts shown regardless.
  - edit mode: preserves Track C draft behavior for margin/HT/TTC and HT-canonical `sale_price`; **also honors `pricing.view_cost_prices`** (users without it must not see WAC/cost/margin-only info through the collapsed section).
  - the margin↔HT↔TTC interlock stays in a **single pricing-controller hook owned by the edit adapter**, so extraction does NOT split the Track C blur-commit behavior across independent children.

## 4. Section gating (owner decisions applied)
- **automotive:** edit renders by vertical; **view renders only when data exists** (Decision 1 — hide empty).
- **pharmacy / loyalty:** render read-only in view when the vertical/module gate is active (Decision 2); must remain gated **at least as strictly as edit**.
- **variants:** edit-only (Decision 3).
- **media:** hero slot only (Decision 4).
- All module/vertical gates enforced identically or more strictly in view than edit — no section leaks into a vertical that edit wouldn't show it in.

## 5. Guardrails — MUST NOT regress (adversarial-review focus)
- **Track C:** ProductForm draft inputs stay blur-commit; the focused field shows the literal draft and does NOT reformat while focused; only non-focused fields re-derive. The pricing interlock hook must not be split across children.
- **Cost confidentiality:** `pricing.view_cost_prices` hides WAC/cost/margin-only info in BOTH view and edit modes (server already redacts `products.show`/index; the FE must not re-expose via the shared section).
- **Edit interactive flows preserved:** barcode lookup, scanner handoff, manual refresh, image upload/buffering, enrichment states — identical behavior to today.
- **HT-canonical `sale_price`** (spec Rev 3 R3-6) preserved; hero/panel/form all consistent.
- **No new pricing surface:** pricing renders exactly once per mode.

## 6. Acceptance criteria
- Shared sections render in identical order in view + edit; pricing appears once in each.
- `pricing.view_cost_prices` hides cost/WAC/margin-only info in both modes (tested: holder vs non-holder).
- Barcode lookup / scanner / manual refresh / image upload+buffer / enrichment keep current edit behavior (tested).
- Vertical/module gates tested for default, Otospex (automotive), parapharmacy (pharmacy), loyalty-enabled.
- Track C draft inputs remain blur-commit, no reformat-while-focused (tested).
- Switching view↔edit keeps section structure/order identical (no reflow).

## 7. Testing (TDD, by path)
Component tests per shared section in both modes; permission-gating tests (view + edit, holder/non-holder); vertical-gating matrix; a Track-C interlock test (type in margin → no mid-edit reformat → blur commits correct `sale_price`); a view↔edit parity test (same section keys/order). Run by path; kill vitest workers after any hang.

## 8. Risks / open
- Largest FE refactor in this stream; touches `ProductForm` (all three prior tracks live here). Do it as its own branch, no parallel work on ProductForm.
- The edit adapter must expose RHF context without prop-drilling the whole form — evaluate a form-context provider vs adapter props (adversarial review to assess).
- Enrichment hero + media coupling: confirm the shared hero shell can host both the edit upload/buffer flow and the view read-only asset links without duplicating enrichment state.
