# Adversarial Review — M1 Shared Section Scaffolding + Mode-Adapter Contract

**Date:** 2026-07-10  
**Reviewer:** `claude -p --model claude-opus-4-8`  
**Scope:** Uncommitted M1 diff only: `apps/web/src/features/products/sections/{sectionRegistry.ts, types.ts, index.ts, sectionRegistry.test.ts}`. Checked against `ProductForm.tsx`, `PricingIntelligencePanel.tsx`, generated DTOs, and the governing spec/plan/review.

## Verdict: APPROVE WITH CHANGES

No blockers. Fix the MAJOR findings before the six sections build on this contract.

M1 is structurally sound on the expensive-to-change foundations: the locked six-key order is correct and test-pinned; the adapter is a discriminated union; the product type is the generated DTO; and the pricing interlock is modeled as one edit-owned controller object. The findings are type-provenance drift traps that compile today through structural identity.

## BLOCKER

None. The registry compiles, discrimination is sound, order matches Rev 2, and `ProductSectionProduct` uses the generated DTO.

## MAJOR

### 1. `ProductSectionFormData` duplicates `ProductFormData`

`types.ts:29-55` re-declares `ProductFormData` (`ProductForm.tsx:200-228`), while `types.ts:15-27` re-declares `ParapharmacyMetadata` (`ProductForm.tsx:118-130`). `Control<ProductFormData>` assigns to `Control<ProductSectionFormData>` only while the two remain structurally identical. A routine field change would break the boundary or invite an unsafe cast.

**Remediation:** Define the form-data interface once in the sections package and import it into `ProductForm`; delete the duplicated parapharmacy form type at the old location.

### 2. `ProductDiscountPolicyVerdict` duplicates `DiscountPolicyVerdict`

`types.ts:77-94` duplicates the exported verdict in `PricingIntelligencePanel.tsx:32-49`, creating the same structural-identity drift risk.

**Remediation:** Relocate the canonical verdict type to the shared contract or import the existing canonical type.

### 3. Shared package dependency direction points into inventory

`types.ts:9-11` imports `UploadedPhoto`, `EnrichmentAttributeRow`, `LookupState`, and `SuggestedProduct` from `features/inventory`, while `ProductForm` will consume `features/products/sections`. Later value imports in the hero extraction could turn this into a runtime cycle.

**Remediation:** Relocate the shared lookup/enrichment primitives to a neutral product-owned module so `sections` never depends on inventory.

## MINOR

### 1. Full cost-bearing DTO is exposed to view components

`ProductSectionsViewAdapter.product` exposes cost fields next to the permission boolean, so component authors can accidentally render costs without checking the gate.

**Remediation:** Document the invariant and prefer a sanitized public product plus gated cost values/accessor.

### 2. Formatter asymmetry

The view adapter injects `formatCurrency`/`formatPercent`, while edit only exposes currency/locale, inviting formatting divergence.

**Remediation:** Hoist both formatters to the base adapter.

### 3. Public surface cleanup

`ProductSectionMode` and `ProductPricingField` were unreferenced exports; `discountPolicyVerdict?: T | undefined` was redundant.

**Remediation:** Keep only intended consumer API and remove redundant optional typing.

## Reconciliation

- Single-sourced `ProductSectionFormData` and `ParapharmacySectionFormData` from the sections contract; `ProductForm` now aliases those types.
- Relocated the canonical `DiscountPolicyVerdict` to the sections contract; `PricingIntelligencePanel` re-exports that type for compatibility.
- Relocated lookup types to `features/products/productLookupTypes.ts` and enrichment capture types to `features/products/enrichmentCaptureTypes.ts`; legacy inventory paths re-export the canonical definitions.
- Sanitized the view product type by omitting cost-bearing fields and added `costPrices: ProductSectionCostPrices | null` to the base contract.
- Hoisted `formatCurrency` and `formatPercent` to the base adapter.
- Removed the unused `ProductPricingField` public export and redundant `| undefined`.

## Post-reconciliation evidence

- `pnpm exec vitest run src/features/products/sections/sectionRegistry.test.ts src/features/inventory/ProductForm.test.tsx src/features/inventory/components/pricing/PricingIntelligencePanel.test.tsx --reporter=dot` — 3 files, 55 tests passed.
- `pnpm --filter @autoerp/web typecheck` — exit 0.
