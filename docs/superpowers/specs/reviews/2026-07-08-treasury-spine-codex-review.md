# Codex Adversarial Review — Treasury Money-Movement Spine (Phase 1)

> **Date:** 2026-07-08 · **Reviewer:** Codex (rescue thread `019f4076-ca32-7262-a289-f3d89c21deb6`)
> **Spec reviewed:** `docs/superpowers/specs/2026-07-07-treasury-spine-design.md` (Rev 1, commit `085f09210`)
> **Verdict:** 2 BLOCKER / 8 HIGH / 4 MED / 0 LOW — **all 14 findings ACCEPTED** after firsthand verification of the two load-bearing claims (afterCommit GL posting at `GeneralLedgerService.php:83-94` + `createFromExpense:2332`; DepositBridge `account_id`-only check at `TreasuryDepositBridge.php:196-198`).
> **Reconciliation:** spec Rev 2 §16 (committed together with this file).
> **Process note:** Codex sandbox was read-only; the review body was retrieved via thread resume. The raw Codex output contained an injected fake "system-reminder" block, which the rescue subagent flagged and discarded as untrusted tool output.

---

# Adversarial Review: Phase-1 Treasury Money-Movement Spine (verbatim Codex output)

Spec reviewed: `docs/superpowers/specs/2026-07-07-treasury-spine-design.md`
Supporting audit: `docs/superpowers/audits/2026-07-07-treasury-industry-gap-audit/README.md`
Review date: 2026-07-08
Mode: adversarial, source-verified against `apps/api` and `apps/web`

## Summary

Severity counts:

- BLOCKER: 2
- HIGH: 8
- MED: 4
- LOW: 0

The spine is directionally right, but the draft spec still assumes atomicity, idempotency, and migration properties that the current code does not provide. The most dangerous gap is that many GL helpers still defer posting with `DB::afterCommit()`, so a new movement port can easily commit balance/movement state while the GL post fails later. The second major class of risk is projection behavior: a frozen repository or deterministic-key collision in a Horizon projection turns into retry/dead-letter behavior, not a clean operator workflow.

## Findings

### 1. BLOCKER: `{movement + GL + subledger}` atomicity is false for the main admin and expense/income flows unless GL posting APIs change

Failure scenario: `PaymentController::store()` records a payment, updates allocations/subledger, writes the repository balance or future movement, creates a draft GL entry, and returns. The GL helper schedules the actual post with `DB::afterCommit()`. If posting fails after commit because the future period guard rejects the date, the hash-chain allocator fails, or an account is invalid, the movement and payment allocation are already committed.

Evidence:
- `PaymentController::store()` runs a transaction at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:425`, updates document allocations at `:505-557`, updates repository balance at `:586-605`, then calls GL helpers at `:638-669`.
- `createSupplierPaymentJournalEntry()` creates the draft entry inside its own transaction at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:562-602`, then defers posting at `:604`.
- `createPaymentReceivedJournalEntry()` does the same at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:629-675`.
- `postEntryAndDispatchPostedEventAfterCommit()` explicitly registers `DB::afterCommit()` when already in a transaction at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:83-94`.
- `ExpenseService::post()` wraps the expense status change, GL creation, and repository outflow in one transaction at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:160-205`, but `createFromExpense()` defers posting at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2260-2332`.
- `IncomeService::post()` has the same pattern at `apps/api/app/Modules/Income/Application/Services/IncomeService.php:127-156` and `GeneralLedgerService.php:2349-2429`.

Fix recommendation: split GL helpers into explicit `createDraft*()` and `postSynchronously*()` variants, or add a `postNow: true` command path that posts inside the caller transaction. Phase 1 acceptance tests must inject a GL-post failure after the movement write point and prove no payment allocation, movement, cached balance, or journal entry remains committed.

### 2. BLOCKER: The proposed period-close guard will make the after-commit GL problem worse

Failure scenario: the new movement port checks the fiscal period before recording and succeeds, but the GL helper schedules posting after commit. Between commit and `afterCommit`, the period may be closed, or the guard may be newly wired into `postEntry()`. The GL post rejects after the movement is already durable.

Evidence:
- `postEntryWithOptionalActor()` is the eventual place where the guard would be wired; it posts and hashes at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1957-2014`.
- The after-commit wrapper schedules that method outside the caller transaction at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:83-94`.
- `FiscalPeriodResolverService::isDateInOpenPeriod()` returns `false` when no matching open period exists at `apps/api/app/Modules/Accounting/Application/Services/FiscalPeriodResolverService.php:246-255`.

Fix recommendation: period validation must happen once inside the same transaction that writes the movement and posts the journal. Do not let movement `record()` and GL `postEntry()` each independently validate at different times. Also add a regression test where a period is closed between draft JE creation and `afterCommit` posting; this must be impossible after the design change.

