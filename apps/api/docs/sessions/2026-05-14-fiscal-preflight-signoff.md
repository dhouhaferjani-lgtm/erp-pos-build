# Fiscal Preflight Sign-Off

Date: 2026-05-14

Plan: POS Phase 1 Fiscal Event Engine, Task 1

## Command

```bash
php artisan fiscal:preflight-gate
```

## Local Developer Run

Status: failed closed because the local PostgreSQL role is not available.

```text
SERVER SURFACE: unable to verify - database query failed
DEVICE SURFACE: requires manual inventory - record per-terminal SQLite findings in the sign-off
WEB-POS SURFACE: live receipt-creation path detected - disposition per section 14.2
SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5433 failed: FATAL:  role "autoerp" does not exist
```

## TDD Evidence

Red run before command/provider implementation:

```text
./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php

ERRORS!
Tests: 2, Assertions: 3, Errors: 2.
Symfony\Component\Console\Exception\CommandNotFoundException: The command "fiscal:preflight-gate" does not exist.
```

Green run after implementation:

```text
./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php

OK (2 tests, 5 assertions)
```

Green run after Task 01 Opus review P2 test-coverage edit:

```text
./vendor/bin/phpunit tests/Feature/Fiscal/PreflightFiscalGateCommandTest.php

OK (6 tests, 19 assertions)
```

## Owner Sign-Off (2026-05-16)

The gate's purpose is to guarantee that no real fiscal data exists which the clean rebuild would destroy. The owner attests to the deployment state below in lieu of a remote command run; the deployment state makes a destructive scan unnecessary because no production deployment carries real merchant fiscal data.

**Grounding for this attestation:** `project_pos_go_live_checklist` records the POS go-live as merged locally 2026-05-12 with the pre-deployment checklist still carrying TBDs, the physical-terminal restore drill pending, and no smoke pass on a live terminal completed. No production merchant deployment of POS has happened.

### Server Surface

- **Environment — Production (`api.riserpos.app`, branch `main`, Dokploy container `erp-prod-api-ecl8o8`):** owner attests no merchant deployment of POS exists. Any `pos_receipts` / `pos_z_reports` / `pos_terminals` chain state present is pre-launch test data created during integration work and is acceptable to lose in the clean rebuild.
- **Environment — Staging (`api.erp.otospex.dev`, branch `dev`, Dokploy container `erp-staging-api-kghqex`):** owner attests staging carries only test data from `dev`-branch development and is acceptable to lose in the clean rebuild.
- **Result:** clear (no real merchant fiscal data exists; test data is acceptable to lose).
- **Archival/export path:** not required — no data needs preservation.
- **Verification method:** owner attestation in lieu of a remote command run; the deployment state recorded in `project_pos_go_live_checklist` plus owner knowledge of merchant onboarding state makes the destructive scan unnecessary. A retroactive command run on either environment remains available if a finding later contradicts this attestation.

### Device Surface

- **Terminal inventory:** **no deployed Tauri terminals exist.** No merchant-installed devices are in the field. The physical-terminal restore drill recorded as "pending" in `project_pos_go_live_checklist` confirms no terminal has been provisioned to a live merchant.
- **Result:** no deployed terminals.
- **Archival/export path:** not required.

### Web-POS Surface

- **Live receipt-creation path detected:** confirmed — `POST /api/v1/pos/receipts`, `POST /api/v1/pos/receipts/{id}/payments`, `POST /api/v1/pos/orders/{id}/close` are registered in `apps/api/app/Modules/POS/routes.php` and `routes_orders.php`, reached from `apps/web` per the §14.3 chokepoint enumeration.
- **Disposition decision:** **disable per §14.2 in Task 29** as planned in the spec. The web-POS new-sale path is not in production use by any merchant today; disabling it before the device-authority chain comes online (clean rebuild) is the correct order per `[SoT D8]` ("one fiscal pattern, no two-model coexistence"). Web-POS device-authority parity is deferred (§18 open item).
- **Knowingly retained:** `void` / `processReturn` paths remain server-side for Phase 1 — their event types (`SALE_VOID`, `REFUND_RECEIPT`, `PARTIAL_REFUND`) are Phase 2+ reserved.

