# Staging probe — parties balances import, immediate visibility, import history + reports — 2026-08-31 — **PASS**

Target: `erp.otospex.dev` / `api.erp.otospex.dev` (web bundle `index-BFTVQxGg.js`). Fresh tenant per run. Two harnesses:

## A. Onboarding campaign L0 + L1 against staging (`playwright test -c playwright.campaign.config.ts --grep "L0|L1"` with `CAMPAIGN_WEB_URL/API_URL`) — 4/4 PASS, 36.5 s
Run `20260831103732-atx`, tenant `01a05765-5f71-…`. L0: UI registration, day-one census (locations=1, methods=7, cash tender=1, cash register + safe on MAIN, units=19), company 2 census. L1: 4-quadrant parties file through the wizard → HIST-INV/CN/SINV/SCN created; customer receivable **1250.500**, supplier payable **4780.250** read back immediately; balance endpoint `+1250.500 / −4780.250`; re-run of the same file → 0 extra HIST documents, no duplicate partners, workbook reports skipped rows. L10 findings gate: **zero product findings**.

## B. History/reports probe (`apps/web/e2e-local/staging-import-history.spec.ts`, config `pw.staging.config.ts`) — PASS, 31 s
| Step | Result | Latency after wizard "complete" |
|---|---|---|
| Registration (API) | 201 | 15.3 s |
| Parties import with balances via wizard | job `completed ok=2 skip=0 fail=0 warn=0` | 5.8 s end-to-end |
| API `GET /partners` | receivable 1250.500 / payable 4780.250 | **0.6 s** |
| Customer page `/sales/customers/{id}` | shows **1 250.500** | **2.6 s** |
| `/settings/import/history` | row `Business partners · hist-parties-….csv · Completed · 2 / 0 / 2 · Download result workbook` | — |
| Result workbook (XHR) | **200** `application/vnd.openxmlformats…sheet`, 7 562 B | — |
| File with one invalid row (blank name, non-numeric balance) | job `Completed · 1 / 1 / 2`; history offers **Download failed rows (CSV)** | — |
| Failed-rows report (XHR) | **200** `text/csv`, names the rejected row | — |
| Valid row of the partially-failed file | imported | — |

**Reading:** the "balance not imported / not visible straight away" class is not reproducible on the current staging build for a first balances import — balances land synchronously with the wizard and are visible in the UI within seconds; history lists jobs with counters and both reports download. **Not covered here (still open, lane G-14):** a SECOND parties-with-balances file after the first AR/AP opening batch has POSTED silently skips the new balances with a row warning only — the campaign's known-red list still carries it (`docs/qa/ONBOARDING-CAMPAIGN.md` §known red). Separate open finding F-SOE-1 (same `tax_id` customer+supplier in one file merges them and drops the supplier balance) from the journey spec.
