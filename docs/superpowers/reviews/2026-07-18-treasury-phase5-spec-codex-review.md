# Treasury Phase 5 Design Spec — Adversarial Review

## Scope & Method

This review uses the completed investigation of the Phase 5 spec, prior Treasury/multi-location specs, handoff, migrations, models, controllers, lifecycle services, GL helpers, movement port, POS projections, expense/income services, and legacy reconciliation code. Code paths are relative to `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`. Findings marked **CONFIRMED** are supported by inspected code or an internal contradiction in the spec; **PLAUSIBLE** denotes a risk requiring an owner decision or implementation evidence. The nominal 5a journal directions are accounting-correct: issue-time Dr AP / Cr payable-instrument with no cash movement, followed by clearing Dr payable-instrument / Cr bank plus an outbound movement; the blockers are lifecycle, subledger, routing, and idempotency contracts around those entries.

## Findings

### BLOCKER — The outbound bounce/re-presentation state machine cannot reuse the existing machinery

- **Status:** CONFIRMED
- **Spec location:** §4.3, “reuse the existing `bounce()` reversal machinery with direction-aware accounts.”
- **Evidence:** Existing `clear()` and `bounce()` explicitly reject outbound instruments (`apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:196-204`, `:323-331`). Bounce requires an inbound remittance (`:337-353`), selects receivables/doubtful-receivables accounts (`:398-445`), records an **out** bank movement (`:483-504`), reverses customer allocations (`:507-543`), and marks the customer payment dishonored (`:545-573`). Existing re-presentation is an inbound remittance transition from `Bounced → Deposited` (`apps/api/app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php:162-193`).
- **Why it matters:** An outgoing instrument dishonored after clearing needs the opposite cash effect: normally an `in` movement with Dr bank / Cr payable-instrument liability. The existing path’s remittance, AR, customer-allocation, and movement side effects are fundamentally wrong for outbound instruments.
- **Concrete resolution:** Define and implement dedicated outbound actions with an explicit transition table:
  - `Received → Cleared`
  - `Received → Cancelled`
  - `Cleared → Bounced` with compensating `in` movement
  - `Bounced → Cleared` on re-presentation
  - `Bounced → Cancelled` with AP reopening
  - exact replay and invalid-transition behavior.
  
  Specify GL lines, movement direction, timestamps, fees, locks, events, and idempotency for each transition.

### BLOCKER — Cancellation does not atomically reopen the supplier or expense subledger

- **Status:** CONFIRMED
- **Spec location:** §4.3, cancellation “reopens the AP balance”; §4.5 expense pay-by-instrument.
- **Evidence:** Deferred supplier issue creates a completed `Payment`, links the instrument, creates positive allocations, reduces `balance_due`, and can mark the supplier document paid (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:790-813`, `:905-920`). Existing cancellation refuses most linked payments unless separately reversed/dishonored and only updates payment status directly for its POS-specific shape (`apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:596-639`). Expense settlement also mutates `is_paid`, `paid_at`, repository, method, and date (`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:567-573`).
- **Why it matters:** Reversing only the issue JE leaves documents marked paid, allocations in place, or expense metadata marked paid while GL AP has reopened. Performing payment reversal separately creates a partial-failure window.
- **Concrete resolution:** One orchestration transaction must lock instrument, payment/expense metadata, allocations, documents, GL, and repository in the established order; post the issue reversal; append negative allocations; recompute document balance/status; reset expense/payment state; and record the cancellation event idempotently.

### BLOCKER — Card-batch matching depends on payment-method repository routing that has not shipped

- **Status:** CONFIRMED
- **Spec location:** §6.2 tier 4, “card-tender movements grouped by business day” and “no POS projection changes.”
- **Evidence:** `TreasuryReceiptBridge` routes every tender to the first GL-linked company repository, independent of method (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:460-468`). It stamps the selected repository on the payment (`:599-623`) and movement (`:664-709`). Its own comments acknowledge that multi-repository routing is semantically incorrect until payment-method→repository mapping exists (`:712-741`). The legacy POS writer creates Payment/GL records but no repository movement in the inspected flow (`apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:276-316`).
- **Why it matters:** Card movements may reside in a cash/default repository rather than the imported bank repository, so §6.1’s same-repository guard excludes them. Legacy payments may have no candidate movement at all.
- **Concrete resolution:** Make payment-method/acquirer→repository routing and historical backfill a Phase 5 prerequisite. Update both POS paths. Otherwise remove tier 4 from launch scope.

