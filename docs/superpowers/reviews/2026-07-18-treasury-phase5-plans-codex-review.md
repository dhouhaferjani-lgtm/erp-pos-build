# Adversarial review

## Verdict

- Phase ⑤a outbound instruments: **REJECT**
- Phase ⑤b bank reconciliation: **REJECT**

Both plans cover most Rev 2 requirements nominally, but neither is implementation-safe yet. The largest defects are lifecycle idempotency, the missing expense↔instrument link, mathematically undefined signed netting, ineffective allocation locking, and incomplete checkpoint enforcement.

## BLOCKER

1. **⑤a has no durable lifecycle-action idempotency mechanism.**

   The plan requires keys such as `instrument:{id}:clear:{cycle}` and says to check them before GL posting, but it adds nowhere to persist them. `journal_entries` has only `source_type/source_id`, not an action key, and `postEntryNow()` only posts an already-created entry and returns `void`. [Plan ⑤a](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md:139), [JournalEntry](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Accounting/Domain/JournalEntry.php:51), [postEntryNow](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2889)

   Worse, the prescribed order validates the transition before checking replay. After a successful clear the instrument is already `Cleared`, so an exact retry fails the `Received → Cleared` transition instead of returning the original result.

   **Resolution:** add an `instrument_transition_executions` table, or add immutable `action_key`, semantic digest, JE ID and movement ID columns to `instrument_events`, with a unique action key. Lock the instrument, check execution/replay before transition validation, compare semantic inputs, then create/post the JE. Add dedicated outbound GL entry builders and DB uniqueness.

2. **⑤a expense pay-by-instrument cannot be implemented from the described schema.**

   Task 9 says to store an expense-metadata link and later discover it during clear/cancel, but `expense_metadata` has no `instrument_id`, and the task lists no migration or model change. [Plan ⑤a Task 9](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md:261), [current ExpenseMetadata](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Expense/Domain/ExpenseMetadata.php:51)

   It also leaves `is_paid=false` until clearing. Existing settlement therefore permits a second payment while the first instrument is outstanding because its guard is `is_paid`. [ExpenseService](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:499)

   **Resolution:** add a unique nullable `expense_metadata.payment_instrument_id` FK or a typed settlement-link aggregate, plus an explicit `PendingInstrument/Cleared/Cancelled` payment state. Reject a second settlement while pending. Move the corresponding `OutboundInstrumentService` changes and tests into Task 9’s file list.

3. **⑤b’s allocation arithmetic cannot represent card netting.**

   Allocations are positive, but a net card deposit needs:

   `+gross card inflows − fee outflow = net statement inflow`

   Task 5 merely says “Σ allocations ≤ signed line amount,” without defining how movement direction signs `matched_amount`, while Task 7 expects the outgoing fee movement to join the allocation set. Raw positive sums produce `gross + fee`, not `net`. There is also no ordinary cross-direction rejection. [Plan ⑤b Task 5](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:140), [Task 7](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:161)

   **Resolution:** specify one exact signed equation, including the sign contributed by each movement direction and when opposite-direction movements are legal. Persist a netting-group/action relationship if needed. Test ordinary direction mismatch, gross-plus-fee netting, refunds and partial allocations.

4. **The per-movement concurrency guard is ineffective as written.**

   Locking “the movement’s allocation rows” does not serialize two first allocations: when no allocations exist, both transactions lock an empty result and can both over-allocate. Completion also locks no movement rows while revalidating per-movement totals. [Matching lock prescription](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:140), [completion lock order](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:178)

   **Resolution:** lock all affected `repository_movements` rows in stable ID order before reading allocation sums. Completion must lock every referenced movement row too. Run the race test against PostgreSQL with two real connections.

5. **The five-table migration order contains a forward-FK failure.**

   The plan orders `bank_statements` before `statement_import_profiles`, but `bank_statements.parser_profile_id` references the profile table. A faithful implementation fails when the first migration runs. [Migration ordering](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:73)

   The proposed line→statement composite FK itself is valid PostgreSQL: create a unique constraint on `bank_statements(id, payment_repository_id)`, then reference that exact ordered pair. The problem is overall creation order.

   **Resolution:** create profiles first, then statements and lines; or add `parser_profile_id` in a sixth migration after both tables exist.

## HIGH

