# Spec C (Variant Label Printing) — Codex Adversarial Spec Review + Adjudication

**Date:** 2026-06-18
**Spec reviewed:** `docs/superpowers/specs/2026-06-18-variant-label-printing-design.md`
**Codex verdict:** REVISE (confidence 88%) — 2 BLOCKER, 4 HIGH, 5 MED, 3 LOW. All adopted into spec v2 (§9 maps each to its resolution).

> Codex returned findings inline (sandbox blocks worktree writes); this is the maintainer's record. The review was strongly grounded — it read the sibling POS worktree (scan tiers), the real `PricingService` signature, the Taxation PNG-QR precedent, and the permission seeder.

## Findings (all verified TRUE against the repo)

- **[BLOCKER] B1 — product barcode/SKU mis-resolution:** Spec A's in-memory Tier-1 matches product `barcode`/`sku` *before* the SQLite variant tier; product codes aren't unique-indexed. A `barcode=sku` label could resolve to a product. → collision check now spans product `barcode`+`sku` and variant `barcode` (§3.3, §4).
- **[BLOCKER] B2 — PDF+JSON in one response impossible:** split into `prepare` (JSON: assign + ready/skipped) and `pdf` (binary) (§3.5/§3.6).
- **[HIGH] H1 — bare repo `save()` → 500:** assign via `ProductVariantService::saveBarcodeSafe()` (§3.3).
- **[HIGH] H2 — `getPrice` signature:** real is `(productId, partnerId=null, quantity='1.00', currency='USD', date=null, variantId=null)`; use named args (§3.3).
- **[HIGH] H3 — dompdf SVG barcode unproven:** use picqer PNG renderer → base64 `<img>` (Taxation `CertificatePDFService` PNG precedent); render PoC first (§3.2).
- **[MED] M1** mutation moved into explicit `prepare` step. **M2** scope variant before pricing (`ProductVariantLookup::findById` is unscoped). **M3** UPC-A pad 12→13. **M4** seed `catalog.labels.print` + role-map. **M5** `start_cell` bounds.
- **[LOW] L2** cap `items` length. **L3** `shop_name = Company::$name`. **L1** "A1 notation" — spec uses integer `start_cell`; no change.

## Positives Codex confirmed
- Spec A variant round-trip is real (Tier-2 `variant-hit` adds the exact variant) *when the value survives Tier-1* — which B1's fix guarantees.
- Variant barcode uniqueness is DB-enforced, tenant-scoped, soft-delete-aware; Spec B requests validate it.
- `PricingService::getPrice` supports null partner and returns decimal strings (not floats); variant money columns aren't float-cast.

## Net
Spec → v2; design is now implementation-ready. The one residual UNVERIFIED item (picqer-PNG-in-dompdf actually rendering) is de-risked by requiring a render proof-of-concept as the first plan task.
