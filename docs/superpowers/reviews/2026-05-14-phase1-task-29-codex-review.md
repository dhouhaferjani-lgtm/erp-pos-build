# Task 29 Codex Adversarial Review

Commit under review: `eb85fb155431f30d4d346e3c00a23be4d5f50b91` on `feat/pos-fiscal-event-engine-phase1`.

Review stance: adversarial spec-compliance pass against Spec v7 §5.0/§14/§14.2/§14.3, Source-of-truth v3 §1/D8/§13.6/D16, Plan Task 29, codebase reality audit, and owner architecture notes. I verified codebase claims against the current worktree and used grep/git-show/cat/nl anchors before citing code.

No BLOCKER or P1 finding found. The new-sale server-authoring surface appears closed for the §14.2 targets, but several skipped-test dispositions overstate the surviving owners and should be repaired before relying on the test matrix as a regression net.

## Findings

### T29-F1

- **Class**: P2
- **Path**: `apps/api/tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php:test_void_route_still_responds_in_phase_1` (`lines 174-193`), `apps/api/tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php:test_process_return_route_still_responds_in_phase_1` (`lines 196-213`)
- **Description**: Plan Task 29 requires the knowingly-retained void/processReturn carve-out to assert success for the retained path (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:2259-2265`). Spec v7 §14.2 says void and processReturn must not be disabled because they are also reached by the online Tauri POS (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:669`). The committed tests only prove the routes do not return HTTP 410 or `NEW_SALE_AUTHORING_RETIRED`. The void test explicitly allows 422, and the return test posts `lines: []`, so it is shaped to prove “not retired”, not “retained online void/return still works”. That leaves a real regression gap in the carve-out the spec singled out as shared with Tauri.
- **Recommended resolution**: Add positive retained-path coverage. Seed an eligible finalized receipt with at least one returnable line and valid terminal/shift/user state, then assert `void` and `processReturn` return 2xx and produce the expected receipt/status side effects. Keep the `not 410` assertion as a secondary guard, not the whole contract.

### T29-F2

- **Class**: P2
- **Path**: `apps/api/tests/Feature/POS/StoreReceiptPaymentsToleranceAuthorizationTest.php:setUp` (`lines 58-68`), `apps/api/tests/Feature/POS/StoreReceiptPaymentsToleranceAuthorizationTest.php:test_seeder_grants_apply_tolerance_to_cashier_role` (`lines 234-244`)
- **Description**: The class-level skip is justified by retirement of `POST /api/v1/pos/receipts/{id}/payments`, but it also disables `test_seeder_grants_apply_tolerance_to_cashier_role`, which never exercises the retired route. That test asserts `RolesAndPermissionsSeeder` grants `pos.tolerance.apply` to the cashier role. Route retirement under §14.2 does not obsolete the seeder/permission contract; it only obsoletes the online payment writer path. The skip therefore drops a non-route assertion without a surviving owner.
- **Recommended resolution**: Move the seeder permission assertion to an unskipped role/permission test, or split the class so only route-dependent short-pay tests are skipped. If short-pay authorization is moving to the device, add a separate device/permission-cache test for that behavior, but keep the server seeder assertion live.

### T29-F3

- **Class**: P2
- **Path**: `apps/api/tests/Feature/POS/StoreReceiptPaymentsInstrumentBindingTest.php:setUp` (`lines 69-78`), `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:validateSaleReceiptPayload` (`lines 143-160`), `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:writePayments` (`lines 536-548`)
- **Description**: The skip message says instrument-binding validation moved to “FiscalPayloadConstraintValidator on the device + FiscalEventEnvelope intake”. The server-side `FiscalPayloadConstraintValidator` does not enforce the old B3/B4 assertions: it validates monetary shape for `payment_lines`, but not `instrument_type`, `instrument_serial`, missing/empty instrument fields, or invalid instrument enum values. `PosCoreReceiptProjection` does have a defense-in-depth throw when voucher-like `method_code` lacks instrument fields, but the old skipped class covered more cases and I did not find matching projection tests for missing/empty `instrument_type`/`instrument_serial` or invalid enum. Given SoT v3 §1 and D8, the device is the authority, but the server mirror still must reject/flag invalid sealed inputs consistently instead of silently losing the fiscal-hash protection for voucher tenders.
- **Recommended resolution**: Add fiscal/projection tests for `store_voucher`, `restaurant_voucher`, and `gift_card` payment lines with missing/empty instrument fields and invalid instrument type, asserting projection rollback/failure. If device-side validation owns part of this, add Tauri tests there as well, but do not cite `FiscalPayloadConstraintValidator` as owning assertions it does not currently implement.