### 3. HIGH: POS split-tender idempotency keys are not stable enough; duplicate method legs can collide

Failure scenario: a sale has two CASH lines, or two CARD lines from the same method with different instruments. The spec example key `fiscal_event:{uuid}:tender:cash` collapses both lines into one movement. If the retry path treats an existing idempotency key as success without comparing amount/method/instrument, one tender line silently disappears from treasury balance.

Evidence:
- Canonical POS payment rows contain `amount`, optional instrument fields, and `methodCode`, but no stable line id or ordinal: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/PaymentDTO.php:27-34`.
- `TreasuryReceiptBridge` iterates each canonical payment with `$index` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:280-284`, but the current idempotency probe only checks whether any POS payment already exists for the event at `:172-173` and `:463-469`.
- `PosCoreReceiptProjection` likewise writes one `pos_receipt_payments` row per canonical payment without a persisted canonical payment ordinal at `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:733-801`.

Fix recommendation: make the movement leg key include a deterministic canonical payment ordinal, e.g. `fiscal_event:{event}:payment:{index}`. Persist that ordinal on `pos_receipt_payments` or derive it from immutable canonical bytes. On idempotency hit, compare repository, direction, amount, currency, source, and journal_entry_id; mismatch must fail loudly, not return success.

### 4. HIGH: Partial projection success can be marked as success by current POS idempotency probes

Failure scenario: a POS bridge writes the first payment/movement leg and then crashes before the second. On retry, `paymentsForEventExist()` returns true and exits, so the missing leg is never created.

Evidence:
- `TreasuryReceiptBridge::apply()` returns immediately if any POS-origin payment exists for the event at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:172-173`.
- The predicate is only `(fiscal_event_id, origin=pos)` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:463-469`.
- The loop can write multiple lines inside one transaction at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:243-286`, but the future movement port's savepoint recovery could make per-leg failures more likely if not wrapped by an outer all-or-nothing transaction.

Fix recommendation: idempotency must be complete-set based. For a replay, count and validate every expected leg from canonical payload against existing movements/payments. A partial existing set must be repaired in the same transaction or treated as a hard corruption requiring operator resolution.

### 5. HIGH: Freeze-on-drift will poison fiscal projection queues unless the spec defines a projection-safe policy

Failure scenario: reconciliation freezes a repository. Offline POS events already ingested then reach `TreasuryReceiptBridge` or account/deposit bridges. The new port rejects writes, the projector throws, Horizon retries, then the projection row dead-letters. Sales remain fiscally sealed but treasury projections fail indefinitely until manual intervention.

Evidence:
- Projection jobs call the projector outside the row-lock transaction and rethrow on failure at `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:390-401`.
- Failure accounting resets the row to `pending` for retry at `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:524-530`.
- After retry exhaustion, `failed()` flips the row to `dead_lettered` at `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:428-455`.
- POS treasury bridges throw projection exceptions or runtime exceptions on missing/invariant failures, for example `TreasuryReceiptBridge` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:228-233` and `:369-386`.

Fix recommendation: freezing must distinguish interactive writes from fiscal replay. Options: allow projection writes into frozen repositories but flag them as `recorded_while_frozen`, route them to a suspense repository, or pause projector dispatch with an operator-visible blocked state that does not burn retries. Do not implement freeze as a generic exception thrown from the port for queued fiscal projections.

### 6. HIGH: Unpaid expense AP fix creates a liability with no Phase-1 settlement path

Failure scenario: an unpaid expense posts `Dr Expense / Cr AP`, with no movement. There is no route or service method to later pay that expense. The user can create a payable but cannot settle it through the treasury spine, leaving AP and cash stuck.

