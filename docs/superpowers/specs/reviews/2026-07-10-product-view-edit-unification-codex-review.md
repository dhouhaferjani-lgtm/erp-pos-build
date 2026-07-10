# Codex adversarial review — Product View/Edit Unification spec (2026-07-10)

Read-only Codex review of `docs/superpowers/specs/2026-07-10-product-view-edit-unification-design.md` before dispatch. **Verdict: NOT sound to dispatch as-is.** Code-verified `file:line`.

## BLOCKERs
1. **Media contradiction (owner-facing).** Spec §3 lists `media` as a shared section, but Decision 4 says hero-slot only. A live, tested `section-media` card exists (`ProductForm.tsx:184,1593`, `__tests__/ProductFormMediaSection*.test.tsx`). Honoring D4 means removing that section + its tests; keeping it contradicts D4. Owner must pick.
2. **Pricing-controller extraction underspecified.** The Track-C interlock spans the hero strip (`ProductForm.tsx:194-195,956,978`) AND the pricing-section `MoneyInput`s (`:1277,1299`), with cross-field `setValue` writes at `:757,768,775`. Feasible, but the spec must name the EXACT fields/handlers the new pricing-controller hook owns before extraction.
3. **Decision 2 (pharmacy/loyalty read-only in view) unsupported by current code (owner-facing cost).** No view-side pharmacy registry; `ProductDetailPage`'s local type omits `parapharmacy_metadata` (`:41`); `ParapharmacyMetadataFields.tsx` is RHF-only (`:8,21,40,147`). So this is NOT a mode-prop reuse — it needs read-only field variants + threading the metadata into the view payload (backend DTO/type change). Significantly more than assumed.

## MAJOR
- **Cost-permission leaks (pre-existing):** `ProductHero`/`ProductStockLevels` show cost/margin without `pricing.view_cost_prices` gating — the unification must not carry these forward, and they're worth fixing.
- "Pricing renders once" is **already false today** (hero strip + panel) — the spec must state it's a fix, not a status quo.
- **Section key/order mismatch** vs both real registries (`EDITOR_SECTIONS` and `viewSections.ts`) — the spec's list doesn't match either; enumerate against the actual registries.
- **Hero state ownership** — `ProductEditHero` upload-buffer + barcode-lookup state ownership across the shared-shell split needs specifying.
- **D1/D2 empty-section conflicts** — D1 hides empty vertical sections in view while D2 wants pharmacy/loyalty shown; define precedence.

## MINOR
- Wrong file paths in the spec (a couple of cited paths off).
- Backend/DTO "self-contained FE refactor" claim overstated — D2 pulls in a backend view-payload change.
- `ProductForm.tsx` is a hot file (all 3 prior tracks) — extraction must be staged, not one big rewrite.

## Reconciliation
- **Owner-facing → re-confirm:** BLOCKER-1 (media: consolidate to hero vs keep the tested media section), BLOCKER-3 (pharmacy/loyalty in view: accept the extra read-only-variant + backend-payload cost now, or defer to a follow-up phase).
- **Deterministic spec edits:** enumerate the pricing-controller hook's fields/handlers (B2); fix section keys/order vs both registries; add cost-leak gating (ProductHero/StockLevels) to scope; state "pricing once" is a fix; specify hero state ownership; define D1/D2 precedence; correct paths; mark backend view-payload change if D2 kept; require staged extraction.