### BLOCKER — The schema prohibits the only ignored-line shape allowed at completion

- **Status:** CONFIRMED
- **Spec location:** §5.1 `CHECK (amount > 0)`; §6.3 zero-amount ignored lines; §6.4 exclusion of zero-amount ignored lines.
- **Evidence:** These clauses directly contradict one another. No Phase 5 bank-statement tables or implementation currently exist.
- **Why it matters:** Every persisted ignored line must be non-zero, while every non-zero ignored line blocks completion. `Ignored` therefore cannot contribute to a completable statement.
- **Concrete resolution:** Either allow `amount = 0` with defined zero-line direction semantics, or exclude informational rows during parsing and remove zero-amount ignored lines from the persisted state model.

### BLOCKER — The matching schema cannot enforce the advertised sum rule in both directions

- **Status:** CONFIRMED
- **Spec location:** §5.1 unique `repository_movement_id`; §6.1 “one-line↔many-movements”; §6.2 “remittance total or per-line amounts.”
- **Evidence:** The proposed match table has no `matched_amount`. Unique `repository_movement_id` supports one line→many movements but forbids many lines→one movement and partial allocations. Existing movements are indivisible positive-amount facts with sign stored separately (`apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:14-40`). Current inbound clearing happens per instrument/remittance line (`apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:212-285`), but the system does not establish that all other sources have bank-line-compatible granularity.
- **Why it matters:** Aggregated debits, split bank postings, gross settlement plus separate fee lines, and partial settlement cannot all be reconciled. The proposed per-line sum check is insufficient to prevent over-allocation of a movement.
- **Concrete resolution:** Add a true allocation model with unique `(line_id,movement_id)`, `matched_amount`, signed allocation rules, and `Partial` state. Validate both:
  - allocations per line equal the signed statement amount;
  - allocations per movement never exceed the signed movement amount.

### HIGH — The nominal 5a Dr/Cr entries are correct, but their GL account contract is incomplete

- **Status:** CONFIRMED
- **Spec location:** §4.2 Dr AP / Cr ChecksToPay or EffetsPayable; §4.3 Dr payable-purpose / Cr bank.
- **Evidence:** Current supplier-payment logic already models Dr SupplierPayable / Cr a supplied payment account (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:707-774`). Phase 4’s unpaid-expense rule credits AP and settlement debits it (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3335-3385`; `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:523-546`). Thus the spec’s issue and clear entries are balanced and do not double-count cash when issue creates no movement.
- **Why it matters:** The entries are correct only if the payable-purpose accounts are liabilities, clearing uses the bound repository’s exact GL account, and no existing supplier-payment movement/Cr-bank path also executes.
- **Concrete resolution:** Make those three conditions normative. Explicitly suppress the current deferred-supplier movement at issue—the current guard suppresses deferred customer movements only (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:1082-1127`)—and require clearing to credit `instrument.repository.gl_account_id`.

### HIGH — The account-purpose proposal conflicts with the shipped Phase 2 purpose model

- **Status:** CONFIRMED
- **Spec location:** §4.1, new `SystemAccountPurpose` cases “following the Phase ② collection-purpose pattern.”
- **Evidence:** `SystemAccountPurpose` has no instrument purposes and contains exhaustive label/type matches (`apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php:14-76`, `:81-122`, `:150-174`). Phase 2 instead uses `InstrumentAccountPurpose` (`apps/api/app/Modules/Treasury/Domain/Enums/InstrumentAccountPurpose.php:7-16`) and a country-code resolver (`apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:39-51`). France seeds account 403, while TN and generic charts do not contain the proposed payable-instrument accounts (`apps/api/database/seeders/FranceChartOfAccountsSeeder.php:163-169`, `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:167-216`, `apps/api/database/seeders/GenericChartOfAccountsSeeder.php:126-158`).
- **Why it matters:** Implementers can place the new purposes in the wrong enum/resolver or invent inconsistent chart codes.
- **Concrete resolution:** Decide explicitly which purpose system owns outbound instruments. Specify exact TN/FR/generic codes, expected liability type, exhaustive enum branches, brownfield backfill, and missing-account behavior.

### HIGH — Instrument-scoped idempotency is not enough to protect GL posting

- **Status:** CONFIRMED
- **Spec location:** §4.2 “instrument-scoped key on the JE”; §4.3 clearing idempotent on the instrument key.
- **Evidence:** The movement port does not compare `journal_entry_id` on replay and warns that a new JE posted before a movement replay becomes orphaned (`apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:35-48`). Instrument JEs deliberately lack a unique source index because one instrument creates multiple lifecycle entries (`apps/api/database/migrations/tenant/2026_07_12_100400_create_instrument_remittances.php:9-13`). The deferred-supplier `receive()` call does not pass its supported idempotency key (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:757-779`).
- **Why it matters:** Issue, clear, bounce, cancel, and repeated presentation cycles cannot safely share one key. A retry may post duplicate JEs before hitting an existing movement.
- **Concrete resolution:** Use action/cycle keys such as `instrument:{id}:issue`, `:clear:{cycle}`, `:bounce:{cycle}`, and `:cancel`. Enforce uniqueness before posting GL, persist the key with the event/JE, return the original result on exact replay, and reject semantic mismatch.

