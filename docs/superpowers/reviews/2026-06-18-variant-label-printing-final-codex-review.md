# Spec C — Final End-to-End Codex Review + Adjudication

**Date:** 2026-06-18
**Scope:** whole `feat/variant-label-printing` (merge-base..HEAD). Companion: a frontend spec-compliance review (✅ compliant; 28/28 FE tests, typecheck/ESLint clean, all i18n keys en+fr).
**Codex verdict:** REVISE — 1 BLOCKER, 1 HIGH, 1 MED. All verified TRUE; all fixed (`230ac84fc`).

Codex confirmed sound: the cross-layer JSON contract (prepare `{items}` → `data.ready`/`meta.skipped`; pdf `{format,items,start_cell}` → PDF blob), the permission gate (seeder + FE PERMISSIONS map + route + authorize), the decimal-string price path, and the hand-reconciliation consistency (no half-applied edits / dead code from the worktree-concurrency incident).

| # | Sev | Finding | Resolution |
|---|-----|---------|------------|
| B1 | BLOCKER | `valueIsUsable` ran only on the assign path; a **pre-existing** variant barcode (e.g. user-typed via Spec B, which doesn't check product codes) was printed without re-checking product-code collision → could mis-scan to a product (Spec A Tier-1). | Extracted `collidesWithProductCode($tenantId,$value)`; `prepare()` `elseif` skips an existing-barcode variant that collides (`barcode_conflict`); `pdf()` → 422. **Product-only check** for existing barcodes (no trashed-variant subcheck, which would false-skip the legitimate owner). |
| H1 | HIGH | Non-ASCII SKU (plausible in FR/AR catalogs) → Code 128 emits a corrupt/unscannable image instead of a clean skip/422. | `VariantLabelBarcodeRenderer::canEncode()` (valid EAN numeric OR printable-ASCII `\x20-\x7E`); `prepare()` skips `unencodable_barcode`; `pdf()` → 422. |
| M1 | MED | Blade applied horizontal gutter but not `gutterYmm` (2 mm for `grid_custom`) → no vertical row gap. | Blade applies `gutterYmm` as bottom padding on non-last rows (dompdf-safe table styles). |

**Verification after fixes (PG):** VariantLabelBarcodeRendererTest 7, PrepareLabelsTest 8, GenerateLabelPdfEndpointTest 7, VariantLabelPdfServiceTest 4, LabelBarcodeCollisionTest 3, PrepareLabelsEndpointTest 6 — all green. PHPStan `app/Modules/Catalog` 0 errors, **no `@phpstan-ignore`**. Pint pass.

## Documented scope decision (owner to confirm)
**Bulk product-list "Print labels" trigger DEFERRED** (spec §3.6 listed it). `ProductListPage` has no existing row multi-select; building one is out of proportion for v1, so E3 shipped the **variant-editor trigger only** (fully functional: print a product's variants' labels). A follow-up can add a multi-select + bulk action.

## Net
Spec C is implementation-complete, fully reviewed (spec ×Codex, plan ×Codex, prepare ×spec+Codex, frontend ×spec, final ×Codex+spec), green, PHPStan/Pint/typecheck/ESLint clean. The dompdf-PNG render PoC passed early. Merge-ready.
