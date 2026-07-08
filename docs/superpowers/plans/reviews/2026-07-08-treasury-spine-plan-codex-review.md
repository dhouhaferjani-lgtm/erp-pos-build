# Codex Adversarial Review — Treasury Spine Implementation PLAN

> **Date:** 2026-07-08 · **Reviewer:** Codex (rescue thread)
> **Plan reviewed:** `docs/superpowers/plans/2026-07-08-treasury-spine.md` (Rev 1, commit `449476992`)
> **Verdict:** 2 BLOCKER / 6 HIGH / 5 MED / 2 LOW — **accepted** after firsthand verification of the three load-bearing claims (PaymentController balance-before-GL ordering `:581-676`; MultiPaymentService writes `document.balance_due` not repo balance `:58-305`; `FiscalEventType::DEPOSIT_RECEIPT::isServerOnly()===true`).
> **Reconciliation:** plan Rev 2 (same commit as this file); §Reconciliation-log below maps each finding to the task edit.

---

## Findings (verbatim Codex output)

Severity counts: BLOCKER 2 / HIGH 6 / MED 5 / LOW 2

Index: lock-order deadlock; GL helper migration not executable; freeze misclassifies fiscal returns/deposits; refund idempotency missing; payment/multipayment idempotency unstable; cutover GUC/savepoint gaps; schema gaps; listener/test/commit issues.

**1. BLOCKER: inconsistent advisory-lock vs repository-lock order can deadlock.**
Task 7 takes `pg_advisory_xact_lock(...)` inside GL sealing. Tasks 14/16/17 do `postEntryNow()` before `record()` → order is company advisory lock → `payment_repositories FOR UPDATE`. Task 12 `transfer()` locks both repos sorted before posting one JE → repo lock → advisory lock. `VendorRefundService.php:49-101` locks PO then repository before GL at `:154-166`. Failure: transfer holds repo A waits for GL advisory while an expense/payment holds advisory waits for repo A. Fix: one global order — post GL under advisory first, then lock repositories sorted; transfer acquires the company advisory lock before any repo lock.

**2. BLOCKER: Task 16 assumes the GL entry exists before the movement, but `PaymentController` writes balance before creating GL.** Balance lock/update at `PaymentController.php:581-620`; GL helper calls at `:623-675`. An executor cannot pass `journalEntryId` at the replacement point. Fix: Task 16 must reorder — create payment/allocations, create draft JE, `postEntryNow()`, then `record()` with the JE id, then link `payment.journal_entry_id`. Same explicit draft/post split for Tasks 14/17.

**3. HIGH: no usable draft-creation seam for `postEntryNow()` consumers.** `createFromExpense()`/`createFromIncome()` create a draft then call `postEntryAndDispatchPostedEventAfterCommit()` at `:2332`/`:2429`; payment helpers same at `:604`/`:675`. Tasks 14/16/17 say "GL via postEntryNow()" without specifying whether helpers stop auto-posting, gain a flag, or expose `createDraft*()`. Fix: add a step before convergence splitting every spine helper into `createDraft...()` + caller `postEntryNow()`, or a `PostingMode` param, with tests that the returned JE is posted before the movement insert.

**4. HIGH: freeze policy misclassifies POS fiscal returns.** Task 11 `isProjectionOrigin()` = `sourceType===FiscalEvent`; Task 21 return bridge writes `movement(refund, out)`. A queued POS return into a frozen repo is treated as interactive → `record()` throws → poisons projection retries. Fix: add an explicit `allowWhileFrozen` flag / origin context on `MovementIntent`, set by fiscal projection bridges; returns use `sourceType=Refund` while staying projection-safe.

**5. HIGH: treating all `FiscalEvent` as projection-safe lets server-authored fiscal events bypass freeze.** `FiscalEventType::DEPOSIT_RECEIPT` is server-only (`FiscalEventType.php:74-81`), authored by `VirtualAdminFiscalEventService`. Task 20 maps deposit/account bridges to `sourceType=FiscalEvent`. Server-authored deposit/account events can mutate frozen balances. Fix: explicit context, not source type; mark offline device replay allowed, server-authored admin fiscal events interactive.

**6. HIGH: admin refund idempotency key is impossible as specified.** Task 18 uses `refund:{original_payment_id}:{refund_request_id}` but `PaymentRefundController::partialRefund()` validates only `amount`/`reason` (`:91-98`), `refundPayment()` has no request id (`:61-65`); `refund_request_id` is only on POS/prorated paths. Fix: add required `refund_request_id` to admin full/partial refund requests, persist on refund `payments`, extend the unique index; or define a different stable key and make the API idempotent.

**7. HIGH: payment and multipayment movement keys are not stable across HTTP retries.** `PaymentController::store()` mints a new `Payment` UUID (`:434-450`), no client idempotency key; `MultiPaymentService::createSplitPayment()` mints new UUIDs in a loop (`:58-91`). Retry creates new payment ids so `payment:{new_uuid}:main` / line-index keys do not dedupe. Fix: request-level idempotency keys for payment + multipayment creation, persisted, movement keys based on that stable id + leg.

