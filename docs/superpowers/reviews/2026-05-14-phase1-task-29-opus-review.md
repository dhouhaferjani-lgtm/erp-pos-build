# Task 29 — Opus spec-compliance review

**Commit reviewed:** `eb85fb155` (23 files, +690/-979)
**Spec authority:** v7 §14.2 + §14.3 (chokepoint completeness rule) + §14 disposition table
**Plan reference:** `apps/erp.fiscal-phase1/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` Task 29 (line 2231+)
**Reviewer role:** Opus first-stage spec-compliance reviewer (Codex adversarial review running in parallel)

---

## Summary

Task 29 closes one of the two v5/v6 BLOCKER root-causes — the "missed server-authoring caller" defect class — by dispositioning every §14.2 new-sale write caller (web POS + Tauri-online + order-close → SALE_RECEIPT). The implementation is route-level-closure-first (returns HTTP 410 with structured `NEW_SALE_AUTHORING_RETIRED` error before FormRequest validation runs), with controller methods preserved as docblock anchors for Task 30's §14.3 chokepoint grep manifest. The test surface is a 10-case discriminated-union matrix (Task 20 standing pattern) covering retired + carve-out + read-only + sync-handoff routes. The CI PG-merge-gate is extended in the same commit (Tasks 10/11/19/21/22 standing pattern). 30 obsolete test methods across 8 PHP feature files are skipped with §14.2 citations + surviving-owner pointers.

**Verdict:** APPROVE-WITH-MINOR-EDITS. The §14.2 disposition is correct, complete across all four target classes (web POS + Tauri-online + order-close + backend routes), the test matrix is comprehensive, and the obsolete-test skips are well-cited. Two P2 concerns (cross-tenant FK coverage gap for non-payment-method axes; route closures inherit auth middleware so anon callers get 401-not-410) and three P3 doc-nits round it out. No BLOCKERs, no P1s.

---

## Findings

### CLEAN — what passes spec compliance

- **C1. §14.2 web-frontend surface fully dispositioned.** `POSTransactions.tsx` collapsed to a retirement-notice page (735 → ~50 lines); `receiptApi.ts` `createReceipt` + `processReceiptPayments` deleted with `§14.2` doc anchor; `CloseOrderButton.tsx` deleted; `OrderPanel.tsx` close affordance + handler + `canClose` predicate removed; `useOrders.useCloseOrder` removed; `orderApi.closeOrder` removed. `useOrders.tenantScope.test.tsx` updated in lock-step (mock slot + describe-block close mutation removed; counter assertions decremented by one step). No dangling import or reference of the deleted symbols remains in non-comment code (grep across `apps/web/src` clean).
- **C2. §14.2 backend surface fully dispositioned via route-level closures.** All three new-sale write routes return HTTP 410 + `NEW_SALE_AUTHORING_RETIRED`:
  - `POST /api/v1/pos/receipts` (`apps/api/app/Modules/POS/routes.php`)
  - `POST /api/v1/pos/receipts/{id}/payments` (same file)
  - `POST /api/v1/pos/orders/{id}/close` (`routes_orders.php`)
  The route-closure-over-controller-early-return choice is correct and explicitly justified in the commit message: closures short-circuit before `StoreReceiptRequest` / `StoreReceiptPaymentsRequest` validation runs, so the disposition code is delivered regardless of payload shape (a 422-from-FormRequest would have masked the retirement). Controller methods (`ReceiptController::store`, `storePayments`, `OrderController::close`) preserved with §14.2 docblock anchors for Task 30's chokepoint grep manifest.
