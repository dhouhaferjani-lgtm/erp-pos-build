# Treasury Phase 2 — live A→Z report

Date: 2026-07-11  
Tenant: `019f2313-4ff7-73aa-99fd-fc6fbbedcce4` (`owner@pharmabio.tn`)  
Stack: db-per-tenant PostgreSQL, Laravel API on `:8010`, Vite on `:5173`, Redis queue worker

## Result

All seven Task-27 stations passed against the real local stack. The final reconciliation checked seven repositories with zero cash drift, zero portfolio drift, and zero errors.

## Stations

1. **B2B deferred payment and register** — created inline traite `E2E-TRT-20260711-A` for `125.500 TND`. The register showed `Bill of exchange`, `Incoming`, `Received`, and `Details required = No`. Treasury Total Cash remained `56 027.404 TND`. A second `20.000 TND` paper confirmed the same no-cash receipt behavior and populated the échéancier.
2. **Effet remittance** — remitted `E2E-TRT-20260711-A` and `E2E-TRT-20260711-B` on `REM-2026-0001`. Both moved to `Deposited`; the remittance journal was posted in journal `EF`. The printable bordereau showed drawer, drawee bank, reference, maturity, count, and exact `145.500 TND` total.
3. **Clearing with fee** — cleared A with `1.000 TND` bank fee and `0.190 TND` fee VAT. Repository movement `b1987def-6658-4782-be76-1edbf2096593` is `In 124.310 TND`, links instrument `019f5207-0f31-71f2-9b90-d958bbcc1253` to JE `019f521d-10ee-73ef-bd4a-7452aa6ae3c5`, and left the bank repository at `25 124.310 TND`. Overview Total Cash became `56 151.714 TND`.
4. **Dishonor and invoice reopening** — created fully allocated traite `E2E-TRT-20260711-C` through `PaymentController` for the remaining `5.296 TND` of `DEMO-INV-0003`, remitted it, then bounced it through the UI with `receivable` routing. The instrument is `Bounced`, payment `dishonored_at` is set, the invoice returned to `Posted` with `balance_due = 5.296`, and a negative `-5.296` allocation mirrors the payment allocation. The échéancier excludes cleared A and bounced C and shows only remitted B; Money In shows the reopened invoice once.
5. **POS fiscal-event leg** — authored verified SALE_RECEIPT event `ad4cfc37-9975-43d9-8c98-b9f107896e8c` from the demo terminal chain and ran the production POS core plus Treasury receipt projections. Check instrument `019f5225-59c2-7232-b78f-bbef254955bd` appeared as `Received`, `origin=pos`, `needs_details=true`. The PATCH endpoint completed it as `E2E-POS-CHK-20260711`, after which the UI remitted and cleared it in full.
6. **Operations** — `php artisan treasury:reconcile --tenant=019f2313-4ff7-73aa-99fd-fc6fbbedcce4` reported `checked 7`, `froze 0`, `portfolio drift 0`, `errors 0`. `php artisan treasury:instrument-maturity-alerts` checked one company with zero errors and wrote audit event `019f5228-cc30-7061-85b7-9ad3891b0482` (`treasury.instrument.maturity_alert`).
7. **Fix-forward status** — no red product station remains.

## Harness notes

- The first attempt to remit `REM-2026-0001` failed because the pre-existing demo chart lacked Phase-2 account 5313. Re-running the idempotent Tunisia chart seeder added all seven portfolio/fee accounts; retrying the unchanged draft succeeded. This is the exact deploy prerequisite captured by Task 28.
- Direct URL loads occasionally lingered briefly on `Loading companies…`; the page completed after company scope hydration and all actions persisted exactly once.
- The in-app tab's CDP screenshot command timed out, so an isolated authenticated Playwright page captured the same live routes. No mocked responses or database-only UI assertions were used.

## Screenshots

- `treasury-overview-final.png` — final Total Cash, survivor-only échéancier, and reopened invoice in Money In.
- `effet-remittance.png` — `REM-2026-0001` detail and printable bordereau.
- `pos-check-cleared.png` — completed POS check after its remit/clear lifecycle.

Screenshots are intentionally retained in this gitignored session directory; this report is force-tracked as the durable execution record.
