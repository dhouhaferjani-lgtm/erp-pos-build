# Task 29 — Round-2 Opus Re-Review

**Commit reviewed:** `314dbc781` (11 files, +267/-47)
**Round-1 reviewed:** `eb85fb155` (Opus + Codex APPROVE-WITH-MINOR-EDITS; 0 BLOCKER / 0 P1 / 6 P2 / 3 P3)
**Plan reference:** `apps/erp.fiscal-phase1/docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` Task 29
**Reviewer role:** Opus round-2 re-reviewer (verifying actual closures vs. claimed closures)

---

## Summary

Round-2 substantively closes 4 of 4 Codex P2 findings + 3 of 3 Opus P3 findings + adds explicit-citation deferrals for 2 Opus P2 findings. The new test coverage (void/return happy-path + 2 voucher-instrument projection pins) is assertion-strong (positive 2xx + DB side-effects for the carve-outs, explicit `InstrumentRequiredException` typed catch for the projector pins) — neither falls into the Task 22 deferred-bail-out anti-pattern. Skip-narrowing on the two route-dependent suites correctly resurrects the non-route assertions (seeder-permission + service-bypass tenant-isolation) without leaving residual class-level skips dead-coding the live assertions.

No new BLOCKER, P1, or P2 finding. The Task 23 r2/r3 "fixes-introduce-new-defects" pattern did not recur.

**Verdict:** APPROVE.

---

## Round-1 finding closure verification

### Codex T29-F1 — void/processReturn happy-path coverage — CLOSED

