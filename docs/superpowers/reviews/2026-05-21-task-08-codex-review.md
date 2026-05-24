# Task 08 Codex Self-Adversarial Review — Server Parser + POS-Core ACCOUNT_PAYMENT Receipt Projection

Date: 2026-05-21  
Reviewer: Codex  
Implementation commit: `ebbd529c9 Phase 2.8.1: Project account payment receipts`  
Verdict: APPROVE

## Scope Reviewed

- Added `pos_account_payment_receipts` printable/read projection table.
- Added `AccountPaymentReceipt` Eloquent model.
- Added `AccountPaymentReceiptProjection` POS-core projector for `ACCOUNT_PAYMENT`.
- Registered projector in `POSServiceProvider`.
- Added projection tests, D16 source guard, and production tagged-set live-wiring assertion.

## Adversarial Checks

### D16 / bounded-modules seam

Result: PASS.

- `AccountPaymentReceiptProjection` imports Fiscal/POS/Shared/DB only.
- It does not import Treasury, Accounting, Partner, Customer, Contact, or B2B modules.
- `requiresModule()` returns `null`, so the POS-core printable projection always runs even in POS-only deployments.
- Treasury payment creation and FIFO allocation remain deferred to a future gated bridge; this implementation writes no `payments` rows.
- `AccountPaymentD16Test` grep-guards forbidden module imports plus `app(`, `App::make(`, and `resolve(` in the projector source.

### Constructor injection only

Result: PASS.

- Production projector uses constructor injection for `CanonicalPayloadReader`.
- No production service locator usage was introduced.
- Tests resolve through the Laravel test container, matching existing feature-test practice; the guard is applied to production projector source.

### Dead-path rebuild / live caller

Result: PASS.

- `POSServiceProvider` tags `AccountPaymentReceiptProjection` under `FiscalEventProjector::class`.
- `FiscalEventProjectionRegistryTest::test_tagged_set_contains_both_pos_core_and_treasury_bridge_in_production` now also asserts `pos_core_account_payment_receipt` is present in the production tagged set.
- The projector contract is explicit: `name() === pos_core_account_payment_receipt`, `handlesEventType(ACCOUNT_PAYMENT) === true`, `handlesEventType(SALE_RECEIPT) === false`, `priority() === 50`, and `requiresModule() === null`.

### Cross-tenant / FK safety

Result: PASS.

- Projection copies `tenant_id` and `company_id` from the already-ingested `FiscalEvent`.
- `fiscal_event_id` is FK-constrained and unique, making the fiscal event the authoritative tenant/company anchor.
- No live customer/partner lookup is performed, so there is no cross-tenant FK lookup surface for customer identity.
- `customer_id` is intentionally stored as sealed payload snapshot text, not as a live FK, because Phase 2 supports pending/offline customer mirror identities.
- Query indexes include `(tenant_id, company_id, customer_id)` and `(tenant_id, company_id, account_payment_uuid)`.

### Fail-loud vs silent downgrade

Result: PASS.

- `CanonicalPayloadReader::forAccountPayment()` rejects non-`ACCOUNT_PAYMENT` events and null payloads.
- Missing `customer` snapshot raises `InvalidArgumentException`; the projector does not synthesize placeholder customer data.
- The test `test_projection_fails_loud_on_missing_customer_snapshot` locks this behavior.

### Idempotency

Result: PASS.

- Table has a unique `fiscal_event_id`.
- Projector returns early when a projection row already exists and double-checks inside the DB transaction before creating.
- Test `test_projection_is_idempotent_by_fiscal_event_id` locks repeat-apply behavior.
- Note: concurrent duplicate insertion would be stopped by the unique constraint. This is acceptable for the current registry/job idempotency model; no silent duplicate row is possible.

### Contract drift

Result: PASS.

- Projector reads only via `CanonicalPayloadReader::forAccountPayment()`, the same server parser/canonical view used by surrounding Phase 2 contract code.
- Persisted `payload_snapshot` is `AccountPaymentPayload::toArray()`, not an ad hoc partial mapper.
- Tests compare the stored snapshot to the original canonical event payload.

### Reconciliation classification

Result: PASS.

- The POS-core projection is read/print only.
- No FIFO allocation, Treasury Payment, GL row, or reconciliation effect is introduced here.
- This preserves roadmap v2 classification: ACCOUNT_PAYMENT event is offline-authoritative; FIFO allocation remains server-reconciled in the later Treasury bridge task.

### Discriminated-union matrix / skip discipline

Result: NOT APPLICABLE / PASS.

- This task does not introduce a discriminated-union DTO.
- No new skips were added.

## Verification

- Focused TDD red before implementation: missing projection classes failed as expected.
- Focused Task 8 tests: `OK (6 tests, 23 assertions)`.
- Surrounding fiscal projection/ingestor/job tests: `OK (54 tests, 221 assertions)`.
- Added live-wiring focused suite after hardening: `OK (22 tests, 47 assertions)`.
- Backend full Fiscal/POS gate: `OK, 1107 tests, 3712 assertions, 107 skipped, 2 incomplete, 16 PHPUnit deprecations`.
- Backend PHPStan L8 on POS/Fiscal modules: PASS.
- Focused PHPStan on touched files/tests: PASS.
- Pint on touched files: PASS.
- POS `pnpm test`: `162` files, `1445` tests passed.
- POS `pnpm typecheck`: PASS.
- POS `pnpm lint`: PASS with `41` pre-existing warnings, `0` errors.
- §14.3 chokepoint gate + `.PASS_2B_PENDING` absence: PASS.
- `git diff --check`: PASS.

## Verdict

APPROVE. I found and fixed one hardening gap before this review: production tagged-set coverage now asserts the ACCOUNT_PAYMENT projector is live. No remaining blocker or request-change issue found.
