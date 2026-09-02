# Wave 1 (imports + enrichment + units + barcode identity) — team handoff, 2026-09-02

**State:** merged to `dev` and pushed (`a1d24da88`); staging auto-deployed. Everything below is verified in the real browser UI on fresh tenants with zero 5xx and zero console errors (evidence with per-run numbers: `docs/superpowers/reviews/2026-08-31-wave1-imports-local-evidence.md`; gate registers under `docs/superpowers/reviews/2026-0{8-30..9-02}-k*`).

## What shipped (lanes K-6..K-13 + 9 fix rounds)
- **K-6:** wizard no longer 500s (`GET /imports//preview` guard + `whereUuid` on every `/imports/{id}` route).
- **K-7:** opt-in **platform enrichment on products import** (toggle appears when a platform key + mapped vertical + a mapped barcode column exist): barcode lookup → backlink → attributes + images persisted; misses get per-row warnings; `enrichment_summary` on the job + history.
- **K-8:** unit validation moved to PREVIEW (aggregated: "N rows use unknown unit 'x' — map it in Settings → Units or correct the file"); validation is O(1) queries per run.
- **K-9:** Settings → Units → **Unmapped unit texts** panel: map free text (e.g. `piece → pc`) once — applies to existing products, pending imports, and (FR3) FUTURE imports even when mapped proactively.
- **K-11 (owner-ruled):** barcode = ONE product per company. Same-barcode lines that agree = multi-location import (confirm → one product, stock per line's location). Contradictory lines = refused `barcode_identity_conflict` with the conflict panel naming barcode/rows/differing fields. Write-guard on import+API (create with a taken barcode → prompt to the existing product). Per-company partial unique + greenfield twin-cleanup migration.
- **K-12:** mapping-suggestion hygiene (blank headers tolerated, failures banner'd, exact-name matches exclusive, second-upload lifecycle fixed).
- **K-13:** Excel numeric noise normalized at the boundary (`71.162000000000006` → `71.162`; scientific notation handled); malformed numerics are coded errors, never crashes.
- **K-10:** no more `/auth/me` 401 console noise on the login page.

## Tester quick-truths for `model produits.xlsx`
- Barcodes intact → **102 imported / 757 refused** with actionable reasons (754 barcode conflicts — the file's barcodes are Excel-corrupted, only 180 distinct across 859 rows; +3 negative quantities on rows 141/160/827).
- After mapping `piece → pc` once (Settings → Units) and clearing/fixing barcodes → **856 / 3** (only the negative-quantity rows fail).
- Clients (257) and suppliers (11) files import cleanly; French headers auto-map.

## Known open items
- **K-14 (pre-existing `dev` defect, P1):** opening-balance import reports "1 imported / Completed" while posting nothing when the referenced account doesn't exist (`ImportTypesTest.php:438` red is THIS). Next lane.
- Partial-import dialog no longer shows for files whose invalid rows were fully counted at preview (post gate-fix arithmetic) — deliberate, tiles stay honest; revisit copy if testers miss it.
- Platform image DATA gap: production platform has images for very few parapharmacy EANs (2/51 sampled) — enrichment adopts what exists; image acquisition is a platform-side task (own wave).
- POS flows: manual team test day (unchanged by this wave).

## Wave 2 (purchase orders) runs in a parallel session
Handover: `docs/sessions/session-L-wave2-po-2026-09-01/HANDOVER.md` (local machine). Findings flow through gated briefs; K-1 (line_total NET + account-charge stock, gated, dispatch-ready) owns the PO gross-in-net money fix.