### HIGH — Outbound issue lacks a bank-repository type/ownership contract

- **Status:** CONFIRMED
- **Spec location:** §4.2, repository “of the account it will draw on.”
- **Evidence:** `receive()` accepts and stores a repository ID without enforcing an outbound bank-account lifecycle contract (`apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:67-105`). The supplier controller scopes the repository and requires a GL account but does not require `RepositoryType::BankAccount` (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:621-642`).
- **Why it matters:** Checks/effects can be issued against a cash register, safe, inactive repository, or inconsistent bank identity.
- **Concrete resolution:** Require an active tenant/company-scoped `BankAccount`, matching currency, non-null `gl_account_id`, and consistent `bank_id` at issue and clear time.

### HIGH — Unmatch and void semantics can replay or conceal financial actions

- **Status:** CONFIRMED design gap
- **Spec location:** §5.3 void when no line is matched; §6.1 unmatch deletes matches; tiers 3–4 execute financial actions.
- **Evidence:** No durable Phase 5 action-execution table is specified. Existing expense settlement prevents duplicate GL by checking a deterministic movement key before posting (`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:480-497`). The spine contract requires GL idempotency to precede movement replay (`apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:35-48`).
- **Why it matters:** A pending-action match can create GL/movement, then be unmatched. Reconfirm may create another fee expense/income or lifecycle action. The statement can subsequently satisfy “no line matched” and be voided despite having created financial records.
- **Concrete resolution:** Add immutable match-execution provenance containing deterministic action key, action type, target entity, and produced movement IDs. Unmatch may remove allocations but not execution provenance. Reconfirm reuses the original action. Forbid void after any action execution unless a compensating reversal has completed.

### HIGH — Repository-wide fingerprints produce both false positives and false negatives

- **Status:** CONFIRMED design flaw
- **Spec location:** §5.1 fingerprint over date, direction, amount, reference, and label.
- **Evidence:** No bank transaction ID, statement/file identity, sequence, or occurrence discriminator is included. No statement-level file hash/idempotency key is specified.
- **Why it matters:** Two legitimate identical same-day fees can collide and one will be silently skipped. Conversely, whitespace, reference, date, or label-format changes can make re-imported rows appear new. Re-importing an identical file can also create a duplicate statement containing zero accepted lines.
- **Concrete resolution:** Add unique `(repository_id,source_file_sha256)` or a normalized statement identity. Prefer bank transaction IDs when available. Define canonical normalization and an occurrence discriminator, add unique `(statement_id,line_number)`, and reject zero-line confirmed imports unless explicitly acknowledged.

### HIGH — Completion and reopen have no race-safe checkpoint contract

- **Status:** CONFIRMED
- **Spec location:** §6.1 reopening; §6.4 checkpoint gives the spine a backdating bound.
- **Evidence:** The movement port locks repositories but does not consult `last_reconciled_at` (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:46-95`). The legacy matcher mutates rows without transactions/locks (`apps/api/app/Modules/Treasury/Application/Services/BankReconciliationService.php:99-160`), and legacy completion stamps `now()` after fetching the reconciliation outside the transaction (`:166-199`).
- **Why it matters:** Complete can race with match/unmatch/ignore or a backdated movement. Completing statements out of order can regress the checkpoint; reopening can leave a false checkpoint.
- **Concrete resolution:** Define one lock order for statement, lines, allocations/actions, GL, and repository. Revalidate all sums under lock. Define checkpoint time as `period_end`, maximum value date, or another explicit bank boundary; enforce it in the movement port. Reopen must recompute the latest valid checkpoint or reject/cascade out-of-order reopening.

