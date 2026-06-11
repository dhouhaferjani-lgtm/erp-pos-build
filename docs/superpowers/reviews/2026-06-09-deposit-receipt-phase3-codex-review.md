verdict: APPROVE-WITH-MINOR-EDITS

## Findings

### BLOCKER

None.

### P1

None.

### P2

1. Non-atomic projector idempotency can still throw on a direct concurrent `apply()` race.

   - Evidence: `apps/api/app/Modules/POS/Application/Projections/DepositReceiptProjection.php:54` does a preflight `exists()`, `:61` opens a transaction, `:62` repeats the `exists()`, and `:66` inserts. The `pos_deposit_receipts.fiscal_event_id` unique constraint in `apps/api/database/migrations/tenant/2026_06_09_130000_create_pos_deposit_receipts_table.php:17` prevents a double row, but it does not make the loser a no-op.
   - Repro: run two independent PHP workers that both call `app(DepositReceiptProjection::class)->apply($sameEvent)` against the same `FiscalEvent`, with both transactions reaching the second `exists()` before either commits. Both see no row; one insert commits; the other raises a duplicate-key `QueryException` on `fiscal_event_id`. The normal queued pipeline mostly masks this because `ApplyFiscalEventProjectionJob` uses `WithoutOverlapping($projectionRowId)` at `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:205` and also short-circuits fresh `running` rows at `:281`, so I do not see this as a production blocker for the registered projection path. It is still drift from the stated projector contract that a re-delivery "no-ops if a row already exists" under concurrent calls.
   - Fix: make the write itself the idempotency primitive. For example, replace the check-then-create body with an atomic `insertOrIgnore`/`upsert` keyed by `fiscal_event_id`, or catch the duplicate-key `QueryException` for `pos_deposit_receipts.fiscal_event_id` and return after confirming the row now exists. Keep the reader call before the write if malformed payloads must still fail loudly when no projection exists.

### NIT

1. The new projection test does not carry over the analog's failure-mode coverage.

   - Evidence: `apps/api/tests/Feature/Fiscal/DepositReceiptProjectionTest.php:35` through `:80` covers happy path, repeated apply, and registration. The shipped analog additionally asserts malformed canonical payloads fail loudly in `apps/api/tests/Feature/Fiscal/AccountPaymentProjectionTest.php:77`.
   - Repro: delete `customer` or pass a non-`DEPOSIT_RECEIPT` event to `DepositReceiptProjection::apply()`. The current implementation does fail via `CanonicalPayloadReader::forDepositReceipt()` at `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:148` and `:155`, but Phase 3 has no regression test locking that behavior.
   - Fix: add tests for missing customer / null payload and wrong event type, expecting `InvalidArgumentException`, mirroring the account-payment analog.

## False-positive checks I ran

- Diffed Phase 3 projection/model/migration against the shipped account-payment analog. Drift is limited to the expected name/table/UUID field/event-type substitutions plus the tenant-FK explanatory comment.
- Checked registration: `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:49` tags `DepositReceiptProjection`, and its `priority()` is `50` at `apps/api/app/Modules/POS/Application/Projections/DepositReceiptProjection.php:47`.
- Checked registry behavior: `activeProjectorsFor()` filters by `handlesEventType()` and sorts by priority/name in `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:166` and `:233`.
- Checked boundary imports with `rg -P`: no Treasury, Accounting, Partner, Customer, Contact, B2B imports or container-helper calls in `DepositReceiptProjection` or `DepositReceipt`.
- Checked data fidelity: the projector persists `deposit_receipt_uuid`, customer/partner id, customer name, amount, currency, and `payload_snapshot` from the canonical DTO at `apps/api/app/Modules/POS/Application/Projections/DepositReceiptProjection.php:66`.
- Checked failure modes: wrong event type and null payload fail loudly through `CanonicalPayloadReader::forDepositReceipt()` at `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php:148` and `:155`, then the job records failure and retries/dead-letters via `ApplyFiscalEventProjectionJob.php:392`.
- Ran `php artisan test --filter DepositReceiptProjectionTest`; the 3 tests passed with existing PHPUnit doc-comment metadata warnings.
- Attempted `composer test -- --filter DepositReceiptProjectionTest`; the Composer script does not pass PHPUnit's `--filter` through and failed with "The \"--filter\" option does not exist."

---

## Resolution (Claude, 2026-06-09)

**P2-1 — non-atomic idempotency could throw on a concurrent apply() race: FIXED.**
`DepositReceiptProjection::apply()` now wraps the insert and treats the
`pos_deposit_receipts.fiscal_event_id` UNIQUE constraint as the real idempotency
primitive: a `QueryException` is caught, and if a row now exists for the event the
call no-ops (a concurrent writer won the race); otherwise it re-throws so the job
retries. The preflight + in-transaction `exists()` checks are retained for the common
sequential re-delivery. (The repeated `exists()` is now a `@phpstan-impure` helper so
PHPStan does not constant-fold it across the DB write.)

**NIT-1 — failure-mode coverage: ADDED.**
Two regression tests lock the fail-loud contract that the Phase 1 canonical reader
already enforces: `test_projection_fails_loud_on_a_non_deposit_receipt_event`
(wrong event type → `InvalidArgumentException`, no row) and
`test_projection_fails_loud_on_a_null_payload` (parse-failure/NULL payload →
`InvalidArgumentException`, no row).

Note: a true concurrent-race test is not deterministically reproducible in the PHPUnit
harness (single process, no real threads) — same as the shipped account-payment
analog. The sequential idempotency test plus the catch path cover the observable
contract.

Gates after fixes: 5/5 projection tests green (14 assertions); Pint pass; PHPStan L8
clean; deptrac unchanged (0 new violations); full Fiscal feature suite re-run below.