6. **Task 5 depends on Task 7, so Wave 3 cannot compile in order.**

   Task 5 dispatches to `AcquirerFeeService`, CreateExpense and CreateIncome, all introduced in Task 7. It also dispatches every `OutboundClear` to `clear()`, while ⑤a defines `clear()` only for `Received` and `represent()` for `Bounced`. [⑤b Task 5](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:140), [⑤a service contract](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md:127)

   **Resolution:** introduce typed action handlers before the dispatcher, or initially implement only available actions and extend after Task 7. Dispatch Bounced instruments to `represent()`.

7. **Checkpoint enforcement is incomplete and has an unsafe boundary.**

   Task 8 changes only `record()`. `transfer()` is another movement-port write and remains able to backdate both legs. The promised projection “flag + alert” has no column/event field; the only existing flag is `recorded_while_frozen`, which must not be repurposed. [Plan Task 8](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:170), [current port](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:46), [TransferIntent](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php:26)

   Stamping a date into a timestamp at midnight and rejecting only `< checkpoint` lets writes on the reconciled period-end date through.

   **Resolution:** enforce the bound in both `record()` and `transfer()`, after repository lock and after resolving the effective occurrence time but before opening the port GUC/savepoint. Compare bank dates inclusively or persist an explicit end-of-day boundary. Add a separate behind-checkpoint audit event/field.

8. **Payment-method routing is incomplete and replay-unstable.**

   The current resolver accepts only `FiscalEvent`; it must receive the already-resolved `PaymentMethod`. Existing payment replays must retain their stored repository even if the method mapping later changes. [Current call](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:468), [current resolver](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:733)

   Task 1 also omits `PaymentMethod` fillable/property/relation changes and omits the spec’s guarded mapping backfill. Existing methods therefore remain null and keep routing cards to the fallback repository.

   **Resolution:** update the model and controller with company-scoped validation; pass `PaymentMethod` to resolution; on replay use `existing Payment.repository_id`; add a guarded configuration/backfill command and a replay-after-mapping-change test.

9. **Several interfaces and file anchors are not real.**

   - `InstrumentLifecycleController.php` does not exist; lifecycle endpoints are in `PaymentInstrumentController.php`. [Bad anchor](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md:233)
   - There is no “income-creation DTO”; `IncomeService::create()` accepts an array. [Bad interface](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:157), [actual IncomeService](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/apps/api/app/Modules/Income/Application/Services/IncomeService.php:43)
   - `postEntryNow()` does not create or return a JE. ⑤a needs explicit outbound GL builder methods.
   - `CsvStatementParser` is expected to parse XLSX, but no parser registry, XLSX implementation, or service binding is defined.

10. **The repository contract has no concrete shared interface.**

    Task 3 promises a helper reused by `PaymentController`, but creates no helper and merely says an implementer “may extract” one. Tests cover only cash-register rejection, omitting inactive repository, currency mismatch, missing GL, bank mismatch and cross-company cases. [Task 3](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md:139), [Task 6](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md:217)

    **Resolution:** create `OutboundRepositoryValidator` with an exact signature and use it from issue and every lifecycle transition.

11. **Execution replay does not reject semantic mismatch.**

    `executeAndAllocate()` reuses any existing `action_key`, but does not compare action type, target, amount, repository, value date, method or fee parameters. A later confirmation with changed inputs can silently reuse the wrong financial action.

    **Resolution:** persist a canonical semantic digest and typed action payload; on replay compare it before reusing produced movements.

12. **Finding 15, the multi-location prerequisite, has no implementing or enforceable dependency task.**

    The plans defer the package externally and do not add instrument/payment location inheritance. That means outbound instruments still cannot freeze origin location. This is the one original finding with no concrete task that closes the accepted resolution. [Rev 2 dependency text](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md:180), [⑤b self-review deferral](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:223)

    **Resolution:** add a prerequisite task/merge gate for the multi-location §3 package, or remove every acceptance criterion dependent on instrument/payment location until it lands.

## MED / LOW

- The plan never explicitly performs `Imported → Reconciling` on the first allocate/ignore/action, nor defines clearing ignore state or unignore. Thus finding 19 is only nominally mapped.
- Statement/profile ownership FKs and delete behavior are not enumerated. “Exactly spec §5.1” is insufficient for an implementation plan.
- The card tier does not define grouping by payment method/acquirer. Multiple card methods on one repository/day can be incorrectly netted together.
- Tier 1–2 exclude every movement already present in allocations, even partially allocated movements with remaining capacity.
- The accounting gate is contradictory: the spec says confirm codes before build, while ⑤a permits Waves 1–4 to build before confirmation. [Plan gate](/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5/docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md:22)
- Both documents use collapsed “red → implement → green” steps and placeholder test comments. They do not meet the repository’s executable TDD-plan standard.