### HIGH — Card fee expenses cannot use `fee_account_id` through the normal expense path

- **Status:** CONFIRMED
- **Spec location:** §6.2 tier 4, fee expense “against `fee_account_id`”; §6.3 normal expense flow.
- **Evidence:** `fee_account_id` is a direct account reference (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:98-102`, `:118-139`). Normal expense posting selects the debit account through `ExpenseCategory.account_id` or `GeneralExpense` (`apps/api/app/Modules/Expense/Domain/ExpenseCategory.php:18-58`; `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3349-3365`). Paid expense/settlement posting selects generic Bank/Cash purposes instead of the repository’s exact GL account (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3367-3378`; `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:516-540`).
- **Why it matters:** The fee can debit the wrong expense account and credit a GL account different from the matched repository, violating movement/GL alignment.
- **Concrete resolution:** Add a dedicated acquirer-fee action or validated account override. Debit `fee_account_id`, apply Phase 4 VAT rules, credit the statement repository’s exact `gl_account_id`, and key the expense/movement deterministically to the match group.

### HIGH — Create-from-line does not preserve statement date, location, or income classification

- **Status:** CONFIRMED
- **Spec location:** §6.3, pre-filled date, repository, description, and `location_id`.
- **Evidence:** Expense creation does not populate `documents.location_id` (`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:106-136`). Settlement movements use `occurredAt: null`, therefore current time (`:535-565`). Income defaults to `ProductRevenue` unless an account is supplied (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3493-3505`).
- **Why it matters:** Fees may become unattributed, movements may fall outside the statement period, and bank interest may be classified as product sales.
- **Concrete resolution:** Extend the normal service DTOs with explicit `location_id`, movement occurrence date, and fee/interest account. Use the statement value date consistently for document, JE, and movement dates, subject to period/checkpoint guards.

### HIGH — Phase 5 assumes a multi-location prerequisite that has not landed

- **Status:** CONFIRMED
- **Spec location:** §5.1 location defaults; §8 strict adherence to the multi-location contract.
- **Evidence:** The multi-location design requires repository location exposure and indexed `location_id` columns on instruments/payments (`docs/superpowers/specs/2026-07-16-multi-location-management-design.md:101-126`). Current schema has repository `location_id` without an FK/index and lacks location on instruments/payments (`apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:13-47`, `:89-169`). Repository creation validates location only as a UUID, and its API representation omits it (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:71-109`, `:319-337`).
- **Why it matters:** Statement-line inheritance cannot be validated, and outbound instruments cannot freeze their origin location.
- **Concrete resolution:** Make the multi-location migration/API package an explicit Phase 5 dependency. Land scoped location FKs/indexes, instrument/payment columns, writer propagation, and repository response exposure first.

### MED — Import profiles and statement tables omit ownership and integrity constraints

- **Status:** CONFIRMED design gap
- **Spec location:** §5.1 table definitions.
- **Evidence:** `statement_import_profiles` is “per repository” but its listed fields omit `id`, tenant/company/repository ownership, timestamps, and version/active state. Statements do not persist currency. Lines denormalize repository ID without a constraint tying it to their parent statement. No Phase 5 migration currently exists.
- **Why it matters:** Profiles can be applied across companies, dedupe scope can disagree with the statement, and historical currency depends on mutable repository state.
- **Concrete resolution:** Specify profile ownership FKs, statement currency, period checks, unique `(statement_id,line_number)`, repository/status/period indexes, delete behavior, and either remove line repository duplication or enforce it with a composite FK/trigger.

### MED — `SpreadsheetParserService` does not satisfy the proposed profile contract

- **Status:** CONFIRMED
- **Spec location:** §5.2 parser reuse; §5.1 `header_rows`, date/decimal formats, and direction convention.
- **Evidence:** CSV parsing always treats the first physical row as headers (`apps/api/app/Modules/Import/Services/SpreadsheetParserService.php:44-66`). Excel does the same and reads raw cell values as strings (`:129-176`). There is no support for preamble rows, bank date conversion, locale decimals, or separate debit/credit columns.
- **Why it matters:** Common bank exports with preambles, Excel serial dates, formulas, and locale-formatted numbers will be misparsed.
- **Concrete resolution:** Introduce a profile-aware raw-row parser or extend the service contract. Define formula handling, Excel date conversion, encoding/delimiter behavior, preamble skipping, decimal normalization, and unparseable-row reporting.