- **Void test:** Round-2 seeds via `Receipt::factory()` (default state: `is_voided=false`, `posted_at=now()`, valid tenant/company/location/terminal/cashier from setUp). POSTs to `/api/v1/pos/receipts/{id}/void` with `['reason' => 'Test void']`. Asserts `$response->assertStatus(200)` + four JSON-path assertions (`is_voided=true`, `void_reason='Test void'`, non-null `voided_at`) AND DB-row side-effects via `DB::table('pos_receipts')->where('id', $receipt->id)->first()` checking `is_voided`, `voided_at` non-null, `voided_by=$this->user->id`, `void_reason='Test void'`. ✓
- **Process-return test:** Seeds the sale receipt PLUS a `ReceiptLine` with explicit fiscal-shape numerics (`'5.000'`, `'10.000'`, etc. per the `bcformat` precision rule), POSTs to `/api/v1/pos/receipts/{id}/return` with a valid `ReturnReason::Defective->value` + `lines => [['line_id' => $line->id, 'quantity' => '2']]`. Validated against `StoreReturnRequest::rules()` (`lines.*.line_id required|uuid` + `lines.*.quantity required|numeric|min:0.001`). Asserts `$response->assertStatus(201)` + `receipt_type=Return`, `original_receipt_id=$saleReceipt->id`, `return_reason=Defective`. Database side-effect verified via `assertDatabaseHas('pos_receipts', [...])` with the same four columns. ✓
- **Phpunit verification:** `NewSaleServerAuthoringDispositionTest` 10/10 passing, 31 assertions (up from r1's 20 — the +11 assertions match the new positive-path coverage). ✓
- No `assertSuccessful()` (would have masked 3xx) — uses explicit status codes. ✓

### Codex T29-F2 — seeder permission un-skip — CLOSED

- Class-level `markTestSkipped` in `setUp()` removed. The 4 route-dependent test methods (`test_short_pay_denied_*`, `test_short_pay_authorized_*`, `test_exact_tender_*`, `test_short_pay_returns_409_*`) gained per-method `$this->markTestSkipped(self::ROUTE_SKIP_REASON)` at method-body top. ✓
- `test_seeder_grants_apply_tolerance_to_cashier_role` is live (no skip), seeds `RolesAndPermissionsSeeder`, sets `PermissionRegistrar` team id, and asserts `$role->hasPermissionTo('pos.tolerance.apply')`. ✓
- Phpunit run: 5/5 tests, 1 assertion, 4 skipped. Pattern matches the claim. ✓
- The `app(PermissionRegistrar::class)` use is pre-existing convention (3 sites in the file pre-r2; not introduced by Task 29).

### Codex T29-F3 — instrument-binding citation + new projection tests — CLOSED

- **Citation accuracy:** Skip message in `StoreReceiptPaymentsInstrumentBindingTest::setUp` now cites `PosCoreReceiptProjection::writePayments` (the actual defense site) + names both new test methods. The misleading "FiscalPayloadConstraintValidator owns it" claim from r1 is gone. ✓
- **Test 1 (`test_voucher_payment_line_without_instrument_type_is_rejected_by_projection`):** Builds a real `SALE_RECEIPT` via the existing `storeSaleReceiptFiscalEvent(paymentLinesOverride: [...])` helper (real `FiscalEvent` Eloquent model — no mocking), `method_code='store_voucher'`, missing `instrument_type`, present `instrument_serial='SV-MISSING-TYPE'`. Calls `$this->app->make(PosCoreReceiptProjection::class)->apply($event)` and uses explicit `try { ... ; $this->fail(...); } catch (InstrumentRequiredException $e) { ... }` — assertion-strong (typed catch, fail-if-no-throw, `assertStringContainsString('store_voucher', $e->getMessage())`). Plus three rollback assertions (`pos_receipts`, `pos_receipt_payments`, `pos_receipt_lines` all 0 rows). ✓
- **Test 2 (`test_voucher_payment_line_without_instrument_serial_is_rejected_by_projection`):** Symmetric pin for the missing-`instrument_serial` branch. Same shape, same rigor. ✓
- **Defense path verified:** `PosCoreReceiptProjection::writePayments` lines 544-549 confirm `PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)` gate + `InstrumentRequiredException::forMethodCode($methodCode)` throw on null/empty type-OR-serial. The new tests cover both branches of that `||` predicate. ✓
- **Phpunit run:** `PosCoreReceiptProjectionTest` 23/23 passing, 86 assertions (up from r1's 21 tests). ✓

### Codex T29-F4 — service-bypass tenant-isolation un-skip — CLOSED

- Class-level skip removed from `setUp`; 5 route-dependent methods gained per-method skips. ✓
- `test_service_rejects_cross_tenant_customer_id_when_form_request_is_bypassed` is live. The test calls `ReceiptPaymentService::processReceiptPayments()` directly (not via HTTP), smuggles tenant A's `partnerA->id` into tenant B's context (`CompanyContext` set to tenant B), `$this->expectException(ModelNotFoundException::class)` + defensive `assertSame(0, ReceiptPayment count)` in a `finally` block. ✓
- Phpunit run: 6/6, 5 skipped + 1 live with 2 assertions. The live test passes. ✓
- Security-sensitive defense-in-depth preserved per Codex's escalation rationale (`ReceiptPaymentService` still reachable via `ExchangeService::processExchange`).

### Opus F3 — controller docblock past-tense — CLOSED

- `ReceiptController::store` summary: `[RETIRED §14.2] Created a new POS receipt.` (was: `Create a new POS receipt.`).
- `ReceiptController::storePayments` summary: `[RETIRED §14.2] Processed payments for a receipt.` (was: `Process payments for a receipt.`).
- Bodies were already past-tensified in r1. Now grammatically consistent (summary + body both past tense + retirement-prefixed). ✓

### Opus F4 — IMPLEMENTATION_SUMMARY.md retirement note — CLOSED

- `apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:349` line now reads: `POST /api/v1/pos/receipts - Create receipt [RETIRED §14.2 (Task 29) — 410 NEW_SALE_AUTHORING_RETIRED; device-authored via FiscalEventEngine.append()]`. Cite-strong (§14.2, Task 29, the 410 code, AND the device-authority replacement path). ✓

### Opus F5 — POSTransactions design tokens — CLOSED

- `bg-gray-50` → `colors.neutral[50]` (verified `colors.neutral[50]='bg-gray-50'` at `apps/web/src/lib/designTokens.ts:58`). ✓
- `text-gray-900` → `textColors.primary` (verified `textColors.primary='text-gray-900'` at `designTokens.ts:87`). ✓
- `text-gray-600` → `textColors.tertiary` (verified `textColors.tertiary='text-gray-600'` at `designTokens.ts:89`). ✓
- `text-amber-500` retained — implementer's claim is accurate: there is no amber token in the file; the project's ESLint design-token rule does not include amber in the legacy-color denylist. Acceptable.
- Import added: `import { colors, textColors } from '@/lib/designTokens'`. ✓
- `pnpm typecheck` clean. ✓

### Opus P2 deferrals — CITED CORRECTLY

- **F1 (FK coverage gap):** Cited in the round-2 commit message ("F1: cross-tenant FK coverage gap on 6 non-payment-method axes → Task 22 / Task 30 follow-up") AND in `apps/api/docs/sessions/2026-05-14-fiscal-preflight-signoff.md` Web-POS Surface "Round-2 closures" block with a detailed rationale (DB-FK defense intact; explicit projector-level assertions queued for the Task 22/30 projection-coverage extension; round-2 scope absorbed too broadly otherwise). ✓
- **F2 (auth middleware):** Inline comments added at `apps/api/app/Modules/POS/routes.php:107-115` and `apps/api/app/Modules/POS/routes_orders.php:38-42`. Both comments explain the auth-middleware contract, acknowledge Codex r1 + Opus r1, and note Codex did not escalate. ✓ Also captured in the preflight signoff "Round-2 closures" block.

---

## New-issues scan (Task 23 r2/r3 pattern)

- **No new test scaffolding mocks production-code that should be real.** The new void/return tests use real `Receipt::factory()` + real `ReceiptLine::create()`. The new projector tests use the existing `storeSaleReceiptFiscalEvent` helper that builds a real `FiscalEvent` Eloquent model. `$this->app->make(PosCoreReceiptProjection::class)` resolves the real projector — no `Mockery`, no `vi.mock` analog. ✓
- **No un-skipped methods papered over with `markTestIncomplete`.** Grep across the 5 touched test files: 0 hits for `markTestIncomplete`. ✓
- **New projection tests are assertion-strong, not assertion-weak.** Both new methods use typed `catch (InstrumentRequiredException $e)` + explicit `$this->fail(...)` if no throw + message-content check + 3-table rollback verification. They would not pass if the projector silently swallowed the void. ✓
- **Docblock past-tense edits internally consistent.** Both store + storePayments have `[RETIRED §14.2]` prefix AND past-tense summary AND past-tense body. No mixed-tense residue. ✓
- **Design-token migration did not break the page.** Pnpm typecheck clean (no output = success). The `bg-gray-50` → `${colors.neutral[50]}` template-literal interpolation pattern is standard for the codebase. ✓
- **`app(PermissionRegistrar::class)` / `app(CompanyContext::class)` / `app(ReceiptPaymentService::class)` use in resurrected tests is pre-existing convention** in the file (Spatie PermissionRegistrar needs a team-id setter at test bootstrap; the alternative is the trait-driven `Sanctum::actingAs` flow which doesn't address the service-bypass scenario being tested). Not a Task 29 R2 introduction; not a new finding.
- **No `git add -A` / scope creep.** Commit lists exactly 11 files, all on the §14.2 surface or directly cited in r1 review findings. ✓

---

## Sanity check

- `./vendor/bin/phpunit tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php` → 10 / 10 OK, 31 assertions. ✓
- `./vendor/bin/phpunit tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php` → 23 / 23 OK, 86 assertions. ✓
- `./vendor/bin/phpunit tests/Feature/POS/StoreReceiptPaymentsToleranceAuthorizationTest.php` → 5 tests, 4 skipped + 1 live (seeder-permission), 1 assertion. ✓
- `./vendor/bin/phpunit tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php` → 6 tests, 5 skipped + 1 live (service-bypass), 2 assertions. ✓
- `./vendor/bin/phpunit --testsuite=Feature --filter="Fiscal"` → 320 tests, 0 failures, 953 assertions, 44 skipped, 2 incomplete (incomplete count is pre-existing baseline; no new incompletes from r2).
- `./vendor/bin/pint --test` on `ReceiptController.php` + `routes.php` + `routes_orders.php` → pass.
- `./vendor/bin/phpstan analyse --level=8` on `ReceiptController.php` + `routes.php` + `routes_orders.php` → 0 errors.
- `pnpm typecheck` (`apps/web`) → clean.
- Verified design-token symbols exist at the claimed positions in `apps/web/src/lib/designTokens.ts`.
- Verified `PosCoreReceiptProjection::writePayments` lines 544-549 contain the `requiresInstrumentForMethodCode` gate + `InstrumentRequiredException::forMethodCode` throw the new tests target.

---

## Anti-pattern checklist

- [x] No `app()` helper introduced in production code (CLAUDE.md rule 13). Test-side use of `app(...)` is pre-existing convention and bounded to test bootstrap (PermissionRegistrar team-id set, CompanyContext setup, service-resolution for direct-bypass tests).
- [x] No magic strings. `NEW_SALE_AUTHORING_RETIRED` + `ROUTE_SKIP_REASON` constants in both touched test classes.
- [x] No `git add -A` / scope creep — commit lists 11 specific files; every file is on the r1 review-finding surface.
- [x] New tests follow real-Eloquent + factory pattern (no API-shape mocking).
- [x] No `markTestIncomplete` smuggled in.

VERDICT: APPROVE