Evidence:
- Expense creation records `is_paid` and `payment_repository_id` at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:81-92`.
- Posting only calls repository outflow when `is_paid === true` at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:186-197`.
- Current GL incorrectly credits cash/bank unconditionally at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2284-2327`.
- Expense routes expose create/show/update/delete/post/reverse only; there is no pay/settle endpoint at `apps/api/app/Modules/Expense/routes.php:23-31`.
- `ExpenseController` has only `store`, `show`, `update`, `destroy`, `post`, and `reverse` surfaces at `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:95-247`.

Fix recommendation: include an expense settlement command in Phase 1: `POST /expenses/{id}/pay`, requiring posted unpaid expense, repository, method, date, and actor. It must write `movement(out)` and `Dr AP / Cr Cash` atomically, then mark metadata paid. If settlement is explicitly out of scope, block posting unpaid expenses in Phase 1 rather than creating dead-end liabilities.

### 7. HIGH: Transfer atomicity is underspecified around GL and two repository locks

Failure scenario: a transfer locks repository A, creates the out movement, then fails to lock repository B or fails GL. The spec says `transfer()` is one transaction, but it also says validation and GL work happen before repository locks. For same-GL-account transfers `journal_entry_id` may be nullable, making it easier for one leg to be created without a GL anchor if the implementation decomposes `transfer()` into two `record()` calls.

Evidence:
- Existing repository writers all lock one `payment_repositories` row with `lockForUpdate()`: old ports at `apps/api/app/Modules/Treasury/Application/Services/RepositoryInflowService.php:28-40` and `RepositoryOutflowService.php:28-40`, admin payments at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:586-605`, vendor refunds at `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:96-152`.
- Existing flows have different lock order. `PaymentController::store()` locks documents first at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:505-511`, then repository at `:586-592`. `VendorRefundService` locks the PO first at `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:49-58`, then repository at `:96-101`.

Fix recommendation: `transfer()` must be a single dedicated database transaction that locks both repositories sorted by id before any movement insert, writes both legs, updates both balances, and posts one JE if needed. Do not implement transfer as two calls to `record()`. Add a test with two opposing concurrent transfers and a forced failure after leg one.

### 8. HIGH: Migration sequencing allows coexistence of old direct balance writes and new port writes

Failure scenario: one task migrates expense/income adapters to the new port while `PaymentController` and `VendorRefundService` still directly mutate `payment_repositories.balance`. Reconciliation sees movement sums that exclude old-path balance changes. Worse, if an adapter delegates to the new port and a caller also adds an explicit port call during a staged migration, balances double-count.

Evidence:
- `PaymentRepository` still mass-assigns `balance` at `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:62-80`.
- Direct balance writes exist in `PaymentController::store()` at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:602-605`, `storeMultiple()` at `:948-961`, `VendorRefundService` at `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:149-152`, and old ports at `apps/api/app/Modules/Treasury/Application/Services/RepositoryInflowService.php:37-40` / `RepositoryOutflowService.php:37-40`.
- The old ports are still bound in `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` via `RepositoryInflowInterface` and `RepositoryOutflowInterface` bindings found by grep.

Fix recommendation: add the movement table and port behind a feature flag, then switch all writers in one deploy boundary or add a database trigger that rejects direct `balance` updates except from a controlled session variable set by the port. Remove `balance` from `$fillable` in the same migration task. Add an architecture test plus a DB-level guard; PHPStan alone will not catch runtime `update()` or raw query paths.

### 9. HIGH: Account/deposit POS bridges currently create payments and GL allocations without repository balance movement

Failure scenario: Phase 1 adds port calls to only `TreasuryReceiptBridge`, but account-payment and deposit bridges keep creating `Payment` rows and GL allocation entries without movements. Cash position remains wrong for these fiscal event types.

Evidence:
- `TreasuryAccountPaymentBridge` creates a `Payment` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:99-116` and calls allocation at `:118-126`; no repository balance or old port call appears in that transaction.
- `TreasuryDepositBridge` creates a `Payment` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:114-131` and calls allocation at `:133-141`; no repository balance or old port call appears.
- `PaymentAllocationService` creates GL entries at `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:251-347` but does not update repository balance.

Fix recommendation: include these bridges in the first movement-port convergence task, not as a later cleanup. Their movement idempotency must be `fiscal_event:{id}:payment:0` because these event types are modeled as one payment row; if that ever changes, the canonical payload must grow a stable payment ordinal.

### 10. HIGH: Refund and reversal flows need locking and GL/movement semantics before being put behind idempotent movements

Failure scenario: two concurrent partial refunds both pass validation against the same original payment amount and each create a negative payment row. The future movement port records both outflows because the proposed idempotency key is per refund row, not per original payment/refund request, and the original payment is never locked.

Evidence:
- `PaymentRefundService::partialRefund()` validates outside the transaction at `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:168-182`, then creates the refund inside a transaction at `:184-232`.
- It does not `lockForUpdate()` the original payment; full refund only does `$payment->refresh()` at `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:82-87`.
- Full and partial refunds create negative `Payment` rows at `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:102-119` and `:194-211`, but no GL or repository balance movement.
- Prorated POS refunds have a DB idempotency index for `(company_id, original_payment_id, refund_request_id)` documented and used at `apps/api/database/migrations/tenant/2026_05_03_000004_add_refund_audit_columns_and_unique_index_to_payments.php:27-40` and `PaymentRefundService.php:437-517`; full/partial admin refunds do not.