## Twenty-finding coverage audit

| # | Planned task(s) | Result |
|---|---|---|
| 1 | ⑤a T3–T4 | Partial: replay order/storage broken; Bounced dispatch mismatch |
| 2 | ⑤a T5, T9 | Partial: expense link/state absent |
| 3 | ⑤b T1, T7 | Partial: mapping backfill and stable replay omitted |
| 4 | ⑤b T3–T4 | Covered |
| 5 | ⑤b T2, T5, T7 | Partial: signed netting and locking undefined |
| 6 | ⑤a T1, T3, T6 | Partial: no concrete idempotent GL builder |
| 7 | ⑤a T1 | Covered |
| 8 | ⑤a T2–T6 | Partial: no durable action-key store |
| 9 | ⑤a T3, T6 | Partial: validator/interface and failure tests missing |
| 10 | ⑤b T2, T4, T5 | Partial: semantic replay mismatch and ignore conflicts |
| 11 | ⑤b T2–T4 | Partial: occurrence-index and canonical-normalization tests missing |
| 12 | ⑤b T8 | Partial: transfer bypass, boundary and projection audit gap |
| 13 | ⑤b T7 | Covered structurally; VAT policy still unresolved |
| 14 | ⑤b T7 | Partial: incorrect DTO/file reality |
| 15 | External deferral only | **Slipped** |
| 16 | ⑤b T2 | Partial: FK ownership/delete/order not concrete |
| 17 | ⑤b T3 | Partial: no XLSX parser dispatch/binding |
| 18 | ⑤b T9 | Covered |
| 19 | ⑤b T2, T5, T8 | Partial: transitions not actually prescribed |
| 20 | Spec anchors refreshed | Covered, but ⑤a introduces a new nonexistent-controller anchor |

## Missing failure-mode tests

For ⑤a, add tests for:

- Every unlisted transition for every action and status.
- Concurrent identical action requests and semantic-key mismatch.
- Retry after state already changed.
- GL failure and movement failure for clear, bounce, represent and cancel.
- Cycle rollback when representation fails.
- Inactive, missing-GL, wrong-currency, wrong-bank and cross-company repositories at issue and clear.
- Expense second-payment attempt while an instrument is pending.
- Expense link lookup, clear/cancel rollback and retry.
- Multi-document and partial-allocation cancellation.
- Backfill dry-run, FR/generic charts, existing wrong-type `403/4035`.

For ⑤b, add tests for:

- First-allocation race when no allocation rows yet exist.
- Direction mismatch and explicit signed card-netting equation.
- Completion racing an allocation on another statement against the same movement.
- Action replay with changed target/amount/date/method.
- Ignore after allocation/execution; unignore and status recomputation.
- Two identical legitimate fees in one file; whitespace/case normalization; duplicate bank transaction IDs.
- Parser-key dispatch for both CSV and XLSX.
- Partially allocated movements remaining suggestible.
- Multiple card methods/acquirers on the same business day.
- Period-end equality and transfer backdating.
- Cross-company profile/repository ownership.
- Migration up/down ordering and all composite-FK violations.
- Legacy `summary` and every mutation route after cutover.

## Parallelism verdict

The functional claim that ⑤b Waves 0–2 do not require outbound clearing is broadly correct. The execution claim is not safe as written:

- ⑤a Wave 3 and ⑤b Wave 2 both modify Treasury routes and permission seeding.
- ⑤a Wave 4 and ⑤b Wave 3 later both modify `ExpenseService`.
- ⑤b Wave 3 also has its own T5→T7 dependency inversion.

Run only ⑤b Waves 0–1 in parallel on a separate branch/worktree. Serialize route/permission integration, then require ⑤a T3–T5 plus corrected outbound endpoints before ⑤b matching actions.

The review was read-only; the worktree remains unchanged.

Codex session ID: 019f7492-c31d-7f32-817e-872d59aa2d75
Resume in Codex: codex resume 019f7492-c31d-7f32-817e-872d59aa2d75
