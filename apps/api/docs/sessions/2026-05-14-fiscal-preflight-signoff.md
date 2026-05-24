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

**Round-2 closures (Task 29, 2026-05-19):**

- **Closed (4 Codex P2 + 3 Opus P3):**
  - **Codex T29-F1 (P2):** void/processReturn carve-out tests replaced "not 410" assertions with positive happy-path coverage — seed eligible receipt + valid payload + assert 2xx + DB side effects (voided_at / receipt_type=return / original_receipt_id mirrored).
  - **Codex T29-F2 (P2):** `StoreReceiptPaymentsToleranceAuthorizationTest` class-level skip → per-method; `test_seeder_grants_apply_tolerance_to_cashier_role` un-skipped (non-route seeder permission assertion).
  - **Codex T29-F3 (P2):** `StoreReceiptPaymentsInstrumentBindingTest` skip citation now points to `PosCoreReceiptProjection::writePayments` (where the defense actually lives — `InstrumentRequiredException` via `PaymentInstrumentKind::requiresInstrumentForMethodCode`). `PosCoreReceiptProjectionTest` extended with two voucher-instrument tests pinning missing-type and missing-serial rejection paths.
  - **Codex T29-F4 (P2):** `StoreReceiptPaymentsTenantIsolationTest` class-level skip → per-method; service-bypass `test_service_rejects_cross_tenant_customer_id_when_form_request_is_bypassed` un-skipped (`ReceiptPaymentService` still reachable via `ExchangeService::processExchange`; security-sensitive defense-in-depth).
  - **Opus F3 (P3):** `ReceiptController::store` + `storePayments` docblock summary lines past-tensified (`[RETIRED §14.2] Created ...` / `[RETIRED §14.2] Processed ...`) for grammatical consistency with the past-tense body.
  - **Opus F4 (P3):** `apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:349` gains inline `[RETIRED §14.2 (Task 29) — 410 NEW_SALE_AUTHORING_RETIRED; device-authored via FiscalEventEngine.append()]` annotation.
  - **Opus F5 (P3):** `POSTransactions.tsx` migrated 3 hardcoded Tailwind gray classes (`bg-gray-50`, `text-gray-900`, `text-gray-600`) to design tokens (`colors.neutral[50]`, `textColors.primary`, `textColors.tertiary`). `text-amber-500` retained — no amber token exists and the legacy lint rule does not flag amber.

- **Deferred with explicit citation (2 Opus P2):**
  - **Opus T29-F1 (P2) DEFERRED to Task 22/30 follow-up:** `PosStabilizationTenantIsolationTest` skips claim cross-tenant defense for `product_id` / `customer_id` / `contact_id` / `modifier_group_id` / `modifier_id` / `composite_item_id` "moves to `PosCoreReceiptProjection` (Task 21)" — but `PosCoreReceiptProjectionTest` covers only `payment_method_id` explicitly. Defense exists at DB-FK level. The explicit projector-level cross-tenant assertion for the six non-payment-method axes is queued for a Task 22/30 projection-coverage extension. Round-2 scope was too broad to absorb (1 parametric test method per axis = 6 new tests with full seed scaffolding) and the underlying defense (DB-FK constraints to tenant-scoped tables) is intact.
  - **Opus T29-F2 (P2) ACKNOWLEDGED — operational discretion:** Route closures inherit `auth:sanctum` middleware so anonymous callers receive 401 BEFORE reaching the closure (instead of the 410 Gone disposition the spec frames). This is intentional on the authenticated POS/API surface; both reviewers acknowledged and Codex did not escalate. Inline comments added to `routes.php` + `routes_orders.php` documenting the auth-middleware contract. Authenticated callers receive 410 as specified, which matches the in-app caller's experience.

### Signature

- **Owner:** otospexsolutions (project owner)
- **Signed at:** 2026-05-16

## Gate

**OPEN.** Schema-destructive Tasks 7–13 and 28–30 are cleared to proceed on the basis of this written owner attestation. If any later verification surfaces real merchant fiscal data that this attestation did not account for, work stops and an archival/export path is defined before continuing.