- **C3. §14.2 knowingly-retained carve-outs preserved.** `POST /pos/receipts/{id}/void` and `POST /pos/receipts/{id}/return` are NOT 410'd — the test `test_void_route_still_responds_in_phase_1` + `test_process_return_route_still_responds_in_phase_1` pin that the routes assert `status() !== 410` AND `error.code !== 'NEW_SALE_AUTHORING_RETIRED'`. Carve-out rationale (SALE_VOID/REFUND_RECEIPT/PARTIAL_REFUND are Phase 2+ reserved; both routes are shared with offline Tauri POS via `VoidReturnModal.tsx`) is cited in the test docblock + commit message.
- **C4. §14.2 read-only surface preserved.** Tests pin `GET /pos/receipts` (index), `GET /pos/receipts/{id}` (show), `GET /pos/receipts/{id}/pdf` (PDF stream). The web shop-management POS section that *lists* transactions (§1.3) is preserved through `ReceiptSearchPage.tsx` — verified via `apps/web/src/routes/index.tsx:234,2297` still lazy-loading the page.
- **C5. `routes.php:45` deferral premise verified (Task 19 standing pattern).** Current `routes.php:45` is `Route::post('/pos/terminals/web', [TerminalController::class, 'getOrCreateWebTerminal'])` — terminal acquisition, not a new-sale authoring route. The commit message records the verification explicitly (`routes.php:45 confirmed unrelated`), and the preflight signoff notes the line-number drift from the spec's enumeration. ✓
- **C6. `/pos/receipts/sync` correctly left alone.** Test `test_pos_receipts_sync_route_is_still_registered` pins that Task 28's surface is untouched by Task 29 (the route response code is not retired-class). Scoping discipline is preserved.
- **C7. Order CRUD non-close routes intact.** Test `test_order_crud_non_close_routes_still_function` pins `POST /pos/orders` still returns 201. The spec explicitly preserves the rest of `routes_orders.php` (`/pos/orders`, `/pos/orders/{id}/lines`, `/pos/orders/{id}/send-to-kitchen`, `/pos/orders/{id}/cancel`).
- **C8. §14.3 chokepoint disposition completeness (the v5/v6 defect class).** The two server-side `SALE_RECEIPT` authoring chokepoints (`ReceiptCreationService::createReceipt()` + `ReceiptFinalizationService::finalize()`) are reached by four §14.3-enumerated callers: `ReceiptController::store` (b), `OrderToReceiptService::convertToReceipt` via OrderManagementService/OrderController::close (b), `ReceiptPaymentService::finalize` via storePayments (b), `ReceiptSyncService::finalize` via /pos/receipts/sync (Task 28). All four are now reachable only through routes returning 410 — except `ReceiptSyncService` which is Task 28's scope. The `ReceiptReturnService::finalize` (c) carve-out is correctly preserved. `ExchangeService` (service-level, no live route per the spec's re-grep note) is a Task 30 cleanup target. Verified by re-grepping `apps/api/app` for `->createReceipt(` and `->finalize(`.
- **C9. Discriminated-union test matrix (Task 20 standing pattern).** 10 cases / 20 assertions covering 3 retired + 2 carve-out + 3 read-only + 1 order-CRUD + 1 sync-handoff. Each case has an explanatory docblock citing why it's there. The matrix is exhaustive — every §14.2 surface class has a pin.
- **C10. CI PG-merge-gate extended in same commit (Tasks 10/11/19/21/22 standing pattern).** `.github/workflows/ci.yml:436` adds `NewSaleServerAuthoringDispositionTest` to the `--filter` regex; explanatory comment at `:415-424` records the rationale (PG-only schema constraints on `pos_receipts` / `pos_orders` UUID CHECK / enum columns). ✓
- **C11. Sign-off updated with disposition outcome.** `apps/api/docs/sessions/2026-05-14-fiscal-preflight-signoff.md` Web-POS Surface section gains a "Disposition outcome (recorded by Task 29 implementer, 2026-05-19)" block summarising every concrete change: route closures, controller anchors, web frontend deletions, knowingly-retained, read-only, `routes.php:45` resolution, D8 coexistence resolution, obsolete-test cleanup, CI extension.
- **C12. D8 coexistence resolution recorded.** Commit message + sign-off both cite "§14 disposition (b) — disabled/deferred". No two-model coexistence: the new-sale server-authoring path now returns 410 unconditionally; the device-authority chain is the single fiscal pattern (`[SoT D8]`).
- **C13. Test scaffolding follows standing patterns.** Uses `RolesAndPermissionsSeeder`, real Eloquent factories (`Tenant`, `Company`, `Location`, `Terminal`, `User`, `Shift`, `Receipt`, `Order`), `Sanctum::actingAs`, `PermissionRegistrar::setPermissionsTeamId($tenant->id)`, manager role assignment. No `mockery`, no `app()` helper, no `git add -A` (commit lists 23 specific files).
- **C14. Pre-existing baseline error claim verified.** Confirmed `MissingAppKey` environmental issue reproduces on `ReceiptLookupServiceTest` / `ReceiptPrintQrTokenTest` without Task 29 in the loop (verified via direct phpunit run). 29-error count is consistent across both PR and pre-PR states. Not a Task 29 regression.
- **C15. Test passes locally.** Re-ran `./vendor/bin/phpunit tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php` → 10 tests, 20 assertions, OK. ✓