### MED — “Legacy tables read-only” does not disable legacy mutations

- **Status:** CONFIRMED
- **Spec location:** §5.4 legacy scaffold.
- **Evidence:** Existing match, unmatch, complete, and cancel methods remain mutating (`apps/api/app/Modules/Treasury/Application/Services/BankReconciliationService.php:99-249`). Mutation routes remain exposed (`apps/api/app/Modules/Treasury/Presentation/routes.php:249-279`).
- **Why it matters:** Hiding navigation does not stop API clients from mutating the legacy checkpoint alongside the new workspace.
- **Concrete resolution:** Remove or explicitly disable legacy mutation routes at cutover, preserve only named read endpoints if required, and ensure only the new completion action can stamp repository checkpoints.

### MED — Statement and line state transitions are underspecified

- **Status:** CONFIRMED design gap
- **Spec location:** §5.1 `Draft → Imported → Reconciling → Reconciled`; §5.3 preview is not persisted; §6.1 unmatch/reopen.
- **Evidence:** The specified confirm flow creates a statement directly as `Imported`, leaving persisted `Draft` unreachable. `match_status` duplicates facts held in match/action rows, but no synchronization invariant is provided.
- **Why it matters:** Implementers may permit edits in terminal states or leave line status inconsistent after deletion, retry, or rollback.
- **Concrete resolution:** Publish a statement/line transition matrix including permissions and error rollback. Remove `Draft` if preview stays ephemeral. Derive line status, or update it only inside a locked aggregate action.

### LOW — Several existing-code anchors in the spec are stale

- **Status:** CONFIRMED
- **Spec location:** §3 current anchors; §4.4 maturity work; §11 remittance-grain question.
- **Evidence:** `MaturingInstrumentsController` already handles both directions (`apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php:27-46`, `:82-108`). Current remittance confirmation clears instruments individually (`apps/api/app/Modules/Treasury/Presentation/Controllers/InstrumentRemittanceController.php:148-172`). Gross POS movements live in `TreasuryReceiptBridge` (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:664-709`), not the inspected legacy `ReceiptPaymentService` flow (`apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:276-316`).
- **Why it matters:** The implementation plan may duplicate completed work or modify the wrong POS path.
- **Concrete resolution:** Refresh the current-state section, enumerate both POS writers, and distinguish current per-line remittance clearing from the independent bank-statement aggregation decision.

## Open Questions for the Spec Author

- What exact TN, FR, and generic account codes back `ChecksToPay` and `EffetsPayable`?
- Does outbound dishonor itself reopen AP, or does AP remain closed until explicit cancellation?
- Is true many-to-many partial allocation required at launch?
- What defines a card batch: timezone, sale/settlement date, acquirer, refunds, chargebacks, fixed fees, and multi-day settlements?
- Which date owns the reconciliation checkpoint, and may statements complete/reopen out of order?
- Which operational roles receive confirm, unmatch, ignore, complete, and reopen permissions?
- Must match/unmatch history be immutable rather than physically deleted?

## Verification Log

- Read the target Phase 5 spec, Treasury spine, Phase 4 expense-depth spec, multi-location spec, and outbound handoff.
- Traced instrument issue/clear/bounce/cancel/re-presentation, supplier allocations, expense settlement, GL helpers, movement idempotency, POS repository routing, import parser behavior, legacy reconciliation mutations, schemas, and account seeders.
- Confirmed no Phase 5 bank-statement models, match classes, parser contract, or migrations currently exist.
- The investigation remained read-only; the prior final worktree check reported no diff, cached diff, tracked changes, or untracked files.

## Verdict

**REJECT**

The core issue/clear Dr/Cr design is sound, but the spec is not implementation-safe. The outbound dishonor/cancellation state machine, reconciliation cardinality, action replay semantics, card repository routing, zero-line schema contradiction, checkpoint locking, fee-account posting, and multi-location dependency must be resolved before planning or implementation.

Codex session ID: 019f745d-8e23-71c1-80ec-618912578296
Resume in Codex: codex resume 019f745d-8e23-71c1-80ec-618912578296
