# Spec C Plan — Codex Adversarial Review + Adjudication

**Date:** 2026-06-18
**Plan:** `docs/superpowers/plans/2026-06-18-variant-label-printing.md`
**Codex verdict:** REVISE — 2 BLOCKER, 4 HIGH, 2 MED. All verified against the repo; all adopted into plan v2.

| Sev | Finding | Verified | Resolution |
|-----|---------|----------|------------|
| BLOCKER | `saveBarcodeSafe()` is `private` (ProductVariantService.php:126); plan called it directly | TRUE | Assign via public `updateVariant($variant, ['barcode'=>$sku])` (wraps saveBarcodeSafe, maps 23505→ValidationException); catch → `skipped` (B2) |
| BLOCKER | dompdf has no CSS-grid support; plan used CSS grid for the sheet | TRUE | HTML `<table>` (table-layout:fixed, mm `<td>`s, page-break per rows) (C1) |
| HIGH | `getPrice()` returns `array{price,source,price_list_id}`, not a string | TRUE | Use `$priceResult['price']` (B2) |
| HIGH | `catalog.labels.print` absent from frontend `PERMISSIONS` map → type-error/always-false gate | TRUE | Add to `usePermissions.ts` PERMISSIONS map (A0) |
| HIGH | symbology via `private select()` across task boundary | TRUE | Public `VariantLabelBarcodeRenderer::symbologyFor()` (A1); `symbology` in the DTO |
| HIGH | `VariantLabelData` positional shape drifted (B2 vs C1 vs FE) | TRUE | One canonical DTO, **named** construction everywhere (plan header block) |
| MED | Permission seed (old D1) ordered after the gated tests | TRUE | Moved to **Task A0** (first); execution-order note added |
| MED | `Modal` is not inherently fixed-size | TRUE | Pin fixed dims via `className` (E2) |

LOW/positives Codex confirmed: `Pdf::loadHTML/loadView->output()` matches installed dompdf usage; catalog route middleware group is correct; the B1 collision check covers all real Spec A scan tiers (LRU caches products only; Tier1/2/API product barcode+sku; SQLite variant barcode); picqer API matches by name (unverifiable until installed — A1 installs it + has a dompdf render PoC).

**Net:** plan → v2, executable. Order: A0→A1→A2→B1→B2→B3→C1→C2→E1→E2→E3→F.