Fix recommendation: lock the original payment row for all refund/reversal paths, compute already-refunded totals under that lock, require an idempotency key for partial refunds, and write GL reversal + movement in the same transaction. Add a unique partial index for non-prorated refunds or require all refunds to flow through the existing `refund_request_id` model.

### 11. MED: Savepoint/idempotency recovery is underspecified for ordinal and balance consistency

Failure scenario: implementation increments `payment_repositories.next_movement_ordinal`, computes `balance_after`, attempts insert, gets a unique violation, rolls back only the insert savepoint, and returns the existing movement. The ordinal increment remains, creating gaps. Or implementation updates cached `balance` inside the savepoint, rolls back it on duplicate, and then separately updates balance again.

Evidence:
- Current old ports update only the cached balance inside a transaction at `apps/api/app/Modules/Treasury/Application/Services/RepositoryInflowService.php:28-40` and `RepositoryOutflowService.php:28-40`; there is no existing ordinal/cached-balance pattern to copy.
- The spec says the insert runs under a savepoint and the row counter is "incremented in-transaction", but it does not state whether the counter update and cached balance update are inside or outside that savepoint.

Fix recommendation: specify exact ordering in the spec: lock repo; create savepoint; increment ordinal; compute new balance; insert movement; update cached balance; release savepoint. On unique violation, roll back to savepoint, select existing movement by key, validate all semantic fields, and return without changing balance or ordinal. Add a test that forces a duplicate-key exception after the ordinal update and asserts no ordinal gap.

### 12. MED: Repository currency guard cannot be implemented cleanly without adding repository currency or company snapshot fields

Failure scenario: a tenant later changes company currency or imports historical rows. The port checks movement currency against current company currency, but repositories have no currency field. Reconciliation cannot prove that a repository's historical balance has one currency, and `payment_repositories.balance` can already hold mixed-currency writes.

Evidence:
- Initial `payment_repositories` migration has `balance` but no currency column at `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:28-31`.
- `PaymentRepository` properties and fillable/casts include `balance`, but no currency at `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:20-47` and `:62-93`.
- `payments` do carry `currency` at `apps/api/app/Modules/Treasury/Domain/Payment.php:36-38` and `:81-83`, so payment and repository currency can diverge silently today.

Fix recommendation: add `payment_repositories.currency char(3)` with a backfill from company currency and a check/foreign validation against company currency while multi-currency is out of scope. The movement port should compare intent currency to repository currency and company currency. Do not rely only on current company currency lookup.

### 13. MED: `RepositoryBalanceChanged` is not currently subscribed to audit events

Failure scenario: implementation keeps using `RepositoryBalanceChanged` as the audit notification seam, but no audit row is created. The spec says subscribe it to `audit_events`, but this is not currently true and must be an explicit implementation task.

Evidence:
- `RepositoryBalanceChanged` exists and provides an audit payload at `apps/api/app/Modules/Treasury/Domain/Events/RepositoryBalanceChanged.php:15-48`.
- Existing writers dispatch it after commit from old ports at `apps/api/app/Modules/Treasury/Application/Services/RepositoryInflowService.php:44-55` / `RepositoryOutflowService.php:44-55` and admin payments at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:608-619`.
- `DomainEventSubscriber::subscribe()` includes many treasury/payment events but not `RepositoryBalanceChanged` at `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1011-1046`.

Fix recommendation: add `RepositoryBalanceChanged` to the subscriber and test that a movement commit creates one `audit_events` row. Prefer dispatching a richer `RepositoryMovementRecorded` event from the new movement table; balance-changed events alone are too weak for audit once movement rows exist.

### 14. MED: GL account field usage is inconsistent in projection bridges

Failure scenario: the deposit bridge rejects a repository that is valid for GL posting because it checks `account_id` only. The account-payment bridge accepts `account_id ?? gl_account_id`. The spine spec relies on `gl_account_id` as the cash account. This inconsistency will create projection failures that look like movement-port failures.

Evidence:
- `TreasuryDepositBridge::resolveRepository()` requires `account_id` and checks that account at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:184-215`.
- `TreasuryAccountPaymentBridge::resolveRepository()` uses `$repository->account_id ?? $repository->gl_account_id` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:216-226`.
- `GeneralLedgerService::createPOSPaymentEntry()` uses `gl_account_id` and rejects when it is null at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2092-2133`.
- A migration backfills `account_id = gl_account_id` at `apps/api/database/migrations/tenant/2026_07_07_120000_backfill_payment_repository_account_ids.php:12-15`, but new/edited rows can still diverge.

Fix recommendation: canonicalize on `gl_account_id` for treasury cash GL. If `account_id` remains for legacy UI compatibility, make it a generated/backfilled alias or remove it from projection bridge validation. The movement port should require a single resolved cash GL account id in its intent or repository snapshot.
