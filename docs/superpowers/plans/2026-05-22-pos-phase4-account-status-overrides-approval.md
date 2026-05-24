# POS Phase 4 Account Status Overrides Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Phase 4 account-status lifecycle, scoped supervisor approvals, override fiscal events, and legacy `DEPOSIT`/`PAYOUT` cash drawer approval controls without implementing cash drawer movement fiscal events.

**Architecture:** Device fiscal authority remains the default. `ACCOUNT_STATUS_CHANGED` is the only new server-only administrative carve-out and is authored through a virtual admin terminal with Phase-1-style locking, validation, and ingest rejection on device sync. Phase 4 fiscal event implementation is atomic: vocabulary, DTOs, strict parser constraints, DB CHECK migration, PHP/TS drift tests, and server-only classification land together.

**Tech Stack:** Laravel 12/Pest/PostgreSQL for API and fiscal ingestion; Vite/React/TypeScript/Tauri SQLite/Vitest for POS; pnpm workspace commands from repo root; Composer commands in `apps/api`.

---

## Review-Locked Decisions

- **D-Q1 supervisor PIN identity:** per-supervisor PIN on the user record is locked unless owner overrides before implementation.
- **Cash drawer D-Q dependency:** Z-report/session chain owns future `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, and `CASH_CORRECTION` movement events unless owner overrides during the Z-report spec. Phase 4 only approval-gates legacy `DEPOSIT`/`PAYOUT`.
- **No partial fiscal implementation:** no Phase 4 fiscal type may return implemented=true before DTO, parser, DB CHECK, and drift tests pass in the same commit.
- **No mutable-only correction/safe-drop:** Phase 4 must not add `SAFE_DROP`, `CASH_CORRECTION`, or generic correction operation semantics.

## File Structure

- Fiscal vocabulary/parsing: `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php`, `FiscalEventPayloadRegistry.php`, `StrictCanonicalParser.php`, `FiscalPayloadConstraintValidator.php`, fiscal DTOs, fiscal migrations, POS `FiscalEventPayloadRegistry.ts`, POS payload builders/tests.
- Virtual admin terminal: `TerminalType.php`, terminal migrations/model/resource/controllers as needed, `VirtualAdminTerminalResolver.php`, `VirtualAdminFiscalEventService.php`.
- Account status: `CustomerAccountStatus.php`, Partner migration/model/factory/resource, `CustomerAccountStatusService.php`, POS customer mirror migration/repository/types.
- Approval primitive: `ApprovalScope.php`, `OperatorApprovalDecision.php`, `OperatorApproval.php`, `PinVerifier.php`, `ManagerPinController.php`, `PosAuthController.php`, POS operator PIN migration/repository, POS `operatorApproval/*`.
- Override flows: POS `accountChargeService.ts`, `creditRulesEngine.ts`, `paymentStore.ts`, `HomePage.tsx`, discount modals, refund/void flow components and stores.
- Cash drawer controls: `CashDrawerService.php`, `CashDrawerController.php`, `CashDrawerOperation.php`, POS `cashDrawerRepository.ts`, `cashDrawerApi.ts`, `cashDrawerStore.ts`.
- Guards: fiscal D16 tests, registry drift tests, `apps/api/scripts/check-accountCharge-chokepoints.sh`, CI PG filter if new PG-only tests are added.

---

### Task 1: Install Dependencies And Baseline

**Files:** none modified.

- [ ] **Step 1: Install dependencies**

Run:

```bash
pnpm install
cd apps/api && composer install
```

Expected: both commands exit 0; lockfiles do not change.

- [ ] **Step 2: Baseline focused tests**

Run:

```bash
pnpm --filter @autoerp/pos test --run src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts src/lib/accountCharge/__tests__/creditRulesEngine.test.ts
cd apps/api && php artisan test tests/Unit/Fiscal/FiscalEventTypeTest.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/POS/PinVerifierTest.php
```

Expected: PASS, or record pre-existing failures before code changes.

### Task 2: Atomic Fiscal Vocabulary, DTOs, Parser Constraints, DB CHECK, Drift Tests

**Files:**
- Modify `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php`
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php`
- Modify `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`
- Create fiscal DTOs for `AccountStatusChangedPayload`, `OperatorApprovalGrantedPayload`, `OverrideCreditLimitPayload`, `OverrideAccountStatusPayload`, `OverrideDiscountLimitPayload`, `OverrideTenderTolerancePayload`, `OverrideVoidOrReturnPayload`
- Add migration updating `fiscal_events.event_type` CHECK constraint
- Modify `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts`
- Create POS payload builders under `apps/pos/src/lib/fiscal/payloads/`
- Modify/add PHP and TS registry/parser/drift tests

- [ ] **Step 1: Write failing tests**

Add tests proving:

- all Phase 4 event names exist in PHP and TS;
- `ACCOUNT_STATUS_CHANGED` is implemented and server-only;
- `OPERATOR_APPROVAL_GRANTED` and `OVERRIDE_*` are implemented and device-authorable;
- `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, and `CASH_CORRECTION` remain unimplemented;
- strict parser rejects missing supervisor identity, extra keys, malformed timestamps, and cross-tenant target ids;
- DB CHECK constraint list includes the new event types.

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd apps/api && php artisan test tests/Unit/Fiscal/FiscalEventTypeTest.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Feature/Fiscal/FiscalEventsTableTest.php
pnpm --filter @autoerp/pos test --run src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts
```

Expected: failures identify missing event cases, DTOs, parser keys, or CHECK values.

- [ ] **Step 3: Implement atomically**

Add cases:

```php
case ACCOUNT_STATUS_CHANGED = 'ACCOUNT_STATUS_CHANGED';
case OPERATOR_APPROVAL_GRANTED = 'OPERATOR_APPROVAL_GRANTED';
case OVERRIDE_CREDIT_LIMIT = 'OVERRIDE_CREDIT_LIMIT';
case OVERRIDE_ACCOUNT_STATUS = 'OVERRIDE_ACCOUNT_STATUS';
case OVERRIDE_DISCOUNT_LIMIT = 'OVERRIDE_DISCOUNT_LIMIT';
case OVERRIDE_TENDER_TOLERANCE = 'OVERRIDE_TENDER_TOLERANCE';
case OVERRIDE_VOID_OR_RETURN = 'OVERRIDE_VOID_OR_RETURN';
```

Add `isServerOnly()` with `ACCOUNT_STATUS_CHANGED`, extend DTO registry and `FiscalPayloadConstraintValidator::PAYLOAD_KEYS`, and add per-event constraint clauses before returning implemented=true. Rename POS constants to `IMPLEMENTED_EVENT_TYPES` and `SERVER_ONLY_EVENT_TYPES`.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php apps/api/app/Modules/Fiscal/Domain/DTOs apps/api/database/migrations apps/api/tests/Unit/Fiscal apps/api/tests/Feature/Fiscal apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts apps/pos/src/lib/fiscal/payloads apps/pos/src/lib/fiscal/__tests__
git commit -m "Phase 4.1.0: Add approval fiscal event contracts"
```

### Task 3: Server-Only Ingest Boundary For Administrative Events

**Files:**
- Modify `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- Modify/add `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php`
- Modify `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`
- Modify `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts`

- [ ] **Step 1: Write failing boundary tests**

Use the real route and body shape:

```php
$this->postJson('/api/v1/pos/sync/fiscal-events', [
    'envelopes' => [$this->validEnvelopeFor('ACCOUNT_STATUS_CHANGED')],
])->assertUnprocessable();

expect(FiscalEvent::query()->where('event_type', 'ACCOUNT_STATUS_CHANGED')->count())->toBe(0);
```

In POS, assert `FiscalEventEngine.append('ACCOUNT_STATUS_CHANGED', ...)` throws `ServerAuthoredEventTypeError`.

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd apps/api && php artisan test tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php
pnpm --filter @autoerp/pos test --run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts
```

Expected: server currently accepts/parses or quarantines rather than rejecting before insert.

- [ ] **Step 3: Implement guard**

In `OutboxIngestor::ingest()`, after wire-shape validation and before hash/parse/insert:

```php
if ($envelope->eventType->isServerOnly()) {
    return IngestionResult::rejectedServerOnly($envelope->eventType);
}
```

Use the project’s existing result/controller pattern; the HTTP response must be 422 and persist no `fiscal_events` row.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts
git commit -m "Phase 4.1.1: Reject server-only events at ingest"
```

### Task 4: Virtual Admin Terminal Storage And Resolver

**Files:**
- Modify `apps/api/app/Modules/POS/Domain/Enums/TerminalType.php`
- Modify `apps/api/app/Modules/POS/Domain/Terminal.php`
- Add terminal migration for `virtual_admin` type/marker and uniqueness per tenant/company
- Create `apps/api/app/Modules/POS/Application/Services/VirtualAdminTerminalResolver.php`
- Modify terminal sync/resource/controller tests

- [ ] **Step 1: Write failing tests**

Assert:

- resolver creates exactly one virtual admin terminal per tenant/company;
- resolver is idempotent under repeated calls;
- virtual admin terminals are excluded from claimable/active POS terminal sync;
- sale/account/cash/session services reject virtual admin terminal ids.

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd apps/api && php artisan test tests/Feature/POS/TerminalLifecycleEventsTest.php tests/Feature/POS/TerminalDeviceLookupTest.php tests/Feature/Fiscal/AccountStatusChangedServerOnlyTest.php
```

Expected: `virtual_admin` type/marker and resolver do not exist.

- [ ] **Step 3: Implement resolver and guards**

Add `TerminalType::VirtualAdmin = 'virtual_admin'` or persisted marker with equivalent behavior. Add DB uniqueness for `(tenant_id, company_id, type='virtual_admin')`. Guard checkout/cash/session code paths by rejecting virtual admin terminal ids before business writes.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/POS/Domain/Enums/TerminalType.php apps/api/app/Modules/POS/Domain/Terminal.php apps/api/app/Modules/POS/Application/Services/VirtualAdminTerminalResolver.php apps/api/database/migrations apps/api/tests/Feature/POS apps/api/tests/Feature/Fiscal
git commit -m "Phase 4.1.2: Add virtual admin terminals"
```

### Task 5: Account Status Server Lifecycle And Fiscal Status Change

**Files:**
- Create `CustomerAccountStatus.php`
- Add Partner account-status migration/model/factory/resource fields
- Create `CustomerAccountStatusService.php`
- Create `VirtualAdminFiscalEventService.php`
- Create status lifecycle/fiscal tests

- [ ] **Step 1: Write failing tests**

Assert existing partners default `active`, transition requires actor/reason, invalid transitions fail, status mutation and fiscal append roll back together, and concurrent transitions serialize account-status version.

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd apps/api && php artisan test tests/Feature/Partner/PartnerAccountStatusTest.php tests/Feature/POS/PosCustomerSyncControllerTest.php tests/Feature/Fiscal/AccountStatusChangedServerOnlyTest.php tests/Feature/Fiscal/AccountStatusChangedConcurrencyTest.php
```

- [ ] **Step 3: Implement lifecycle**

Add enum values `active`, `suspended`, `closed`, `disputed`. `CustomerAccountStatusService::transition()` validates transition and runs one `DB::transaction()` that locks the partner, updates status fields, and appends `ACCOUNT_STATUS_CHANGED` through the virtual admin terminal.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Partner apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php apps/api/database/migrations apps/api/tests/Feature/Partner apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php apps/api/tests/Feature/Fiscal
git commit -m "Phase 4.1.3: Seal customer account status changes"
```

### Task 6: POS Customer Mirror And Fail-Closed Status Rules

**Files:**
- Modify `apps/pos/src/lib/db/migrations.ts`
- Modify `apps/pos/src/lib/customer/customerTypes.ts`
- Modify `apps/pos/src/lib/db/repositories/customerRepository.ts`
- Modify `apps/pos/src/lib/customer/customerSyncService.ts`
- Modify `apps/pos/src/lib/accountCharge/creditRulesEngine.ts`
- Modify related POS tests

- [ ] **Step 1: Write failing tests**

Assert migration v42 adds account-status fields, sync rejects unknown status, `closed` always rejects, and `suspended`/`disputed` reject without approval evidence.

- [ ] **Step 2: Run failing tests**

Run:

```bash
pnpm --filter @autoerp/pos test --run src/lib/db/__tests__/migrations.integration.test.ts src/lib/customer/__tests__/customerSyncService.test.ts src/lib/db/repositories/__tests__/customerRepository.test.ts src/lib/accountCharge/__tests__/creditRulesEngine.test.ts
```

- [ ] **Step 3: Implement mirror/rules**

Add `account_status`, `account_status_changed_at`, `account_status_reason`, and `account_status_version`. Add rejection codes `account_suspended`, `account_closed`, `account_disputed`; run these checks after tenant/company and `is_active`, before credit-limit math.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/customer apps/pos/src/lib/db/repositories/customerRepository.ts apps/pos/src/lib/accountCharge/creditRulesEngine.ts apps/pos/src/lib/customer/__tests__ apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts apps/pos/src/lib/accountCharge/__tests__/creditRulesEngine.test.ts
git commit -m "Phase 4.1.4: Mirror account status to POS"
```

### Task 7: Scoped Approval Primitive And Offline PIN Mirror Contract

**Files:**
- Create `ApprovalScope.php`, `OperatorApprovalDecision.php`, `OperatorApproval.php`
- Add `operator_approvals` migration
- Modify `PinVerifier.php`, `ManagerPinController.php`, `VerifyManagerPinRequest.php`, `PosAuthController.php`
- Modify permission seeders
- Modify POS operator PIN migration/repository and create `operatorApproval/*`
- Add API and POS tests

- [ ] **Step 1: Write failing contract tests**

Assert API `pinData()` returns `tenant_id`, `company_ids`, `terminal_ids`, `approval_scopes`, `approval_scope_permissions_fetched_at`, and server time. Assert approval verification request requires `company_id`, `terminal_id`, `approval_scope`, `target_event_type`, `target_reference_id`, and reason. Assert same-tenant different-company supervisor returns `scope_mismatch` before hash comparison.

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd apps/api && php artisan test tests/Unit/POS/PinVerifierTest.php tests/Feature/POS/ManagerPinControllerTest.php tests/Feature/POS/PosAuthSyncPinsTest.php
pnpm --filter @autoerp/pos test --run src/lib/db/repositories/__tests__/queuedPinUpdateRepository.test.ts src/lib/operatorApproval/__tests__/approvalVerifier.test.ts
```

- [ ] **Step 3: Implement contract**

Derive company eligibility from tenant/company assignments already used by terminal and POS user access; if the codebase only models tenant-wide users today, emit the current terminal company as the sole allowed company for POS sync and require explicit owner follow-up before broader multi-company approval. Rate-limit key is `(tenant_id, terminal_id, supervisor_user_id, approval_scope)`. Offline verifier rejects tenant/company/terminal/scope mismatch before bcrypt and marks stale mirrors as `server_quarantined` on sync.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/POS/Domain/Enums apps/api/app/Modules/POS/Domain/OperatorApproval.php apps/api/database/migrations apps/api/app/Modules/POS/Application/Services/PinVerifier.php apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php apps/api/app/Modules/POS/Presentation/Requests/VerifyManagerPinRequest.php apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php apps/api/database/seeders apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/db/repositories/operatorPinRepository.ts apps/pos/src/lib/operatorApproval apps/api/tests/Unit/POS apps/api/tests/Feature/POS apps/pos/src/lib/operatorApproval/__tests__
git commit -m "Phase 4.1.5: Scope supervisor approval PINs"
```

### Task 8: Credit-Limit And Account-Status Account-Charge Overrides

**Files:**
- Modify account-charge PHP/TS payload DTOs
- Modify POS `creditRulesEngine.ts` and `accountChargeService.ts`
- Modify account-charge tests

- [ ] **Step 1: Write failing override tests**

Assert:

- `credit_limit_exceeded` needs `OPERATOR_APPROVAL_GRANTED` -> `OVERRIDE_CREDIT_LIMIT` -> `ACCOUNT_CHARGE`;
- `suspended` and `disputed` need `OPERATOR_APPROVAL_GRANTED` -> `OVERRIDE_ACCOUNT_STATUS` -> `ACCOUNT_CHARGE`;
- `closed` always rejects;
- `approved_with_override` is valid only with exact target customer, amount, account status, policy version, and override event id.

- [ ] **Step 2: Run failing tests**

Run:

```bash
pnpm --filter @autoerp/pos test --run src/lib/accountCharge/__tests__/creditRulesEngine.test.ts src/lib/accountCharge/__tests__/accountChargeService.test.ts src/lib/accountCharge/__tests__/accountChargeFullFlow.test.ts
cd apps/api && php artisan test tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php
```

- [ ] **Step 3: Implement atomic authoring**

Write approval event, override event, and account charge in one local transaction. If the target event fails after approval/override authoring, mark approval/override orphaned and block checkout retry until operator cancels or retries.

- [ ] **Step 4: Run tests and chokepoint**

Run:

```bash
apps/api/scripts/check-accountCharge-chokepoints.sh
pnpm --filter @autoerp/pos test --run src/lib/accountCharge/__tests__/creditRulesEngine.test.ts src/lib/accountCharge/__tests__/accountChargeService.test.ts src/lib/accountCharge/__tests__/accountChargeFullFlow.test.ts
cd apps/api && php artisan test tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php
```

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/accountCharge apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php apps/pos/src/lib/accountCharge/__tests__ apps/api/tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php
git commit -m "Phase 4.1.6: Author account charge overrides"
```

### Task 9: Discount, Tender Tolerance, Void, And Return Approval Hooks

**Files:**
- Modify `apps/pos/src/pages/HomePage.tsx`
- Modify `apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx`
- Modify `apps/pos/src/components/organisms/LineDiscountModal/LineDiscountModal.tsx`
- Modify `apps/pos/src/stores/paymentStore.ts`
- Modify `apps/pos/src/components/pos/CashTenderedModal.tsx`
- Modify `apps/pos/src/components/pos/VoidReturnModal.tsx`
- Modify refund flow stores/components as needed
- Add/modify tests for these files

- [ ] **Step 1: Write failing hook tests**

Assert discount beyond cashier/terminal limit requires `OVERRIDE_DISCOUNT_LIMIT`, tender short-pay outside tolerance requires `OVERRIDE_TENDER_TOLERANCE`, and void/return authorization requires `OVERRIDE_VOID_OR_RETURN`. Each constrained operation must reject a bare verified PIN without matching approval/override event evidence.

- [ ] **Step 2: Run failing tests**

Run:

```bash
pnpm --filter @autoerp/pos test --run src/components/organisms/DiscountModal/__tests__/DiscountModal.test.tsx src/components/organisms/LineDiscountModal/__tests__/LineDiscountModal.test.tsx src/stores/__tests__/paymentStore.cashTenderedAmount.test.ts src/stores/__tests__/refundFlowStore.test.ts src/components/pos/__tests__/RefundConfirmModal.test.tsx
```

- [ ] **Step 3: Implement hooks**

Thread approval evidence through each UI/store call site and author `OPERATOR_APPROVAL_GRANTED` plus the exact `OVERRIDE_*` event before mutating cart/payment/refund state. Match evidence by tenant/company/terminal/scope/amount/policy/local reference.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/pages/HomePage.tsx apps/pos/src/components/organisms/DiscountModal apps/pos/src/components/organisms/LineDiscountModal apps/pos/src/stores/paymentStore.ts apps/pos/src/components/pos/CashTenderedModal.tsx apps/pos/src/components/pos/VoidReturnModal.tsx apps/pos/src/stores apps/pos/src/components/pos/__tests__ apps/pos/src/components/organisms/DiscountModal/__tests__ apps/pos/src/components/organisms/LineDiscountModal/__tests__
git commit -m "Phase 4.1.7: Require approval evidence for POS overrides"
```

### Task 10: Legacy Cash Drawer Approval Controls

**Files:**
- Modify cash drawer PHP model/service/controller/resource
- Add approval evidence migration
- Modify POS cash drawer repository/API/store
- Add API and POS tests

- [ ] **Step 1: Write failing tests**

Assert legacy `PAYOUT` and `DEPOSIT` require reason, over-threshold operations require `OPERATOR_APPROVAL_GRANTED` evidence, closed shift writes are blocked, tenant/company/terminal mismatch is rejected, and `SAFE_DROP`/`CASH_CORRECTION` operations are not accepted.

- [ ] **Step 2: Run failing tests**

Run:

```bash
cd apps/api && php artisan test tests/Feature/POS/CashDrawerControllerTest.php tests/Unit/POS/CashDrawerServiceTest.php
pnpm --filter @autoerp/pos test --run src/lib/db/repositories/__tests__/cashDrawerRepository.recovery.test.ts
```

- [ ] **Step 3: Implement controls**

Add approval columns to `pos_cash_drawer_operations` and offline queue. Carry `approval_id`, `approval_fiscal_event_id`, `approval_scope`, `approval_supervisor_user_id`, and approval target hash through local queue, API payload, server validation, and resource output. Do not add safe-drop/correction operation types.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/POS/Domain/CashDrawerOperation.php apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php apps/api/app/Modules/POS/Presentation/Resources/CashDrawerOperationResource.php apps/api/database/migrations apps/pos/src/lib/db/repositories/cashDrawerRepository.ts apps/pos/src/api/cashDrawerApi.ts apps/pos/src/stores/cashDrawerStore.ts apps/api/tests/Feature/POS/CashDrawerControllerTest.php apps/api/tests/Unit/POS/CashDrawerServiceTest.php apps/pos/src/lib/db/repositories/__tests__/cashDrawerRepository.recovery.test.ts
git commit -m "Phase 4.1.8: Gate legacy cash drawer operations"
```

### Task 11: Integrity Policy Defaults

**Files:**
- Create `FiscalIntegrityAnomaly.php`
- Create `FiscalIntegrityPolicyAction.php`
- Create `FiscalIntegrityPolicyService.php`
- Add tests

- [ ] **Step 1: Write failing tests**

Assert FR/TN defaults: hash/parse/time anomalies accept+quarantine; sequence gaps require acknowledgment; signature invalid is inactive without provider.

- [ ] **Step 2: Run failing tests**

Run: `cd apps/api && php artisan test tests/Unit/Fiscal/FiscalIntegrityPolicyServiceTest.php`

- [ ] **Step 3: Implement enum-backed defaults**

No admin UI in Phase 4.

- [ ] **Step 4: Run tests**

Run Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Fiscal/Domain/Enums/FiscalIntegrityAnomaly.php apps/api/app/Modules/Fiscal/Domain/Enums/FiscalIntegrityPolicyAction.php apps/api/app/Modules/Fiscal/Application/Services/FiscalIntegrityPolicyService.php apps/api/tests/Unit/Fiscal/FiscalIntegrityPolicyServiceTest.php
git commit -m "Phase 4.1.9: Add fiscal integrity policy defaults"
```

### Task 12: D16, Drift, CI, Reviews, PR

**Files:**
- Add/modify fiscal D16 and projection registry tests
- Modify `.github/workflows/ci.yml` only for PG-only test filter
- Create implementation review docs and PR body

- [ ] **Step 1: Run guard tests**

Run:

```bash
cd apps/api && php artisan test tests/Feature/Fiscal/AccountStatusOverrideD16Test.php tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php
apps/api/scripts/check-accountCharge-chokepoints.sh
```

Expected: PASS after fixing import/registry drift failures.

- [ ] **Step 2: Run full verification**

Run:

```bash
pnpm build
pnpm lint
pnpm test
pnpm typecheck
cd apps/api && composer test && ./vendor/bin/phpstan
```

Expected: PASS.

- [ ] **Step 3: Run Codex self-adversarial implementation review**

Record findings in `docs/superpowers/reviews/2026-05-22-pos-phase4-implementation-codex-review.md`. Fix BLOCKER/P1 and rerun impacted tests.

- [ ] **Step 4: Run independent second-pass implementation review**

Dispatch a reviewer against the diff, v3 spec, and this plan. Fix BLOCKER/P1 and rerun impacted tests.

- [ ] **Step 5: Open PR and configure auto-merge outside dry-run window**

Run:

```bash
git push -u origin feat/fiscal-phase-4-account-status-overrides-approval
gh pr create --base dev --head feat/fiscal-phase-4-account-status-overrides-approval --title "Phase 4.1.0: Add account status approvals and overrides" --body-file docs/superpowers/pr/2026-05-22-phase4-account-status-overrides.md
gh pr merge --auto --merge
```

Do not run the merge command during the 2026-05-25 Tunisia + France soft-launch dry-run window.