**Disposition outcome (recorded by Task 29 implementer, 2026-05-19):**

- Backend routes `POST /api/v1/pos/receipts`, `POST /api/v1/pos/receipts/{id}/payments`, and `POST /api/v1/pos/orders/{id}/close` now return HTTP 410 Gone with error code `NEW_SALE_AUTHORING_RETIRED` for all callers (web + Tauri-online + order-close).
- **Disposition approach:** route-level closures (in `apps/api/app/Modules/POS/routes.php` + `routes_orders.php`). Closures short-circuit BEFORE FormRequest validation runs, so callers get the disposition code regardless of payload shape (a controller-method early-return would have been masked by `StoreReceiptRequest` returning 422 first).
- Controller methods (`ReceiptController::store`, `storePayments`; `OrderController::close`) preserved as structural anchors for Task 30's §14.3 chokepoint grep manifest; their docblocks now cite §14.2 and warn against re-wiring without coordination.
- Web frontend new-sale affordances disposed in `apps/web`:
  - `POSTransactions.tsx` collapsed to a retirement notice page (§18 open item).
  - `CloseOrderButton.tsx` deleted.
  - `OrderPanel.tsx` close-button affordance removed (cancel remains as non-receipt termination path).
  - `useOrders.useCloseOrder` hook removed; `closeOrder` API function removed from `orderApi.ts`.
  - `createReceipt` / `processReceiptPayments` API functions removed from `receiptApi.ts` (matches Task 27 Pass 1's deletion of the same functions from `apps/pos/src/api/receiptApi.ts`).
- Read-only routes (GET /receipts, /receipts/{id}, /receipts/{id}/pdf, /receipts/{id}/pdf/download), `/pos/receipts/sync` (Task 28's job to retire), `void` and `processReturn` (Phase 2+ reserved event types) — all preserved.
- `routes.php:45` (currently `Route::post('/pos/terminals/web', ...)` in the live file — line numbers drifted from the plan's enumeration) is NOT a new-sale-authoring route and is not part of this disposition. The plan-flagged route at `routes.php:45` (`patch /pos/terminals/{id}/deactivate` in earlier numbering) was confirmed unrelated per Task 19 standing pattern "verify the premise of every deferral".
- D8 coexistence resolved per spec §14 disposition (b) — disabled/deferred — for `ReceiptCreationService::createReceipt()` and `ReceiptController::store/storePayments` callers. The two §14.3 chokepoints (`createReceipt` + `finalize`) gain their CI grep gate in Task 30.
- **Obsolete test cleanup (8 PHP feature files):** `DiscountEnforcementTest`, `PromotionReceiptIntegrationTest`, `ComboReceiptTest` (4 methods), `KitchenDisplayTest::test_table_released_on_order_close`, `PosStabilizationTenantIsolationTest` (8 store_receipt + close_order methods), `ReceiptPaymentFlowTest`, `StoreReceiptPaymentsInstrumentBindingTest`, `StoreReceiptPaymentsTenantIsolationTest`, `StoreReceiptPaymentsToleranceAuthorizationTest` — class- or method-level `markTestSkipped` with §14.2 reference; cross-tenant defense moves to PosCoreReceiptProjection + TreasuryReceiptBridge (Tasks 21–22), instrument-binding validation moves to FiscalEventEnvelope intake. Each skip cites the surviving test that carries the contract end-to-end.
- **CI extension:** PG-merge-gate filter at `.github/workflows/ci.yml:~424` extended with `NewSaleServerAuthoringDispositionTest` in the same commit as the test.

### Signature

- **Owner:** otospexsolutions (project owner)
- **Signed at:** 2026-05-16

## Gate

**OPEN.** Schema-destructive Tasks 7–13 and 28–30 are cleared to proceed on the basis of this written owner attestation. If any later verification surfaces real merchant fiscal data that this attestation did not account for, work stops and an archival/export path is defined before continuing.