---

### P2 — should address (no merge block)

**F1. (P2) Cross-tenant FK defense coverage gap on non-payment-method axes.**
**Path:** `apps/api/tests/Feature/POS/PosStabilizationTenantIsolationTest.php` (8 method-skips) vs. `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php` + `TreasuryReceiptBridgeTest.php`
**Description:** The PosStabilization skips claim cross-tenant defense for `product_id` / `customer_id` / `contact_id` / `modifier_group_id` / `modifier_id` / `composite_item_id` "moves to PosCoreReceiptProjection (Task 21)" or "device-authority payload validation (FiscalPayloadConstraintValidator)". Verified: the surviving `PosCoreReceiptProjectionTest::test_cross_tenant_payment_method_id_is_rejected_fail_closed` covers only `payment_method_id`; `TreasuryReceiptBridgeTest` covers `payment_method_id` + `repository_id`; `FiscalEventIngestionEndpointTest` covers envelope-level `tenant_id`. None of the six additional FK axes the PosStabilization skips covered has an explicit projector-level cross-tenant assertion in the surviving suite. The defense likely exists at the DB-FK level (those columns have FK constraints to tenant-scoped tables that Task 21 verified), but the *explicit test assertion* did not migrate. This isn't a Phase 1 regression (the routes are 410, so the attack vector is gone for now), but the skip rationale slightly overstates surviving coverage.
**Resolution:** Either (a) extend `PosCoreReceiptProjectionTest` with a parametric test covering the six additional FK axes (10–15 lines, one assertion per axis), or (b) document the coverage gap in the Task 29 sign-off + leave a tracking note on Task 30 / Task 22 for the round-2 PR. Acceptable as-is for merge; flag for round-2 follow-up.

**F2. (P2) Route closures inherit auth middleware; anon callers get 401, not 410.**
**Path:** `apps/api/app/Modules/POS/routes.php:95+`, `routes_orders.php:28+`
**Description:** All three 410 closures are inside the `Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])` group at `routes.php:29`. An anonymous caller hits 401 from `auth:sanctum` before reaching the closure. Best practice for HTTP 410 Gone is to return the disposition unconditionally — the resource is permanently retired, regardless of who asks. The current behaviour means an automated tool probing for retired endpoints gets a misleading 401, not 410. This is a minor contract divergence from the spec's "retired/rejected" framing.
**Resolution:** Move the three closures OUT of the auth middleware group (or apply `withoutMiddleware('auth:sanctum')` on the closure). Optional — the test pins post-auth behaviour, which is consistent with the in-app caller's experience. Flag if a Codex round-2 review wants stricter HTTP semantics; otherwise defer to operational discretion.

---

### P3 — doc-nits

