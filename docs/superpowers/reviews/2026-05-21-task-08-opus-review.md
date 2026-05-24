# Task 08 Opus Second-Pass Adversarial Review: Server Parser + POS-Core ACCOUNT_PAYMENT Receipt Projection

Date: 2026-05-21  
Reviewer: Opus-style second-pass via Codex  
Implementation commit: `ebbd529c9 Phase 2.8.1: Project account payment receipts`  
Codex self-review: `docs/superpowers/reviews/2026-05-21-task-08-codex-review.md`  
Verdict: APPROVE

## Scope Reviewed

- `apps/api/app/Modules/POS/Application/Projections/AccountPaymentReceiptProjection.php`
- `apps/api/app/Modules/POS/Domain/AccountPaymentReceipt.php`
- `apps/api/database/migrations/2026_05_21_130000_create_pos_account_payment_receipts_table.php`
- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- `apps/api/tests/Feature/Fiscal/AccountPaymentProjectionTest.php`
- `apps/api/tests/Feature/Fiscal/AccountPaymentD16Test.php`
- `apps/api/tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php`
- `docs/superpowers/reviews/2026-05-21-task-08-codex-review.md`

## Findings

No blockers. No request-change findings.

## Axis Review

### 1. D16 Bounded Modules

PASS. `AccountPaymentReceiptProjection` imports only Fiscal canonical reading, Fiscal event type/model, POS receipt storage, the shared projector contract, and `DB` (`AccountPaymentReceiptProjection.php:7-12`). It returns `null` from `requiresModule()` (`AccountPaymentReceiptProjection.php:38-41`), so it is POS-core always-active rather than Treasury-gated.

The write path creates only a `pos_account_payment_receipts` row from the sealed canonical view (`AccountPaymentReceiptProjection.php:54-72`). It performs no Treasury, Accounting, Customer, Partner, Contact, or B2B lookup and has no hidden operational payment/FIFO effect. The explicit POS-only test also asserts no `payments` rows are written (`AccountPaymentProjectionTest.php:89-104`), and the static D16 guard blocks forbidden module imports and container helpers in the projector source (`AccountPaymentD16Test.php:18-28`, `AccountPaymentD16Test.php:36-57`).

### 2. Live Wiring / Dead Path

PASS. `POSServiceProvider` tags `AccountPaymentReceiptProjection::class` under `FiscalEventProjector::class` during `register()` (`POSServiceProvider.php:45-50`). The fiscal registry is built from that tagged set (`FiscalServiceProvider.php:33-38`), and the production tagged-set regression test now asserts `pos_core_account_payment_receipt` is present (`FiscalEventProjectionRegistryTest.php:251-275`).

The projector's own contract is also covered: name, event type, priority, and always-active module status are asserted in `AccountPaymentProjectionTest.php:89-99`.

### 3. Idempotency

PASS. The durable idempotency key is `pos_account_payment_receipts.fiscal_event_id`, declared unique in the migration (`2026_05_21_130000_create_pos_account_payment_receipts_table.php:17`). The projector checks for an existing row before parsing and again inside the transaction before `create()` (`AccountPaymentReceiptProjection.php:50-59`), and the focused test verifies repeat `apply()` calls do not duplicate rows (`AccountPaymentProjectionTest.php:66-75`).

Residual risk: this implementation does not use the lower-level `INSERT ... ON CONFLICT DO NOTHING RETURNING id` pattern used by `PosCoreReceiptProjection`; two direct concurrent calls to `apply()` could make the losing caller see a unique-constraint exception instead of a clean no-op. Under the actual projection job path, same-row concurrent execution is serialized/short-circuited by `ApplyFiscalEventProjectionJob` (`ApplyFiscalEventProjectionJob.php:231-320`), and the DB unique constraint still prevents duplicate rows. This is not a blocker for Task 08, but if this projector gets additional direct callers, the insert primitive should be hardened.

### 4. Cross-Tenant / FK Safety

PASS. The projection copies `tenant_id`, `company_id`, and `fiscal_event_id` from the already-ingested `FiscalEvent` (`AccountPaymentReceiptProjection.php:62-65`). Customer identity is stored from the canonical payload snapshot (`AccountPaymentReceiptProjection.php:66-71`) into plain string columns (`2026_05_21_130000_create_pos_account_payment_receipts_table.php:18-23`), with no live customer/partner FK lookup surface.

The table has FKs to `tenants`, `companies`, and `fiscal_events` (`2026_05_21_130000_create_pos_account_payment_receipts_table.php:26-28`) plus tenant/company-leading lookup indexes (`2026_05_21_130000_create_pos_account_payment_receipts_table.php:30-31`). Residual hardening note: the FK to `fiscal_events` is by `id` only, not a composite `(fiscal_event_id, tenant_id, company_id)` constraint. Because this projector derives all three values from the same `FiscalEvent` object and the table is not user-written, I do not consider that a Task 08 defect; it is only a future direct-write hardening point.

### 5. Fail-Loud Behavior

PASS. The projector uses `CanonicalPayloadReader::forAccountPayment()` (`AccountPaymentReceiptProjection.php:54`) rather than silently inspecting ad hoc array keys. The reader rejects wrong event types and null payloads (`CanonicalPayloadReader.php:107-123`), and `AccountPaymentPayload::fromArray()` requires the `customer`, `payment`, `local_balance_snapshot`, `seller`, and `staleness` blocks (`AccountPaymentPayload.php:44-80`). The customer DTO then requires `customer_id`, `customer_sync_status`, and `name` (`AccountPaymentCustomerDTO.php:28-40`).

The task-specific fail-loud test removes `customer` and expects an exception mentioning the customer block (`AccountPaymentProjectionTest.php:77-87`). There is no placeholder customer fallback.

### 6. Contract Drift

PASS. The projection maps from the canonical account-payment view (`AccountPaymentReceiptProjection.php:54-71`), not an independent parser. The persisted `payload_snapshot` comes from `AccountPaymentPayload::toArray()` (`AccountPaymentReceiptProjection.php:71`; `AccountPaymentPayload.php:86-110`), keeping the row aligned with the server parser's DTO contract.

### 7. Verification Adequacy / Test Gaps

PASS for Task 08 scope. The committed tests cover the required cases: printable projection, idempotency, fail-loud missing customer snapshot, POS-only/no Treasury effect, D16 static guard, and production tagged-set reachability (`AccountPaymentProjectionTest.php:47-104`; `AccountPaymentD16Test.php:31-57`; `FiscalEventProjectionRegistryTest.php:251-275`).

I ran the focused suites:

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountPaymentProjectionTest.php tests/Feature/Fiscal/AccountPaymentD16Test.php tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php
```

Result: `OK (22 tests, 47 assertions)`.

Residual test gaps:

- No concurrent duplicate-apply test; acceptable because the production job path serializes a projection row, but worth adding if direct projector calls become supported.
- The fail-loud suite checks missing `customer`; parser/DTO unit tests cover broader ACCOUNT_PAYMENT shape elsewhere, so this projection test does not need to duplicate every malformed field case.

## Verdict

APPROVE. The implementation satisfies the Task 08 scope: POS-core ACCOUNT_PAYMENT printable/read projection, always-active registry wiring, D16 boundary preservation, idempotency by fiscal event, sealed-payload customer facts, and fail-loud canonical parsing. No blocker found.
