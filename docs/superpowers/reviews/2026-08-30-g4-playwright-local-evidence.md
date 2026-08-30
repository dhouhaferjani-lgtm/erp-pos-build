# G-4 — local browser verification (scripted Playwright), 2026-08-30

Stack: G-4 worktree served on API :8011 (`QUEUE_CONNECTION=sync`) + Vite :5174 (in-tree proxy override), fresh tenant registered per run via `POST /api/v1/auth/register` (TN / retail). Harness: `apps/web/e2e-local/` (git-excluded; recipe in memory `reference_local_playwright_scripted_harness`). Sample files: `products-v1.csv` (exact `kg`/`pc`, misspelled `Kg`, blank unit, blank SKUs, same-name pair), `products-v2.csv` (HT-only price change with blank purchase price, barcode-only match, new row), `customers.csv`.

## Run 1 (pre-fix, tip f878a248c) — journeys pass, 3 defects
| # | Defect | Evidence |
|---|---|---|
| 1 | HIGH — override with blank `purchase_price` kept the stale sale price | HAR-200 `updated_at` unchanged; `CoalescingAttributeMerger` governing cells for `sale_price` omitted `sale_price_excl_tax`/`incl_tax` |
| 2 | MED — completion tile counted policy-skipped rows as "Failed to import 5" | backend job: successful 0 / failed 0 / skipped 5, outcomes `duplicate_skipped` |
| 3 | LOW — preview "Repeated in file 0" for a same-name blank-SKU pair | execute resolved it (`matched_by_name` + `preview_drift`) |
Recorded correctly: `Kg` → `unit_unknown` with the accepted-code list (`import_error_detail`); blank unit → `pc` + `unit_defaulted`; SKIP changed nothing; barcode-only row matched.

## Run 2 (after fix round 6) — 4/4 green
    ===== v1 COUNTS ===== {"imported":5,"skipped":1,"failed":1}
      ✓  1 [chromium] › e2e-local/g4-imports-v2.spec.ts:105:3 › G-4 after fix round 6 — real wizard on a fresh tenant › v1: units + in-file pair + blank SKUs (13.3s)
    ===== v2-override COUNTS ===== {"imported":5,"skipped":0,"failed":0}
    HAR-200 before/after {"before":{"sale_price":"4.165","purchase_price":"2.000"},"after":{"sale_price":"4.462","purchase_price":"2.000"}}
      ✓  2 [chromium] › e2e-local/g4-imports-v2.spec.ts:117:3 › G-4 after fix round 6 — real wizard on a fresh tenant › v2 OVERRIDE: HT-only price change with blank purchase price updates the price (13.6s)
    ===== v2-skip COUNTS ===== {"imported":0,"skipped":5,"failed":0}
      ✓  3 [chromium] › e2e-local/g4-imports-v2.spec.ts:145:3 › G-4 after fix round 6 — real wizard on a fresh tenant › v2 SKIP: nothing changes, tiles show 0 / 5 / 0 (14.1s)
    ===== cust COUNTS ===== {"imported":2,"skipped":0,"failed":0}
      ✓  4 [chromium] › e2e-local/g4-imports-v2.spec.ts:156:3 › G-4 after fix round 6 — real wizard on a fresh tenant › customers via parties (10.0s)
      4 passed (1.1m)

Screenshots: scratchpad/shots2 (session-local). Follow-up (not blocking): surface `unit_unknown` at preview time, not only at execute.