**F3. (P3) Past-tensified docblock summary lines on retired controller methods.**
**Path:** `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:278-283,499-506`
**Description:** The `store()` and `storePayments()` docblock bodies have been past-tensified ("Created a receipt..." / "Supported split payments... Created Treasury Payment records and General Ledger entries.") while the imperative summary line ("Create a new POS receipt." / "Process payments for a receipt.") is unchanged. The intent is to signal "this method used to do X but no longer does", but the result is grammatically inconsistent (imperative summary + past-tense body). Minor stylistic nit; the §14.2 docblock block underneath makes the disposition clear unambiguously.
**Resolution:** Either revert the past-tense edits and rely solely on the §14.2 block for the retirement signal, OR also past-tensify the summary lines. Defer to author preference; not a merge concern.

**F4. (P3) Outdated reference to retired route in IMPLEMENTATION_SUMMARY.md.**
**Path:** `apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:349`
**Description:** Contains a stale line: `POST /api/v1/pos/receipts - Create receipt`. The doc file is historical/exploratory but reading it now misrepresents the post-§14.2 state.
**Resolution:** Either add a §14.2 retirement note inline, or move the file to a `legacy/` subdir. Lowest-priority nit.

**F5. (P3) Hardcoded Tailwind color classes in retirement-notice page.**
**Path:** `apps/web/src/pages/POS/POSTransactions.tsx:30-43`
**Description:** Uses raw Tailwind color classes (`bg-gray-50`, `text-gray-900`, `text-gray-600`, `text-amber-500`) rather than design tokens from `lib/designTokens.ts`. The repo's CLAUDE.md rule 18 says "When editing `.tsx` files in `apps/web/src/`, migrate hardcoded Tailwind color classes ... to design tokens" — though it qualifies that "Only migrate classes in code you are already touching." Since the file was rewritten from scratch, all 5 hardcoded color classes are new code and would be flagged by the ESLint design-token rule (if it's enforced as an error on new feature dirs, per CLAUDE.md). The implementer's preflight report says lint is clean, so this may already be exempt.
**Resolution:** Replace with `tokens.bgSurface` / `textColors.primary` / etc. from `@/lib/designTokens`. Optional — depends on whether ESLint flags it; the file is intentionally minimal and will be rewritten in §18 web-POS parity. Lowest-priority.

---

## Anti-pattern checklist (handoff §4.2)

- [x] No `app()` helper introduced (CLAUDE.md rule 13). Constructor injection via Laravel container used throughout the test setup.
- [x] No magic strings — `NEW_SALE_AUTHORING_RETIRED` appears in 3 closures + 3 test assertions; could be promoted to a class constant, but the repetition is intentional and grep-discoverable. Acceptable as-is.
- [x] Test scaffold uses `RolesAndPermissionsSeeder` + real Eloquent models (per project memory), not mocks. ✓
- [x] No `git add -A` / `git add .` — commit lists 23 specific files; no scope creep into unrelated areas.
- [x] Commit didn't include unrelated changes — every file is on the §14.2 surface or a §14.2-dependent skip target.

---

## Sanity check

- Re-ran `./vendor/bin/phpunit tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php` → 10 / 10 passing, 20 assertions. ✓
- Re-greppped `->createReceipt(` and `->finalize(` callsites across `apps/api/app/Modules/POS/` — 9 hits, all enumerated in spec §14.3 table or marked Task 28 / Task 30 scope.
- Confirmed pre-existing `MissingAppKey` baseline errors reproduce in `ReceiptLookupServiceTest` / `ReceiptPrintQrTokenTest` without Task 29 in the loop — not a regression.
- Verified `POSTransactions.tsx` retirement-notice page collapsed correctly (47 lines vs original 735) and renders a translation-keyed notice.
- Verified `CloseOrderButton.tsx` fully deleted (0 references in non-comment code).
- Verified `ReceiptSearchPage` route still wired in `apps/web/src/routes/index.tsx:234,2297` — read-only listing surface preserved per spec §1.3.

VERDICT: APPROVE-WITH-MINOR-EDITS
