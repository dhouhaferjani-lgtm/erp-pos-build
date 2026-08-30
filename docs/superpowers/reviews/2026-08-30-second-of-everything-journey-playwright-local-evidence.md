# Second-of-everything journey — local scripted Playwright (fresh tenant, 2 companies × 2 locations), 2026-08-30

**Why:** the last two days' onboarding bugs (F-BUG-1/F2 company switch, per-company SKU/VAT scope, location resolution, G-3c repositories) lived in the second company / second location / re-run corners that the depth-first lane gates and the parapharmacy-only campaign (one company, one location) never drive. `docs/qa/MANUAL-TESTING-LOOP.md §2` steps 1–6 + 9 are now scripted end-to-end.

**Harness:** main dev checkout (local `dev` `c65f9bab4`, code = staging `70968fbf2`) on API :8010 (`QUEUE_CONNECTION=sync` env override, no `.env` edit) + Vite :5173; `apps/web/e2e-local/second-of-everything.spec.ts` + `e2e-local/pw.soe.config.ts` (git-excluded; promotion into `e2e/campaign/` as a second campaign file is the follow-up). Reuses the campaign helpers (`registerFreshTenant`, `runImportWizard`, `apiRequest`, selectors) but drives ONE shared page across serial legs — the campaign's `ensureSession()` re-injects company 1 into `localStorage` on every navigation and would mask the switch-then-refresh regression. Every stock/balance truth is read through the public API with an explicit `X-Company-Id`.

| Leg | Drive | Assertion |
|---|---|---|
| S0 | UI register + API | day-one company has exactly one location `MAIN`; `POST /locations` creates `WH<run>` (warehouse); `POST /companies` creates company 2 with exactly one `MAIN`; `WH<run>` does not exist in company 2 |
| S1 | UI | header switch c1→c2, `localStorage.autoerp-company-selection` = c2, **refresh keeps c2**; switch back, refresh keeps c1; no "access denied" text |
| S2 | UI import + API | products file with rows at `MAIN`, at `WH<run>` and with a blank `location_code` → A@MAIN 10 / A@WH 0 / B@WH 5 / B@MAIN 0 / blank→MAIN 4; re-run: 3 products, quantities unchanged, workbook reports skipped |
| S3 | UI switch + import + API | same file into company 2 → same SKUs accepted as distinct product rows; A@c2.MAIN 10; row with company 1's `WH<run>` code creates **no** stock in company 2; company 1 unchanged; company 2 cannot read company 1's stock level (403/404) |
| S4 | UI import ×3 + API | parties with balances into c1 (customer 100.000 receivable, supplier 250.500 payable); same file (same codes + tax_ids) into c2 accepted, c1 unchanged; c2 re-run: no doubling, workbook reports skipped |
| S5 | API | transfer MAIN→WH of 3 (DEFAULT lot allocation — parapharmacy batch tracking) → A@MAIN 7, after `complete` A@WH 3; c2's A untouched |
| S6 | API | PO (supplier from S4, `location_id` = WH) → confirm allocates a number → receive with a lot → C@WH 6, C@MAIN still 4; `receipt-status` read |
| S7 | API | `GET /payment-repositories` for c2 shows no c1 drawer; c2 owns a `cash_register` on its MAIN + a `safe`; c1 MAIN keeps its drawer |

## Findings