**8. HIGH: Task 19 is based on false code evidence.** Task 19 says replace balance writes in `MultiPaymentService.php:58-305`; actual code creates `Payment`/`PaymentAllocation` and updates `document.balance_due` only — no repository balance writes; `recordPaymentOnAccount()` has no `repository_id` (`:255-304`). Fix: rewrite Task 19 from actual code — identify which multipayment flows really move repository cash, require/validate repository, create GL, call the port; don't claim "replace balance writes."

**9. MED: `record()` relies on an outer transaction but doesn't enforce it.** If a caller forgets the outer `DB::transaction`, the row lock releases at statement end, the "nested" transaction becomes top-level, atomicity is gone, and Task 22's `SET LOCAL` is unsafe. Fix: assert `DB::transactionLevel() > 0` at the start of `record()`/`transfer()`; test the no-transaction call.

**10. MED: `SET LOCAL app.treasury_movement_port='on'` placement under-specified with savepoints.** If issued inside the idempotency savepoint and a duplicate-key rollback occurs, PG rolls back the local setting; later balance writes could trip the trigger. Fix: `SET LOCAL` before creating the savepoint, inside the caller's outer transaction; add a duplicate-idempotency test with the trigger installed.

**11. MED: Task 1 leaves `payment_repositories.currency` nullable despite the spec requiring it.** Migration adds `->nullable()` and backfills but never alters to non-null; `PaymentRepositoryFactory` has no currency. Fix: after backfill assert no nulls and `ALTER ... SET NOT NULL` on PG; update factory defaults.

**12. MED: Task 2 omits foreign constraints** for `payment_repository_id`, `journal_entry_id`, `reverses_movement_id`. Fix: add FKs where safe; if cross-table lifecycle prevents them, justify + add integrity tests.

**13. LOW: `JournalEntryPosted` listener timing cleanup.** After Task 16 moves posting in-txn, the manual supplier partner-refresh block at `PaymentController.php:650-657` duplicates the `JournalEntryPosted` listener refresh (`EventServiceProvider.php:63-65`). Fix: Task 16 deletes the manual block.

**14. LOW: execution-detail conventions + placeholder helpers.** Test helpers `seedCompanyWithCurrency`/`postedJournalEntryId`/`makeDraftBalancedEntry` are referenced without definitions. Fix: define shared helpers in the task that first introduces them, or use existing factory/seeder patterns. *(Commit-convention sub-claim NOT adopted — repo git history uses `feat(...)`/`fix(...)`/`docs(...)` conventional commits throughout; the `Phase <x.y.z>:` claim is stale.)*

**Verified non-findings (Codex):** `postEntry()` fires `JournalEntryPosted` inline today (Task 6 can preserve non-spine behavior); Laravel nested `beginTransaction()` creates SAVEPOINTs at level>0 and `afterCommit` fires only at outer commit; `hashtextextended(uuid::text,0)` fine, collision risk acceptable; no hidden raw-SQL balance updates Task 22 would miss.

---

## Reconciliation log (finding → plan Rev 2 edit)

| # | Sev | Resolution |
|---|---|---|
| 1 | BLOCKER | New **§Global lock order** invariant: advisory company lock/GL post FIRST, then repository lock(s) sorted-by-id. Tasks 11/12/14/16/17/18 reference it. |
| 2 | BLOCKER | Task 16 rewritten: create payment → draft JE → `postEntryNow` → `record()` → link `payment.journal_entry_id`. |
| 3 | HIGH | Task 6 adds a `PostingMode` param to the spine GL helpers (`createFromExpense`/`createFromIncome`/`createSupplier*`/`createPaymentReceived*`): default = current afterCommit (non-spine unchanged); `SynchronousInTransaction` = draft + `postEntryNow` in caller txn. |
| 4,5 | HIGH | Task 11 drops `isProjectionOrigin()`; `MovementIntent` gains explicit `bool $allowWhileFrozen`. Device-replay bridges (Task 20 receipt, Task 21 return) set true; server-authored deposit/account events + all interactive callers set false. |
| 6 | HIGH | Task 18 adds required `refund_request_id` to admin full/partial refund requests + persists it + unique index; movement key `refund:{original_payment_id}:{refund_request_id}`. |
| 7 | HIGH | New Task 16b: accept a request-level `Idempotency-Key` on payment + multipayment creation, persist on `payments`, base movement keys on it. |
| 8 | HIGH | Task 19 rewritten from actual code (document.balance_due, no repo writes today): the real work is that split/deposit flows create `Payment` rows that never move repository cash — add repository requirement + GL + port; on-account (no repository) documented as a partner-credit, not a cash movement (no repo movement). |
| 9 | MED | Task 11 asserts `DB::transactionLevel() > 0` first; test added. |
| 10 | MED | Task 22 specifies `SET LOCAL` before the savepoint in the outer txn; duplicate-idempotency-with-trigger test. |
| 11 | MED | Task 1 asserts no null currency post-backfill + `SET NOT NULL`; factory updated. |
| 12 | MED | Task 2 adds FKs (`payment_repository_id`, `journal_entry_id`, `reverses_movement_id`). |
| 13 | LOW | Task 16 deletes the manual partner-refresh block. |
| 14 | LOW | New §Executor notes: define referenced test helpers in-task; conventional-commit messages kept (git-history-verified). |