### T29-F4

- **Class**: P2
- **Path**: `apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php:setUp` (`lines 78-89`), `apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php:test_service_rejects_cross_tenant_customer_id_when_form_request_is_bypassed` (`lines 347-393`), `apps/api/app/Modules/POS/Application/Services/ExchangeService.php:processExchange` (`lines 220-255`)
- **Description**: This class-level skip also disables a direct service-bypass tenant-isolation test that does not hit the retired HTTP route. The test calls `ReceiptPaymentService::processReceiptPayments()` directly with a cross-tenant `customerId` and asserts `ModelNotFoundException` plus no payment row. The skip says projector tests carry the contract, but this assertion is specifically about an internal/programmatic service caller bypassing `StoreReceiptPaymentsRequest`. `ReceiptPaymentService` remains production code and is still reachable from `ExchangeService::processExchange` for positive-net exchanges, even though I found no live route/controller caller for `ExchangeService` in this review. That makes the skip broader than the §14.2 route retirement and weakens a security-sensitive defense-in-depth test.
- **Recommended resolution**: Keep the direct service-bypass tenant-isolation test live until Task 30 removes or quarantines the legacy service. If the contract truly moves to fiscal projection only, add an explicit projection/bridge test for cross-tenant partner/customer binding and document why the service path is unreachable.

## Clean / Verified

- Exhaustive grep for `->createReceipt(` and `->finalize(` found no undispositioned live new-sale server-authoring caller beyond the expected §14.3 chokepoints. `ReceiptController::store`, `ReceiptController::storePayments`, and order close are behind 410 route closures; `ReceiptSyncService` is the Task 28 ingestion path; `ReceiptReturnService` is the knowingly-retained void/return carve-out; `ExchangeService` still calls `createReceipt`/`finalize`, but I found no live route/controller caller and this matches the §14.3 Task 30 cleanup queue.
- Spec v7 §14.2 targets for browser web POS were dispositioned: `POSTransactions.tsx` is now a retired-write notice, `CloseOrderButton.tsx` was deleted, `useCloseOrder`/`orderApi.closeOrder` were removed, and the old page was a write surface rather than the preserved read-only receipt listing.
- Tauri new-sale server API callers were not reintroduced. `apps/pos` still uses local-first receipt creation for checkout, and the void/return modal still references the shared online void/return routes that §14.2 preserves.
- Backend route closures for `POST /api/v1/pos/receipts`, `POST /api/v1/pos/receipts/{id}/payments`, and `POST /api/v1/pos/orders/{id}/close` return 410 with `NEW_SALE_AUTHORING_RETIRED` for authenticated callers. Opus’s auth-middleware finding stands as P2: anonymous callers receive 401 before the closure because the routes remain under `auth:sanctum`; I do not escalate it because the retired server-authoring surface is the authenticated POS/API surface.
- Controller docblocks are not stale: `ReceiptController::store`, `ReceiptController::storePayments`, and `OrderController::close` now describe the retired/Task 30 anchor state rather than the old “create receipt and hash chain” behavior.
- CI PG merge-gate was modified in this commit and includes `NewSaleServerAuthoringDispositionTest` in the filtered OR list around `.github/workflows/ci.yml:415-436`.
- Targeted backend verification passed: `php artisan test tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php` completed with 10 warnings and 20 assertions.
- Frontend verification passed for the requested web checks: `pnpm typecheck`, `pnpm lint`, and `pnpm vitest run src/features/pos/hooks/__tests__/useOrders.tenantScope.test.tsx`. Note: `pnpm lint` exits 0 but emits the repository’s existing warning volume; CI appears to rely on the lint ratchet rather than a zero-warning raw lint run.
- Tauri smoke verification passed for the directly relevant checkout test: `pnpm test -- --run src/lib/offline/__tests__/offlineCheckoutService.test.ts` in `apps/pos` ran 8 passing tests. I did not run the full `apps/pos` suite.
- The pre-existing MissingAppKey baseline claim reproduces at `HEAD~1` in a detached temporary worktree after copying `vendor/`: the targeted QR/signing-key tests fail with `Illuminate\Encryption\MissingAppKeyException`, so I did not treat those failures as Task 29 regressions.

VERDICT: APPROVE-WITH-MINOR-EDITS