### F-SOE-1 (P1, imports) — two rows sharing a `tax_id` in one parties file merge into one partner; the second row's name overwrites the first and its opening balance is dropped with a warning only
- Tenant `01a053cf-e698-72da-a11b-2acc2f3550f2`, company 1, job rows: row 1 (`SOE-CUST-…`, customer, tax_id `TAX…`, 100.000) `imported`; row 2 (`SOE-SUPP-…`, supplier, same tax_id, 250.500) `imported` with warning `balance_not_posted | partner_code: Partner 'SOE-SUPP-…' not found or is not a supplier`.
- Result: ONE partner `code=SOE-CUST-…`, **name = "SOE Supplier …"** (row 2 overwrote row 1's name), `type=both`, receivable 100.000, payable **0.000**; documents: `HIST-INV-2026-00001` only — the 250.500 supplier balance never landed anywhere. Job status: success, tiles 2 imported / 0 failed.
- Expected (Odoo/ERPNext baseline): a `tax_id` match may legitimately upgrade a party to `both`, but (a) the row must not silently rename the matched party, (b) the balance phase must find the party by the SAME resolution the identity phase used (code OR tax_id), so the supplier opening posts as `HIST-SINV`, (c) a money-bearing row that did not post is a failure, not a warning on a green job. Owner ruling needed on whether `tax_id` should be an identity key across `type` at all (glossary: parties identity keys).
- Repro: fresh tenant, parties file with a customer and a supplier carrying the same `tax_id`, different `code`s.
- Harness change: the journey now uses distinct tax_ids per party within a company (same across companies, which is the checklist's intent); this case needs its own probe leg once the ruling lands.

### Not defects (harness learnings)
- Parapharmacy vertical → products are batch-tracked by default → `POST /stock-transfers` refuses without `batch_allocations` (`INVALID_TRANSFER`); allocate from the DEFAULT lot (`GET /products/{id}/batch-stock`, use the lot's `uuid`, not its integer `id`). Receipts need `batches[{line}]` for batch-tracked lines.
- `throttle:register` (5 per 15 min per IP) also budgets this journey: one registration per run.

### F-SOE-2 (P2, purchasing API) — "receive all remaining" cannot carry lot data for batch-tracked lines
`POST /purchase-orders/{id}/receive` without `quantities` routes to `GoodsReceiptService::receiveAll()` (`PurchaseOrderController.php:841-843`), which takes no `batches` argument; for a batch-tracked product it throws `Batch data is required for batch-tracked product <uuid>` (`GoodsReceiptService.php:524`) → `GOODS_RECEIPT_FAILED`. So a parapharmacy tenant can never "receive all" through the API; only the explicit-`quantities` branch forwards `batches`. The error also names the product by UUID, not SKU. Check whether the web goods-receipt screen always sends `quantities` (then it is API-only) before routing.

## Run 5 — 2026-08-30 19:32 — **8/8 PASS** (2.9 min)
Tenant `01a053de-…` (parapharmacy/TN). S0 21–43s (registration) · S1 switch/refresh both directions OK · S2 A@MAIN 10 / A@WH 0 / B@WH 5 / B@MAIN 0 / blank-location row → MAIN 4.0000; re-run products=3, quantities unchanged, workbook says skipped · S3 same SKUs in c2 as distinct rows, A@c2.MAIN 10, foreign `WH<run>` code → 0 stock rows in c2, c1 untouched, c2 cannot read c1 stock · S4 c1 customer 100.000 / supplier 250.500; c2 same file OK; c1 unchanged; c2 re-run no doubling · S5 transfer `in_transit`→`completed`: A@MAIN 10→7, A@WH 0→3, c2 A untouched · S6 `PO-2026-0001` confirmed, received at WH with lot `LOT-<run>`: C@WH 0→6, C@MAIN still 4, receipt-status `fully_received` 6/6 · S7 c2 repositories = its own cash_register + safe on its MAIN, 0 leaked from c1.
Runs 1–4 failures were all harness (spec) fixes, except run 1's S4 which is F-SOE-1 above: distinct tax_ids (run 2), lot allocation on transfer (run 3: needs the lot's INTEGER `id`, the `uuid` is rejected — `batch_id must be an integer`), explicit `quantities` + `batches` on receipt (run 5).

**Next:** promote the spec into `apps/web/e2e/campaign/` as a second campaign file (own `pnpm campaign:second-of-everything` script + CI job, fixtures templated on `{{RUN}}`), add a POS leg (device-authored chain against a c1 terminal, then a c2 terminal — disjoint registers), add the F-SOE-1 probe once the owner rules on `tax_id` identity, and run the same journey against staging after the next promotion.
