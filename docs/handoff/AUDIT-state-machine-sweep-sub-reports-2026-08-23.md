# State-Machine Sweep — Sub-Sweep Reports (2026-08-23)

This file preserves the six background-agent sub-sweep reports that fed the 2026-08-23
local state-machine sweep, recovered verbatim from their JSONL transcripts after the
live conversation lost them. The parent report is
`docs/handoff/AUDIT-state-machine-sweep-local-2026-08-23.md`, which was written by an
agent working independently and believing these sub-reports were lost. Where a parent
finding (#22–#30) overlaps a finding here, treat it as **corroboration**, not
duplication — both were reached independently by separate passes over the code.

Each section below is one agent's full, verbatim final report (file:line citations
intact) — six sweeps covering Treasury, Inventory, Fiscal + device/sync, POS retail
till, Import + opening balances, and Tenancy + document-adjacent lifecycles.

---

## Treasury sweep

*Source: background agent transcript `agent-a2cea53d063a01613.jsonl` — final assistant message, extracted verbatim.*

Sweep complete. Full report:

---

# TREASURY state-machine / lifecycle sweep — findings

## NEW FINDINGS

### **[MEDIUM] `InstrumentStatus` has no adjacency map — 9 scattered write sites across 4 files, two divergent per-direction machines, and a dead `isTerminal()`**

**Where:**
- Enum: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/Enums/InstrumentStatus.php:26-65`
- Write sites: `.../Application/Services/InstrumentLifecycleService.php:112` (create `received`), `:295` (`cleared`), `:558` (`bounced`), `:855` (`cancelled`); `.../Application/Services/InstrumentRemittanceService.php:170` (`deposited`); `.../Application/Services/OutboundInstrumentService.php:159` (`cleared`), `:317` (`bounced`), `:470` (`cleared`/re-present), `:691` (`forceFill(['status' => Cancelled])->save()`)

**Problem:** The enum exposes only four action predicates (`canDeposit`, `canClear`, `canBounce`, `canTransfer`) plus `isTerminal()`. There is no `isAllowed(from, to)` / `allowedTargetsOf()` adjacency map like `Workshop/WorkOrder/Domain/Services/StatusMachine.php:29-88`, and no single write path like `WorkOrderTransitionService`. Instead, two services implement **different edge sets for the same column**: inbound clear requires `Deposited|Clearing` (`InstrumentStatus.php:34-37` via `InstrumentLifecycleService.php:213`) while outbound clear requires `Received` (`OutboundInstrumentService.php:100`); inbound bounce accepts `Deposited|Clearing|Cleared` (`InstrumentStatus.php:42-45`) while outbound bounce requires `Cleared` (`OutboundInstrumentService.php:251`). `isTerminal()` at `InstrumentStatus.php:58` is **never called anywhere in `app/`** (verified: `grep -rn "isTerminal()" app/` returns only definitions plus the single caller `Document.php:691`) — the three states it declares terminal (`Expired`, `Cancelled`, `Collected`) are protected only incidentally, because the four action predicates happen to exclude them. `OutboundInstrumentService.php:691` uses `forceFill(...)->save()`, which bypasses `$fillable`.

**Why it matters:** The two machines are currently kept apart by direction guards (`InstrumentLifecycleService.php:145/188/210/335/612/739` reject Outbound; `OutboundInstrumentService.php:97/248/408/557` require Outbound) — so this is not exploitable today. But the safety property lives in nine duplicated `if` statements, not in one map. Any new action handler (e.g. a maturity/expiry sweep, a bulk import, an admin correction endpoint) that forgets one of those two guard families writes `payment_instruments.status` unchecked — and each of these transitions is paired with a GL journal entry and a `repository_movements` cash leg, so a bad edge means double-counted or orphaned bank cash. The three "Reserved-dormant" states (`InTransit`, `Expired`, `Collected`, documented as unreachable at `InstrumentStatus.php:10/17/20`) have no enforcement making them unreachable.

**Fix shape:** Adopt the Workshop pattern — one `InstrumentStatusMachine` adjacency map (parameterised by `InstrumentDirection`, since the two directions legitimately have different graphs), a typed `InvalidInstrumentTransitionException` (already exists at `Application/Exceptions/`), and route all nine writes through a single transition method that consults it. Delete or wire up `isTerminal()`.

---

### **[MEDIUM] `RemittanceStatus`, `RemittanceLineStatus` and `ReconciliationStatus` are behaviour-free enums — no adjacency map, no `isTerminal()`, no guards at all**

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/Enums/RemittanceStatus.php:7-12` (3 cases, zero methods)
- `.../Domain/Enums/RemittanceLineStatus.php:7-12` (3 cases, zero methods)
- `.../Domain/Enums/ReconciliationStatus.php:7-12` (3 cases, zero methods)
- Write sites — `instrument_remittances.status`: `InstrumentRemittanceService.php:63` (`draft`), `:205` (`remitted`), `InstrumentLifecycleService.php:301` (`closed`), `:568` (`closed`) = 4 sites in 2 files
- Write sites — `instrument_remittance_lines.line_status`: `InstrumentRemittanceService.php:94` (`pending`), `InstrumentLifecycleService.php:296` (`cleared`), `:563` (`bounced`) = 3 sites in 2 files

**Problem:** Compare `BankStatementStatus.php:14-26` **in the same enum directory**, which does have a proper `canTransitionTo()` with an explicit `match` and a `Voided => false` terminal. `RemittanceStatus::Closed` is terminal in intent but nothing says so; protection comes entirely from ad-hoc `assertDraft()` calls and the inline check at `InstrumentLifecycleService.php:355` (`! in_array($remittance->status, [Remitted, Closed], true)`). `RemittanceLineStatus` transitions `Cleared → Bounced` at `InstrumentLifecycleService.php:563` — an already-settled line being un-settled — with no machine acknowledging that edge. And `InstrumentLifecycleService.php:301`/`:568` write `Closed` unconditionally when no `Pending` lines remain, including onto a slip that is **already** `Closed`, with no `from`-state assertion.

**Why it matters:** Each of these state writes is the trigger for a GL posting against a bank repository (`InstrumentLifecycleService.php` clear/bounce arms both create journal entries and repository movements). Because the legality rules are inline `if`s rather than a map, adding any new remittance operation (cancel a slip, un-remit, partial recall — all plausible near-term for cheque handling) has no single place to declare the new edge and no compile-time reminder that `Closed` must stay closed. `ReconciliationStatus` is worse: see the next finding.

**Fix shape:** Give all three the `BankStatementStatus::canTransitionTo()` treatment at minimum; ideally a shared `InstrumentRemittanceTransitionService` as the sole writer.

---

### **[MEDIUM] Treasury status columns are unconstrained strings while a sibling table in the same module carries full CHECK constraints**

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:109` — `$table->string('status', 30)->default('received');` on `payment_instruments`, **no CHECK**
- same file `:153` — `$table->string('status', 30)->default('pending');` on `payments`, **no CHECK**
- `.../2026_07_12_100400_create_instrument_remittances.php:28` — `$table->string('status', 12)->default('draft');`, **no CHECK**
- same file `:46` — `$table->string('line_status', 12)->default('pending');`, **no CHECK**

**Contrast (the idiom exists, in this very module):**
- `.../2026_07_19_110001_create_bank_statements.php:52` — `ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_status_check CHECK (status IN ('imported','reconciling','reconciled','voided'))`
- `.../2026_07_19_110002_create_bank_statement_lines.php:66` — same for `match_status`, **plus** a state-invariant CHECK at `:68-77` tying `ignore_reason` to `match_status = 'ignored'`
- `.../2025_12_14_150000_create_bank_reconciliations_table.php:24` uses `$table->enum('status', [...])` (which does emit a PG CHECK)

**Problem:** Four money-bearing status columns accept any 12–30 char string at the DB layer. `PaymentInstrument.php:89` and `Payment.php:88` list `status` in `$fillable`, and `OutboundInstrumentService.php:691` / `:687` use `forceFill()` which bypasses even that. `instrument_remittance_lines` is `protected $guarded = []` (`InstrumentRemittanceLine.php:30`) — fully mass-assignable.

**Why it matters:** A typo'd string, a mass-assignment from a request payload, a data-repair script, or a future enum rename lands silently. On read, Laravel's enum cast then throws `ValueError` on `InstrumentStatus::from()` for every query that touches the row — a single bad write bricks the instrument list, the maturity alert command (`InstrumentMaturityAlertsCommand.php:107-140`), and the remittance screens, and the row cannot be read back to diagnose it. The team already knows how to prevent this (the July 2026 bank-statement migrations); the Nov 2025 tables never got retrofitted.

**Fix shape:** Retrofit `ALTER TABLE ... ADD CONSTRAINT ..._status_check CHECK (status IN (...))` for all four columns, following `2026_07_19_110001_create_bank_statements.php:52` verbatim. Also consider the state-invariant form used at `bank_statement_lines:68` — e.g. `cleared_at IS NOT NULL` iff `line_status = 'cleared'`, `bounced_at IS NOT NULL` iff `'bounced'`.

---

### **[MEDIUM] `generateExpenseNumber` is an unlocked `max+1` whose scope (company) does not match its DB unique index (tenant)**

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:1005-1023`, called at `:317` (post) and `:847` (reversal)

```php
$lastExpense = Document::query()
    ->where('company_id', $companyId)          // <-- scoped by COMPANY
    ->where('type', DocumentType::Expense)
    ->where('document_number', 'like', "EXP-{$year}-%")
    ->orderByDesc('document_number')
    ->first();                                  // <-- no lockForUpdate, no advisory lock
$nextNumber = $lastExpense !== null ? ((int) substr($lastExpense->document_number, -6)) + 1 : 1;
```

**Problem:** Two defects in one function.
1. **No DB backstop for the race.** No `lockForUpdate()`, no `pg_advisory_xact_lock`. Contrast `InstrumentRemittance::allocateNumber()` at `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/InstrumentRemittance.php:52-70`, which **does** take `SELECT pg_advisory_xact_lock(hashtextextended(?, 0))` before the same `max+1` read — the correct pattern is present in the adjacent module and was not applied here. `workshop_work_order_sequences` (`database/migrations/tenant/2026_04_19_130006_...`) is a third, stronger precedent.
2. **Scope mismatch with the unique index.** The generator dedupes within `company_id`; the only unique index is `documents_tenant_id_type_document_number_unique` on **`(tenant_id, type, document_number)`** (`database/migrations/tenant/2025_12_30_085029_make_document_number_nullable_on_documents_table.php:24`). In a tenant with two companies, company B's first expense post generates `EXP-2026-000001`, which company A already holds → unconditional unique violation. The generator's own dedupe is *narrower* than the constraint it must satisfy.

**Why it matters:** `post()` is a single `DB::transaction` (`:311-...`) that also writes the GL entry, the VAT detail, and (for linked costs) the WAC capitalisation. A duplicate-key 500 rolls the whole thing back with no retry loop, so under two concurrent expense posts one operator simply gets an error; in a multi-company tenant, expense posting in the second company is broken permanently until the numbers diverge. This is on the launch path — a parapharmacy posts rent/supplier/utility expenses from day one.

**Fix shape:** Add `pg_advisory_xact_lock` keyed on `expense_number:{$companyId}` exactly as `InstrumentRemittance::allocateNumber()` does, **and** either widen the generator's scope to `tenant_id` or add a `(tenant_id, company_id, type, document_number)` unique index so generator scope and constraint scope agree.

---

### **[LOW-MEDIUM] `bank_reconciliations` / `ReconciliationStatus` / `payments.is_reconciled` are orphaned duplicate state that can never agree with the live reconciliation surface**

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/Enums/ReconciliationStatus.php` — the string `ReconciliationStatus` appears **nowhere else** in `app/` (verified `grep -rn --include='*.php' "ReconciliationStatus" app/` → 1 hit, its own declaration)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_12_14_150000_create_bank_reconciliations_table.php:14-56` — creates `bank_reconciliations` + `bank_reconciliation_items`; the only `bank_reconciliation` references in `app/` are the unrelated plan-limit flags at `Billing/Domain/PlanLimits.php:147` and `Billing/Application/Services/PlanEnforcementService.php:416`. There is no Eloquent model, controller, or service for either table.
- Same migration `:59-62` adds `payments.is_reconciled` + `payments.reconciled_at`; both are declared `$fillable` and cast on the model (`Treasury/Domain/Payment.php:93-94`, `:126-127`) but **are never written by any code** (verified: the only `is_reconciled|reconciled_at` hits in `app/` are that model declaration plus `payment_repositories.last_reconciled_at`, a different column, written at `StatementCompletionService.php:169`/`:227`).

**Problem:** Reconciliation is actually performed by the July-2026 `bank_statements` / `bank_statement_lines` / `bank_statement_line_allocations` surface (`StatementImportService`, `StatementMatchingService`, `StatementCompletionService`). The December-2025 reconciliation schema and its status enum are dead, but the schema is still deployed and `payments.is_reconciled` still defaults to `false` on every payment row.

**Why it matters:** This is textbook (f) — the same fact ("is this payment reconciled?") has two representations, one of which is permanently `false`. Any report, export, or future feature that reaches for the obvious-looking `payments.is_reconciled` column (it is fillable and cast, so it looks live) will report **zero payments reconciled** regardless of the real statement state, and the second, authoritative answer lives in `bank_statement_line_allocations`. A dead status enum with no writer is also an invitation for a future dev to wire it to the live surface and create a genuine divergence.

**Fix shape:** Drop the dead tables + enum, or explicitly document them as reserved and remove `is_reconciled`/`reconciled_at` from `Payment::$fillable`/`$casts` so the trap is not reachable from code.

---

### **[LOW] `PaymentStatus` has no adjacency map and a dead `isTerminal()`; `Pending`/`Failed` are unwritable states with `pending` as the DB default**

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/Enums/PaymentStatus.php:17-39`

**Problem:** Write-site census on `payments.status`: **4 mutations of an existing row**, all → `Reversed` — `InstrumentLifecycleService.php:852`, `OutboundInstrumentService.php:687` (`forceFill`), `PaymentRefundService.php:239`, `:1299`; plus **11 creation sites** all → `Completed` (`MultiPaymentService.php:101/200/386`, `PaymentRefundService.php:192/357/1219/2172`, `VendorRefundService.php:128`, `PaymentController.php:909/1504`, `TreasuryReceiptBridge.php:1414`, `TreasuryAccountPaymentBridge.php:207`, `TreasuryDepositBridge.php:191`). All four mutations **are** guarded (`PaymentRefundService.php:114`/`:1105` reject already-`Reversed`; `:118`/`:1109`/`:285`/`:992` require `Completed`; `InstrumentLifecycleService.php:756` uses `canReverse()`; `OutboundInstrumentService.php:584` requires `Completed`), so no terminal-state violation is reachable today. But: `isTerminal()` at `:25` is never called; `Pending` and `Failed` are never written by any of the 15 sites, yet `pending` is the column default (`2025_11_30_120000_create_treasury_tables.php:153`); and there is no `payment_status_transitions` audit table (only `workshop_work_order_status_transitions` and `scheduling_appointment_status_transitions` exist — verified by `ls database/migrations/tenant/ | grep -i transition`).

**Why it matters:** Low today because the guards are complete. It stays low only as long as every new reversal path remembers all four checks; there is no `PaymentTransitionService` chokepoint and no per-transition audit row proving *who* reversed *what*, *when* — the fiscal surface `payment_instruments` got exactly that treatment (see CLEAN list) and `payments` did not.

**Fix shape:** Either delete the dead cases/method, or promote `canReverse()` into a full adjacency map behind a single transition service. Consider mirroring `instrument_events` for payments.

---

### **[LOW] Dishonor routing depends on `orderByDesc('id')` over a UUID PK — correct only because `HasUuids` currently emits *ordered* UUIDs**

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:346-354`

```php
$line = InstrumentRemittanceLine::query()
    ->where('instrument_id', $instrument->id)
    ->whereIn('line_status', [RemittanceLineStatus::Pending, RemittanceLineStatus::Cleared])
    ->orderByDesc('id')          // <-- "newest" == UUID sort order
    ->lockForUpdate()->first();
...
$remittance = InstrumentRemittance::query()->lockForUpdate()->findOrFail($line->remittance_id);
$repository = PaymentRepository::query()->findOrFail($remittance->bank_repository_id);   // :358
```

**Problem:** An instrument that has been re-presented after a bounce owns multiple `instrument_remittance_lines` rows across different slips. "Which slip is this dishonor against?" is answered by sorting on the UUID primary key. The table has **no timestamps** (`InstrumentRemittanceLine.php:28: public $timestamps = false;`) and the migration defines no `created_at` (`2026_07_12_100400_create_instrument_remittances.php:41-54`), so the PK is the *only* available ordering. It happens to be chronological today solely because `InstrumentRemittanceLine` uses Laravel's `HasUuids` (`:26`), whose `newUniqueId()` returns a time-ordered `Str::orderedUuid()`.

**Why it matters:** The selected line determines `$remittance`, which determines `$repository` at `:358` — i.e. **which bank account the dishonor's cash movement and GL entry hit**. Swapping to `HasVersion7Uuids`, a DB-side `gen_random_uuid()` default, or plain `Str::uuid()` (any of which reads like a harmless refactor) silently randomises the ordering and starts routing bounce reversals to the wrong bank repository. Note the repo already has a standing pitfall entry about UUID ordering in PG (`project_pg_uuid_latestofmany_pitfall.md`).

**Fix shape:** Add `created_at` to `instrument_remittance_lines` and order on it, or resolve the slip explicitly via `$instrument->remittance_id` rather than by PK sort.

---

### **[LOW] Remittance number allocator wraps at 10 000 and then deadlocks itself**

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/InstrumentRemittance.php:61-69`

**Problem:** `substr($lastNumber, -4)` + `sprintf('REM-%s-%04d', ...)`. At 9999, the next value is `REM-2026-10000` (5 digits — `%04d` is a *minimum* width). On the following call, `orderByDesc('number')` sorts lexicographically, so `REM-2026-9999` ranks above `REM-2026-10000`; `substr(-4)` yields `9999`; the allocator re-emits `REM-2026-10000`, which collides with `instrument_remittances_company_number_uniq` (`2026_07_12_100400_create_instrument_remittances.php:35`) — permanently, for the rest of the year.

**Why it matters:** Genuinely unreachable for a single parapharmacy (10 000 remittance slips in one year), so LOW. Recorded because the failure mode is a hard stop on all remittance creation rather than a degradation, and the identical `substr(-N)` + `%0Nd` shape appears in `ExpenseService::generateExpenseNumber` (`:1016/:1022`, 6 digits).

**Fix shape:** Widen the parse to the full suffix after the last `-`, and order by the numeric suffix rather than the raw string.

---

## CLEAN list — genuinely well-governed Treasury surfaces

1. **`payment_repositories.balance` — the strongest guard in the repo.** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_160000_forbid_direct_payment_repository_balance_writes.php:44-72`: a PG `BEFORE INSERT OR UPDATE` trigger rejects **any** balance change unless the session GUC `app.treasury_movement_port = 'on'` (set only inside `TreasuryMovementService`), *and* rejects rows born with a non-zero balance so an opening balance must arrive as a real movement with its backing ledger leg. This is a DB-enforced single-writer port — stronger than the Workshop pattern. No further work needed.

2. **`instrument_events` — a better transition log than Workshop's.** `.../2026_07_12_100200_create_instrument_events.php:17-36` records `event_type`, `from_status`, `to_status`, `from/to_repository_id`, `remittance_id`, `journal_entry_id`, `movement_id`, `payload`, `occurred_at`, `created_by`; `.../2026_07_12_100300_create_instrument_events_immutability.php:15-53` adds plpgsql triggers that raise on UPDATE, DELETE **and** TRUNCATE, then `REVOKE TRUNCATE` from the app role. Every instrument transition in `InstrumentLifecycleService` and `OutboundInstrumentService` writes one. Requirement (e) is fully satisfied for the instrument surface.

3. **`bank_statements` + `bank_statement_lines` — the exemplary migration pair.** `.../2026_07_19_110001_create_bank_statements.php:52-53` (status CHECK + `period_end >= period_start`), `:44-45` (unique `(id, payment_repository_id)` and `(payment_repository_id, source_file_sha256)` — the SHA index makes duplicate-file import structurally impossible); `.../2026_07_19_110002_create_bank_statement_lines.php:63-77` (amount/line-number/direction/match_status/ignore_reason CHECKs **plus** a state-invariant CHECK coupling `ignore_reason` to `match_status='ignored'`), `:40-41` (unique `(statement, line_number)` and `(repository, fingerprint)`). This is the template the four defective Treasury status columns should be retrofitted against.

4. **`BankStatementStatus` — a real adjacency map.** `.../Domain/Enums/BankStatementStatus.php:14-26`: explicit `match`, self-loops forbidden, `Voided => false` terminal with no outgoing edges, `Reconciled` only reopenable to `Reconciling`. Consulted before every write: `StatementImportService.php:246` (void), and the remaining writes are all guarded on an explicit `from` state — `StatementCompletionService.php:45` (`!== Reconciling` → throw, then `:166` → Reconciled), `:198` (`!== Reconciled` → throw, then `:217` → Reconciling), `StatementMatchingService.php:285-292` (rejects `Reconciled|Voided`, else `Imported → Reconciling`). Terminal-state reachability verified clean.

5. **`StatementLineMatchStatus` — derived, not assigned.** `.../Domain/Enums/StatementLineMatchStatus.php:19-40`: a pure `derive()` function computing the status from `(ignored, resolvedByCreation, allocationTotal, lineAmount, scale)` using `bccomp`, throwing when allocations exceed the line or go negative. Status cannot drift from the allocations because it is a function of them — the best possible answer to defect class (f), and it is backed by the DB CHECK at `bank_statement_lines:66`.

6. **`InstrumentRemittance::allocateNumber()` — race-free numbering.** `.../Domain/InstrumentRemittance.php:52-59`: `pg_advisory_xact_lock(hashtextextended('remittance_number:{companyId}', 0))` taken inside the creating transaction, backstopped by the `(company_id, number)` unique index at `2026_07_12_100400_...:35`. Correct on both legs of defect class (d) (modulo the LOW wrap-around above). This is the pattern `ExpenseService::generateExpenseNumber` should copy.

7. **Instrument direction partitioning.** Despite there being two lifecycle services over one table, the partition is airtight: `InstrumentLifecycleService.php:145/188/210/335/612/739` throw on `InstrumentDirection::Outbound`, and `OutboundInstrumentService.php:97/248/408/557` throw on anything not Outbound. I specifically hunted for an unguarded inbound entry point reachable with an outbound instrument and found none.

8. **Remittance line-add concurrency.** `InstrumentRemittanceService::addLine` (`:69-100`) takes `lockForUpdate()` on the instrument then the slip in a documented global order, asserts Draft, checks for an existing active line, and is backstopped by the `(remittance_id, instrument_id)` unique index (`2026_07_12_100400_...:50-53`). `removeLine` (`:104-117`) follows the same lock order. Both legs of (d) covered.

9. **Expense document lifecycle.** `ExpenseService.php:130` hardcodes `DocumentStatus::Draft` on create (the client-supplied `status` validated at `Presentation/Requests/ExpenseRequest.php:79` is an **inert** rule — the service never reads it, so there is no privilege-escalation-to-Posted path); `:179` and `:311` both guard `!== Draft`; `:611` and `:820` guard `!== Posted` before unpost/reverse. Verified by reading the full create/update/post bodies (`:121-340`), not just grepping. (Suggest deleting the inert `status` rule so it never becomes live by accident — noted, not counted as a finding.)

10. **Payment reversal guards.** Although `PaymentStatus` has no map (see LOW above), I traced every one of the 4 mutation sites and all are correctly gated on `Completed` / rejected when already `Reversed`. No terminal-state violation for `payments.status` is reachable in current code.

---

**Coverage note:** all seven assigned enums were read in full and their write sites enumerated. `PaymentAllocationService` and `StatementSuggestionService` were reviewed only at the grep level for status writes (they write `documents.status`, which is exclusion #3, not a Treasury status column). `payment_repositories` freeze/unfreeze was not separately audited beyond the balance-write trigger.

---

## Inventory sweep

*Source: background agent transcript `agent-a802df960070dd6f5.jsonl` — final assistant message, extracted verbatim.*

# INVENTORY LIFECYCLE SWEEP — FINDINGS

Precedent read first: `apps/api/app/Modules/Workshop/WorkOrder/Domain/Services/StatusMachine.php:20-88` (total `match`, terminal states → `[]`, self-loops forbidden), single write path `WorkOrderTransitionService`, typed `WorkOrderTransitionException`, append-only `database/migrations/tenant/2026_04_19_130004_create_workshop_work_order_status_transitions_table.php`. Comparison baseline for everything below.

---

## [SEVERITY HIGH] Counting `finalize()` takes no row lock — the only stock-moving inventory finalize that doesn't, and its downstream idempotency guards are read-then-write

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:1012-1108` (`finalize()`)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:459` — `InventoryCounting::forCompany($companyId)->findOrFail($countingId)`, plain read
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:61, :130-137, :195-213`

**Problem:** `finalize()` opens `DB::transaction` at :1051 and calls `$counting->transitionTo(CountingStatus::Finalized)` at :1075. There is no `lockForUpdate()` anywhere on the path — verified across the controller (`grep -n lockForUpdate InventoryCountingController.php` → no hits) and the service method body. Every sibling stock-moving document in this module *does* lock:
- `GoodsReceiptService.php:206-214` — `lockForUpdate()->firstOrFail()` then `if ($lockedReceipt->status !== GoodsReceiptStatus::Draft) throw`
- `StockAdjustmentDocumentService.php:177-178` — `lockHeader()` + `assertStatus(..., Draft, 'post')`
- `SupplierGoodsReturnNoteService.php:417-435` — `lockForUpdate()`, then explicit already-Confirmed short-circuit
- `StockTransferService.php:276-280` / `:388-392` — `lockTransfer()` + `canBeCompleted()` / `canBeCancelled()`

Counting is the outlier. Under READ COMMITTED two concurrent finalize requests (double-click, client retry, two supervisors) both read `pending_review`, both pass `CountingStatus::canTransitionTo()` (`CountingStatus.php:49`), both commit, both fire `InventoryCountingCompleted`, and Horizon enqueues **two** `ApplyStockAdjustmentsOnCountingCompleted` jobs.

The listener's two idempotency guards are both unlocked read-then-write:
- replay path: `$item->replay_audit !== null` at `:130` — but the items were loaded at `:61`, before the sibling job's write;
- legacy path: an `exists()` probe on `StockMovement` keyed by `reference = 'COUNTING:{number}'` at `:195-204` — a plain `SELECT`, no lock, no unique index behind it.

There is no unique index on `stock_movements` for `(reference_type, reference_id, product_id, location_id, variant_id)` to act as a backstop (the GRN/adjustment/return-note lanes each have a per-line `movement_id` unique index — `2026_07_04_100000_create_goods_receipts_tables.php:62,67`; `2026_08_08_120000_create_stock_adjustments_tables.php:137`; `2026_08_08_160000_create_supplier_goods_return_notes_tables.php:210,215` — counting has none).

**Why it matters:** Tenant #1's first act is an opening/full count; a double-applied count correction writes the variance delta twice onto on-hand and posts two costed movements, so the shrinkage/gain the till later reports is wrong by exactly the variance. `CountingStatus::Finalized` has no outgoing edge (`CountingStatus.php:50`), so there is no in-product path to correct it — the only remedy is a contra stock adjustment authored by hand.

**Fix shape:** Re-read the counting with `lockForUpdate()` inside `finalize()`'s transaction and re-assert `status === PendingReview` before `transitionTo`, exactly as `GoodsReceiptService::post()` does. Additionally, add a partial unique index on `stock_movements (reference_type, reference_id, product_id, location_id, variant_id) WHERE reference_type = 'inventory_counting'` so the second apply fails at the DB rather than succeeding, per the house idiom of putting a DB backstop under every service-level idempotency check.

---

## [SEVERITY MEDIUM] Last-item count submission races into a hard 500 that rolls back the counter's quantity

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:829-876` (`checkPhaseCompletion`), called at `:765` from **inside** the `DB::transaction` opened at `:739`.

**Problem:** `checkPhaseCompletion` does an unlocked count-then-act: `$totalItems = $counting->items()->count()` / `$countedItems = ...->whereNotNull($column)->count()` at `:832-833`, returns early if incomplete, otherwise `$counting->transitionTo(...)` at `:846`. The counting row is never locked and `$counting` is a stale in-memory instance handed down from `submitCount` (`:708`). Two counters (or one counter with two devices) submitting the *last two* items of a phase concurrently both observe `countedItems == totalItems`; the loser's `transitionTo` hits `InventoryCounting.php:233-237` and throws `\InvalidArgumentException`.

That throw is inside the transaction opened at `:739`, so the loser's `$item->submitCount(...)` (`:741`), the `InventoryCountingEvent::recordCountSubmitted` audit row (`:750`) and the assignment progress increment (`:762`) are **all rolled back**. And `\InvalidArgumentException` has no render handler: `grep -c "InvalidArgumentException" bootstrap/app.php` → `0`, `grep -c "LogicException" bootstrap/app.php` → `0`, so it surfaces as a 500, not a 422.

**Why it matters:** On a parapharmacy count with two staff on handhelds, the physically-counted quantity for the final SKU silently disappears and the operator sees a server error with no guidance. The count then cannot complete (that item reads uncounted), and re-submitting fails again because the phase status has already advanced past `count_N_in_progress` — the `submitCount` guard at `:733-735` now rejects it. The session is wedged in a state only Cancel can leave.

**Fix shape:** Lock the counting header (`lockForUpdate`) at the top of `submitCount`'s transaction and re-read it, so `checkPhaseCompletion` serializes; make the completion transition tolerant of "already advanced" (no-op rather than throw). Separately, replace the bare `\InvalidArgumentException` in `InventoryCounting::transitionTo` with a typed `CountingTransitionException` and register a 422 renderer, mirroring `WorkOrderTransitionException` and `TransferStateException` (`StockTransferService.php:279`).

---

## [SEVERITY MEDIUM] Five inventory status columns are unconstrained strings with no CHECK — and two of them have partial unique indexes whose correctness depends on those exact literals

**Where (all under `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/`):**

| Table | Column decl | CHECK on status? | Other CHECKs in the same file |
|---|---|---|---|
| `stock_transfers` | `2026_05_28_120000_create_stock_transfers_table.php:42` | none | `:97` distinct_locations, `:98` cost_nonneg, `:99` quantity_positive |
| `goods_receipts` | `2026_07_04_100000_create_goods_receipts_tables.php:20` | none | none |
| `stock_adjustments` | `2026_08_08_120000_create_stock_adjustments_tables.php:51` | none | `:156` delta_nonzero, `:169` reason_sign |
| `supplier_goods_return_notes` | `2026_08_08_160000_create_supplier_goods_return_notes_tables.php:106` | none | `:189` quantity > 0 |
| `replenishment_requests` | `2026_07_10_100000_create_replenishment_requests_table.php:24` | none | none |

**Problem:** The idiom exists and is used elsewhere in this same directory — `pos_shifts` (`2026_01_08_190641_create_pos_shifts_table.php:81-83` status vocabulary CHECK, `:84-87` state-invariant CHECK, `:69` partial unique index) and `inventory_countings` (`2025_12_02_070000_create_inventory_countings_table.php:114-124`, a `chk_valid_status` I verified covers all 11 `CountingStatus` cases exactly). The five tables above skip it, and three of them add CHECKs for *other* invariants in the same migration — so this is omission, not policy.

The sharp sub-case: two of these tables carry **partial unique indexes filtered on a status string literal**:
- `2026_08_08_120000_create_stock_adjustments_tables.php:146` — `CREATE UNIQUE INDEX stock_adjustments_corrects_unique ON stock_adjustments (corrects_adjustment_id) WHERE corrects_adjustment_id IS NOT NULL AND status <> 'cancelled'` (the "one LIVE correction per document" rule)
- `2026_07_10_100000_create_replenishment_requests_table.php:52,55` — `... WHERE variant_id IS NULL AND status IN ('pending','in_progress')` (the only thing preventing duplicate open replenishment rows)

**Why it matters:** With no vocabulary CHECK, any write path that stores an out-of-enum string — a raw SQL patch, a data fix, an enum value renamed in PHP without a backfill migration — produces a row that PHP's enum cast then fails to hydrate (`Domain/StockAdjustment.php:95`, `Domain/StockTransfer.php:89`), 500-ing every list endpoint that touches it. Worse for the two indexed tables: a value outside `('pending','in_progress')` or a stray `cancelled` variant silently drops the row out of the partial index's predicate, so the uniqueness guarantee the code relies on quietly stops applying, with no error at any layer.

**Fix shape:** Add a `chk_*_status` vocabulary CHECK per table, modelled on `inventory_countings`'s `chk_valid_status`, in a new pgsql-guarded migration. For `stock_transfers` also add the state-invariant CHECK that `pos_shifts_closed_logic` demonstrates (`completed ⇒ completed_at IS NOT NULL`, `cancelled ⇒ cancelled_at IS NOT NULL`) — those columns are written at `StockTransferService.php:348-349` and `:441-443` with no DB guarantee. Pin the enum↔CHECK correspondence with a unit test, the way `stock_adjustment_lines_reason_sign` is pinned by `tests/Unit/Inventory/AdjustmentReasonSignPartitionTest.php` (see the frozen-literal rationale at `2026_08_08_120000...:159-167`).

---

## [SEVERITY MEDIUM] `ReplenishmentStatus` has no adjacency map, and its `isTerminal()` is false — `Fulfilled` has a live outgoing edge back to `Pending`

**Where:**
- Enum: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Replenishment/Domain/Enums/ReplenishmentStatus.php:17-21` — `isOpen()` = Pending|InProgress; `isTerminal()` = `! isOpen()`, i.e. Fulfilled, Rejected and Cancelled all claim terminality
- The contradicting edge: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Replenishment/Application/Listeners/ReopenRequestsOnTransferCancelled.php:24` (selects `status = Fulfilled`) → `:54-61` (`update(['status' => ReplenishmentStatus::Pending, ...])`)

**Problem:** No `allowedTransitions()`, no `canTransitionTo()`, no transition service — the entire lifecycle is 8 scattered raw writes, none of which consults the enum:

1. `Application/Services/ReplenishmentCaptureService.php:114` → Pending
2. `Application/Services/ReplenishmentFulfillmentService.php:163` → Fulfilled
3. `Application/Services/ReplenishmentFulfillmentService.php:178` → InProgress
4. `Application/Services/ReplenishmentFulfillmentService.php:203` → Rejected
5. `Application/Listeners/SettleRequestsOnTransferInitiated.php:66` → Fulfilled
6. `Application/Listeners/ReopenRequestsOnTransferCancelled.php:55` → Pending (**from Fulfilled**)
7. `Application/Listeners/ReopenRequestsOnTransferCancelled.php:106` → Cancelled
8. `Presentation/Controllers/ReplenishmentRequestController.php:148` → Cancelled

Guarding is by hand and inconsistent: site 8 is guarded by `abort_unless($row->status === ReplenishmentStatus::Pending, 422)` at `:141`; sites 2/3/4 by the `whereIn` filter in `openRequests()` (`ReplenishmentFulfillmentService.php:228`); sites 6/7 only by the `where('status', Fulfilled)` in the listener's own query. Nothing centralises the legal edge set, and `isTerminal()` — a method a future author will reasonably trust — is factually wrong about `Fulfilled`.

**Why it matters:** The reopen edge is deliberate and correct (a cancelled transfer must resurrect the demand), but it is invisible to the enum, so any future code that gates on `isTerminal()` before mutating a request will let a Fulfilled row through or block a legitimate reopen. With eight uncoordinated write sites and no adjacency map, a ninth writer is a coin flip.

**Fix shape:** Give `ReplenishmentStatus` a total `allowedTransitions()` that names the compensating `Fulfilled → Pending` edge explicitly (the `Approved → Quoted` "re-quote" edge at `StatusMachine.php:60` is the precedent for encoding a deliberate backward edge rather than hiding it), correct `isTerminal()` to derive from `allowedTransitions() === []`, and funnel all eight sites through one transition method.

---

## [SEVERITY MEDIUM] Check-then-set race in `ReplenishmentFulfillmentService::openRequests()` — the same request can be fulfilled onto two documents

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:222-239`, consumed at `:130` (PO creation) and `:200` (reject).

**Problem:**
```php
$requests = ReplenishmentRequest::query()
    ->where('company_id', $companyId)
    ->whereIn('id', $uniqueIds)
    ->whereIn('status', [ReplenishmentStatus::Pending, ReplenishmentStatus::InProgress])
    ->get();
```
No `lockForUpdate()`. The caller then builds document lines from these rows (`:136-158`) and writes `status => Fulfilled` + `fulfillment_id` at `:162-168`. The sibling listener in the same module demonstrates the correct idiom three times — `ReopenRequestsOnTransferCancelled.php:53`, `:73`, `:89` all use `lockForUpdate()`.

The DB backstop that exists does not cover this: `2026_07_10_100000_create_replenishment_requests_table.php:52,55` constrains *open* rows per `(company, location, product, variant)` — it says nothing about one row being consumed twice.

**Why it matters:** Two buyers (or a retried request) pressing "create PO from requests" on overlapping selections both read the rows as Pending and both emit purchase-order lines, so the supplier is ordered double the quantity — real money out on a parapharmacy's first restock cycle. The second `update()` then overwrites `fulfillment_id`, so `ReopenRequestsOnTransferCancelled` (which matches on `fulfillment_id`, `:26`) can only ever reopen against one of the two documents; cancelling the other leaves the demand permanently settled against a document that no longer exists.

**Fix shape:** Add `->lockForUpdate()` to `openRequests()` (it already runs inside the callers' `DB::transaction`, `:121` and `:199`), and re-assert the open status after acquiring the lock.

---

## [SEVERITY LOW] No transition-audit table for any of the five inventory document lifecycles, though the house has the pattern

**Where:** `grep -rln "transitions" /Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/` returns 7 files; only two are transition logs — `tenant/2026_04_19_130004_create_workshop_work_order_status_transitions_table.php` and `tenant/2026_04_19_140005_create_scheduling_appointment_status_transitions_table.php`. The other five are immutability triggers (`2026_07_08_100200_create_repository_movements_immutability.php`, `2026_05_14_100002_create_fiscal_events_immutability.php`, `2026_05_01_000003_...pending_seal.php`, `2026_05_26_100001_allow_pos_receipt_fk_cleanup.php`, `2026_05_20_100001_create_device_loss_incidents_table.php`).

**Problem:** `stock_transfers`, `goods_receipts`, `stock_adjustments`, `supplier_goods_return_notes` and `replenishment_requests` each move stock or commit money and none records who moved the document between states. What exists instead:
- transfers: `initiated_at`, `completed_at`/`completed_by_user_id`, `cancelled_at`/`cancelled_by_user_id` (`StockTransferService.php:348-349`, `:441-443`) — one slot per edge, overwritten, no from-state
- adjustments: `posted_at`/`posted_by_user_id` only (`StockAdjustmentDocumentService.php:214-215`)
- return notes: `returned_at`/`confirmed_by` only (`SupplierGoodsReturnNoteService.php:465-466`)
- replenishment: `processed_at`/`processed_by_user_id`, overwritten on every reopen (`ReopenRequestsOnTransferCancelled.php:58-59` nulls them, then a re-fulfil rewrites them)

Counting is the partial exception — `InventoryCountingEvent` rows are written for `COUNTING_FINALIZED` (`InventoryCountingService.php:1078-1093`), `COUNTING_CANCELLED` (`:1127-1135`), `THIRD_COUNT_TRIGGERED` (`:961-969`) and count submissions (`:750`) — but not for `Draft→Scheduled` (`:642`), `→Count1InProgress` (`:635`, `:673`), `→CountNCompleted` or `→PendingReview` (`:846`, `:864`, `:873`).

**Why it matters:** A replenishment request that was fulfilled, reopened by a transfer cancellation, then re-fulfilled has an unreconstructable history — the reopen path explicitly nulls the audit columns. For stock documents this is the difference between "we can show the inspector who put this document in the state that moved the stock" and "we can show the current state."

**Fix shape:** One shared append-only `*_status_transitions` table per document lifecycle (or a single polymorphic one), written from the same single service method that performs the status write — the `workshop_work_order_status_transitions` shape.

---

## [SEVERITY LOW] `AssignmentStatus` is a bare 4-case enum with a dead case and no governance

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Domain/Enums/AssignmentStatus.php` (whole file — 4 cases, zero methods: no `label()`, no `allowedTransitions()`, no `isTerminal()`).

**Problem:** Two write sites only, both on the model: `InventoryCountingAssignment.php:144` (`start()` → InProgress) and `:154` (`complete()` → Completed), with a guard at `:136` (`&& $this->status !== AssignmentStatus::Completed`). `AssignmentStatus::Overdue` is **never written anywhere** — `grep -rn "AssignmentStatus::Overdue" app/` returns only the enum declaration at `:12` (the `'overdue'` hits in `InventoryCountingController.php:208`, `MonitoringService.php:392`, `Billing/Domain/Enums/InvoiceStatus.php:14` etc. are unrelated aggregate keys and a different enum). No DB CHECK on `inventory_counting_assignments.status` either.

**Why it matters:** Low blast radius — assignments don't move stock, and the two writes are contained on the model. But `Overdue` is exactly the placeholder-with-no-transition-behind-it that `SupplierGoodsReturnNoteStatus`'s own docblock (`:20-22`) argues the codebase forbids, and a reader gating on `status === Overdue` will build a feature on a value that can never occur.

**Fix shape:** Either implement the overdue transition (a scheduled sweep against `scheduled_end`) or delete the case; add `allowedTransitions()`/`isTerminal()` matching the `StockAdjustmentStatus` shape.

---

# CLEAN — surfaces checked and found genuinely well-governed

**`GoodsReceiptStatus` (draft → posted) — clean, despite the bare 2-case enum.** `GoodsReceiptStatus.php` has no adjacency map, but every write is funnelled and DB-backstopped. `GoodsReceiptService::post()` (`:203-263`) opens a transaction, re-reads with `lockForUpdate()->firstOrFail()` (`:207-210`), asserts `status !== Draft → throw` (`:212-214`), then acquires the product cost lock and stamps `Posted` (`:261`). Terminal `Posted` has no reachable outgoing edge: the only other mutation route, `GoodsReceiptController::destroy` (`:139-158`), refuses anything but Draft with a 422 (`:143-150`). Double-post is additionally impossible at the DB level — `goods_receipt_lines_movement_id_unique` and `goods_receipt_lines_free_movement_id_unique` (`2026_07_04_100000...:62,67`) are partial unique indexes on the per-line movement back-links. Receipt numbering is stamped at post time from `numberingService->generateForKey` (`:255-260`) under `unique(['company_id','receipt_number'])` (`:30`).

**`StockAdjustmentStatus` (draft → posted|cancelled) — the best-governed inventory lifecycle; a genuine second precedent alongside Workshop.** Total `allowedTransitions()` with no `default` so a new case is a compile error, terminal states → `[]`, `isTerminal()` derived from the map, and a docblock (`:14-19`) that explicitly reasons about which house idiom it is following. Every mutating method opens with `lockHeader()` + `assertStatus()` (`:134-135` update, `:177-178` post). The migration is unusually thorough: four partial unique indexes on `(adjustment_id, product_id[, variant][, batch])` (`:130-133`), `stock_adjustment_lines_movement_id_unique` (`:137`), `stock_adjustments_company_number_unique` (`:139`), `stock_adjustments_corrects_unique` enforcing one live contra per document (`:146`), an idempotency-key index (`:151`), and the `reason_sign` CHECK pinned to the enum by a unit test (`:159-172`). Its only gap is the missing status vocabulary CHECK, reported above.

**`SupplierGoodsReturnNoteStatus` (draft → confirmed) — governed, and dpa-v8 landed it correctly.** Two cases with an explicit rationale for why there is no cancel path (`:18-22`). `confirm()` (`SupplierGoodsReturnNoteService.php:413-484`) locks the header (`:417-420`), **short-circuits idempotently** on already-Confirmed by returning the note untouched rather than throwing (`:422-427`) — the cleanest handling of the re-post question I found in this sweep — then falls through to `canBeConfirmed()` (`:429`), refuses batch-tracked products *before* the number stamp or any stock motion (`:449`), and stamps number+status+timestamps in one `forceFill()->save()` (`:462-467`). DB backstops: `supplier_goods_return_notes_credit_note_unique` (one note per credit note, `2026_08_08_160000...:203`), and per-line `..._lines_movement_unique` / `..._lines_cost_movement_unique` (`:210`, `:215`). Terminal `Confirmed` has no reachable outgoing edge — no other write site for that column exists (`grep` over the module returns only `:356` create-as-Draft and `:464` confirm).

**`TransferStatus` (draft → in_transit → completed | cancelled) — governed via per-action predicates rather than an adjacency map, but airtight in practice.** All four write sites live in one service: `:145` (create as Draft), `:558` (initiate → InTransit), `:347` (complete → Completed), `:440` (cancel → Cancelled). Each mutating entry point locks (`lockTransfer()`) and consults the enum before writing: `canBeCompleted()` at `:278-280` and `canBeCancelled()` at `:390-392`, both raising the typed `TransferStateException`. Terminal states are genuinely closed — `isTerminal()` covers Completed and Cancelled (`TransferStatus.php:22-25`) and `canBeCancelled()` admits only Draft and InTransit (`:37-40`), so a Completed transfer cannot be re-completed or cancelled back. Creation is idempotency-keyed with a pre-check (`:106-116`) *and* a unique index behind it (`stock_transfers_idempotency_unique`, `2026_05_28_120000...:67`), and `transfer_number` has `stock_transfers_company_number_unique` (`:66`). The multi-product advisory-lock ordering to prevent AB-BA deadlocks is documented and applied on both the complete (`:282-294`) and cancel (`:398-406`) paths. Gap: the missing status CHECK + state-invariant CHECK, reported above.

**`CountingStatus` adjacency map + DB CHECK — the map itself is exemplary.** `CountingStatus.php:38-58` is a total `match` with both terminal states → `[]` and a `canTransitionTo()`; the model routes every transition through one `transitionTo()` method that guards, stamps the matching timestamp, and saves (`InventoryCounting.php:231-249`). All 8 in-flight transitions go through it (`InventoryCountingService.php:635, 642, 673, 956, 1075, 1125`, `:846`, `:864/873`) — the only two direct `$counting->status =` assignments are on **new, unsaved** models in draft-creation paths (`InventoryCountingController.php:687` `createDraft`, `:1050` `batchCreateDrafts`), which is initialisation, not a lifecycle bypass. `inventory_countings` carries `chk_valid_status` covering all 11 cases exactly (`2025_12_02_070000...:114-124`), plus `chk_valid_scope_type` and `chk_valid_execution_mode` — the strongest schema-level status governance in the inventory surface. Draft-only mutation is enforced on both product endpoints (`addProduct` `:751-756`, `removeProduct` `:836-840`) and count submission is phase-gated (`InventoryCountingService.php:727-735`). The defects reported above are concurrency and error-surface, not the state model.

**Counting numbering — race exists but has a DB backstop.** `generateCountingNumber()` (`InventoryCountingService.php:1266-1283`) is a `lockForUpdate()` + `orderByDesc` + `+1`, which locks nothing when no row yet exists for the year — so two concurrent first-activations of a calendar year both derive `CNT-2026-0001`. This is caught by `unique(['company_id','counting_number'])` (`2026_03_04_100000_add_tenant_id_and_counting_number_to_inventory_countings.php:17`), so it degrades to a failed request, not a duplicate number. Not reported as a finding: the brief's defect (d) is a check-then-set race *without* a DB backstop, and here the backstop is present. (The surfaced error is a raw 23505 rather than a retry, which is a polish item.)

**`ExpiryStatus` / batch FEFO state — not a lifecycle at all; correctly derived.** `ExpiryStatus.php` is a pure presentational/derivation enum (`color()`, `canSell()`, `label()`, `daysThreshold()`). There is **no** `expiry_status` column anywhere — `grep -rn "expiry_status" app/ database/migrations/` returns five hits, all output serialisation (`BatchSuggestionDTO.php:35-37`, `BatchResource.php:37`, `StockTransferController.php:332`), each computing it on the fly from the batch's expiry date. Nothing to govern, nothing to diverge; this is the right shape for a derived state and the correct counter-example to defect class (f).

**No raw `DB::table(...)->update(['status' => ...])` anywhere in Inventory or Replenishment.** Verified: `grep -rn "DB::table(" app/Modules/Inventory app/Modules/Replenishment` returns 20 hits, every one a read (`StockMatrixQueryService`, `LocationStockQueryService`, `StockLevelData`, `InventoryValuationModeResolver`, `LateSyncResidualDetector`, `SupplierGoodsReturnNoteService:813`) or the one-time `StockLevelMigrationService` backfill (`:58-123`). Every status write in these two modules goes through Eloquent with the enum cast applied — so the enum casts on `StockTransfer.php:89`, `GoodsReceipt.php:71`, `StockAdjustment.php:95`, `SupplierGoodsReturnNote.php:80`, `InventoryCounting.php:99`, `ReplenishmentRequest.php:80` are not bypassed at runtime. This is what makes the missing DB CHECKs a latent rather than an active defect.

**`ApplyStockAdjustmentsOnCountingCompleted` queue-retry idempotency — correct for its stated threat model.** Both paths carry a documented applied-marker: the replay path stamps `replay_audit` in the *same* transaction as its stock movement (`:288-336`, and the comment at `:288-295` explicitly names the crash-between-movement-and-marker window it closes), and the legacy path probes for an existing `COUNTING:{number}` movement discriminated by `(product, location, variant)` (`:187-213`). Against the sequential `$tries = 3` retry it defends, this is sound. It is only the *concurrent* double-job scenario (F1) that defeats it, and that originates upstream in the unlocked `finalize()`, not here.

---

## Fiscal + device/sync sweep

*Source: background agent transcript `agent-a8e2f11c82ab7f754.jsonl` — final assistant message, extracted verbatim.*

## FISCAL + DEVICE/TERMINAL/SYNC state sweep — findings

Baseline read first: `apps/api/app/Modules/Workshop/WorkOrder/Domain/Services/StatusMachine.php:28-86` (adjacency map, terminal states with `[]` outgoing), `.../Application/Services/WorkOrderTransitionService.php`, `.../Domain/Exceptions/WorkOrderTransitionException.php`, `database/migrations/tenant/2026_04_19_130004_create_workshop_work_order_status_transitions_table.php`. Also read the good in-surface precedents: `pos_shifts` migration (CHECK + state-invariant CHECK + partial unique index) and `device_loss_incidents` migration (CHECK-pinned lifecycle whitelist).

---

### [SEVERITY HIGH] `voided` is not a terminal state on `pos_receipts` — a voided fiscal receipt is fully mutable, including back to `fiscalized`

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:59-155` (this is the current `prevent_receipt_modification()` body — it is the last `CREATE OR REPLACE` of that function in the tenant migration set; earlier bodies at `2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php:30-70` and `2026_01_08_190637_create_pos_receipts_table.php` have the identical hole).

**Problem:** The UPDATE path is a sequence of `IF OLD.fiscal_status = …` branches covering exactly two source states:
- `pending_seal → fiscalized` (line 60) → `RETURN NEW`
- `fiscalized → voided` (line 64, with an immutable-field diff check) → `RETURN NEW`
- `OLD.fiscal_status = 'fiscalized'` catch-all raise (line 143)
- `OLD.fiscal_status = 'pending_seal'` narrow raise (lines 148-152)

There is **no branch for `OLD.fiscal_status = 'voided'`** (nor for `pending_sync` / `synced` / `sync_failed`, all three of which the column CHECK admits — `2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:54-55`). Any UPDATE against a row already at `voided` falls straight through every guard to the bare `RETURN NEW` at line 155. That permits, at the DB layer, `voided → fiscalized`, `voided → pending_seal`, and free rewriting of `fiscal_hash`, `receipt_number`, `total`, `subtotal`, `tax_amount`, `chain_sequence`, `posted_at` on a voided row. The void branch's own field-integrity check (lines 65-74) only runs on the `fiscalized → voided` edge, not afterwards.

**Why it matters:** For an NF525-style till, `voided` is the archival end-state that keeps the void auditable — the row must stay frozen so the chain and the Z totals reconcile. Today a buggy service, a bulk `DB::table('pos_receipts')->update(...)`, or a support script can silently resurrect a voided receipt into the `fiscalized` set (it would then be counted by every consumer that filters `where('fiscal_status', Fiscalized)` — `ReportGenerationService.php:532/545/623`, `Nf525DataProvider.php:134/162/183`, `ReceiptQrIndexSyncService.php:45`) or rewrite its totals after the fact, with the hash chain still pointing at the old bytes. The trigger is the only backstop; there is no application-layer state machine above it.

**Fix shape:** Add the missing terminal branch immediately before the final `RETURN NEW`: raise on any UPDATE where `OLD.fiscal_status = 'voided'` (allow only a strict no-op), and add an explicit `ELSE RAISE` default so a newly-admitted `fiscal_status` value can never fall through silently again. Mirror the `fiscal_events` trigger's shape (`2026_05_14_100002_create_fiscal_events_immutability.php:206-235`), which does exactly this: named transitions, `ELSE RAISE EXCEPTION`.

---

### [SEVERITY HIGH] Terminal claim (`hardware_identifier`) is an unlocked check-then-set with no unique index — two devices can bind to one fiscal chain, and one device can hold many terminals

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:334-375` (`claim`)
- `.../TerminalController.php:385-405` (`requestTerminal`)
- `.../TerminalController.php:496-503` (`findByDevice`)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:51`

**Problem:** `claim()` loads the terminal with `->firstOrFail()` at line 342 — **no `lockForUpdate()`, no `DB::transaction()`** — tests `if ($terminal->hardware_identifier !== null)` at line 362, then writes `$terminal->update(['hardware_identifier' => …])` at line 371. The migration declares `$table->string('hardware_identifier', 100)->nullable();` with **no index and no unique constraint**; I grepped the entire `database/migrations/` tree for `hardware_identifier` and that line 51 is the only hit. The other terminal-uniqueness invariants in this table *are* DB-pinned (`pos_terminals_unique_code`, and the partial uniques added in `2026_02_19_000002_add_type_to_pos_terminals.php:27` and `2026_05_22_101000_add_virtual_admin_terminal_type.php:16-20`), so the idiom exists and was skipped here.

Two independent corruption paths:
1. **Race:** two devices POST `/pos/terminals/claim` for the same terminal concurrently. Both read `hardware_identifier = NULL`, both pass line 362, both write. Last-writer-wins on the column, but *both clients believe they own the terminal*.
2. **Not even racy:** `requestTerminal()` (line 405) writes `hardware_identifier` straight into `Terminal::create()` with no uniqueness check at all. One device can create N terminals all carrying its own hardware id. `findByDevice()` then resolves with `->first()` (line 503) with **no ordering**, so which terminal a device gets back is whatever Postgres returns first — and can change between calls.

**Why it matters:** The terminal *is* the fiscal chain identity — `pos_terminals.genesis_seed` / `current_sequence` / `last_hash` (migration lines 39-42) and the `fiscal_events` chain unique key `(tenant_id, company_id, terminal_id, chain_context, sequence_number)`. Two physical tills authoring against one `terminal_id` produce sequence collisions that the ingestor correctly refuses — routing real sales into `fiscal_event_quarantine` as `sequence_conflict` — i.e. lost/parked revenue on a live till, needing manual admin resolution per incident. A device that non-deterministically flips between two terminals interleaves two chains. For a single-till parapharmacy launch this is one mis-click away during setup.

**Fix shape:** (a) partial unique index `CREATE UNIQUE INDEX … ON pos_terminals (tenant_id, company_id, hardware_identifier) WHERE hardware_identifier IS NOT NULL AND deleted_at IS NULL` — the DB backstop; (b) wrap `claim()` in `DB::transaction` + `Terminal::lockForUpdate()`, and catch `23505` → the existing `TERMINAL_ALREADY_CLAIMED` 409; (c) apply the same guard in `requestTerminal()`; (d) make `findByDevice()` deterministic (`orderBy('created_at')`) as belt-and-braces. A `release`/`unclaim` path with an explicit clear should be added at the same time — `deactivate()` (line 275-289) does **not** clear `hardware_identifier`, so today there is no supported way to re-home a terminal to replacement hardware.

---

### [SEVERITY MEDIUM] Every fiscal status column is an unconstrained string — zero CHECK constraints across `fiscal_events`, `fiscal_event_projections`, `fiscal_event_quarantine`

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_14_100001_create_fiscal_events_table.php:64` (`signature_status`, 16), `:78` (`integrity_status`, 24), `:86` (`payload_parse_status`, 16)
- `.../2026_05_14_100003_create_fiscal_event_projections_table.php:45` (`projection_status`, 20)
- `.../2026_05_14_100004_create_fiscal_event_quarantine_table.php:72` (`payload_parse_status`, 16)

**Problem:** `grep -rn "integrity_status\|payload_parse_status\|signature_status\|projection_status" database/migrations/tenant/*.php | grep -i check` returns **nothing**. Meanwhile, in the *same* `fiscal_events` migration, `chain_context` and `event_type` both get value whitelists (`:125`, `:137`), the hashes get format CHECKs (`:126-127`), and `sequence_number` gets `> 0` (`:124`). The sibling lifecycle table `device_loss_incidents` pins its `recovery_status` whitelist explicitly (`2026_05_20_100001_create_device_loss_incidents_table.php:84-88`) and the migration's own docblock (`:28-30`) calls this the "Task 9 / Task 10 standing pattern". `fiscal_event_quarantine` even pins `integrity_exception_class` (`:112-116`) while leaving `payload_parse_status` beside it unconstrained.

This bites concretely at `OutboxIngestor.php:995`, which inserts `'projection_status' => 'pending'` as a **raw magic string** (not `ProjectionStatus::Pending->value`, unlike the other five write sites) — a typo there writes an unrepresentable value the DB happily accepts, and every reader (`FiscalEventProjectionDispatcher.php:148/180`, `RetryFiscalProjectionsCommand.php:301-304`) filters by exact value, so the row becomes invisible to the dispatcher *and* to the retry tool.

**Why it matters:** `fiscal_events.integrity_status` is the column every projector and the NF525 exporter gate on (`ZReportProjection.php:56/315`, `ZSessionLifecycleProjection.php:51`, `Nf525DataProvider.php:1197`, `VerifyEventChainCommand.php:422`, `Nf525XmlBuilder.php:593`). The immutability trigger validates *transitions* but only fires on UPDATE — an INSERT with an out-of-set `integrity_status` is unconstrained, and it would then be neither `verified` (so never projected) nor `quarantined` (so never surfaced by the incident view). A sale silently disappears from both the ledger projection and the operator's exception queue. The device SQLite side already does this right: `apps/pos/src/lib/db/migrations.ts:969` pins `CHECK (sync_status IN ('pending','syncing','synced','failed'))`, and `apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:290` explicitly tests rejection of out-of-set values. The server is the weaker layer.

**Fix shape:** One migration adding five CHECK whitelists, matching the `device_loss_incidents` pattern; add each enum's docblock note that widening the enum requires widening the CHECK. Separately, replace the raw `'pending'` at `OutboxIngestor.php:995` with `ProjectionStatus::Pending->value` (rule 9).

---

### [SEVERITY MEDIUM] `ProjectionStatus` has no adjacency map or `isTerminal()`; 11 write sites across 6 files, with the only terminal-state protection hand-rolled inside one job

**Where:**
- Enum: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php:7-13` — four bare cases, no `canTransitionTo`, no `isTerminal`, no `label()`, nothing.
- Write sites: `ApplyFiscalEventProjectionJob.php:314` (Running), `:428` (Applied), `:470` (DeadLettered), `:549` (Pending), `:568` (DeadLettered), `:613` (Pending); `FiscalEventProjectionDispatcher.php:123` (insert); `OutboxIngestor.php:995` (insert); `ParseFailureResolutionService.php:201`; `EnqueueResolvedEventProjectionsCommand.php:358`; `RetryFiscalProjectionsCommand.php:366`.

**Problem:** 11 writes, 6 files. The invariant "`Applied` and `DeadLettered` are terminal" is encoded exactly once, as a literal `if` inside `ApplyFiscalEventProjectionJob::handle`'s `T_lock` (`:257-262`), plus a second ad-hoc re-check at `:469`. Five of the eleven write sites live **outside** that job and are governed by nothing but their own local `if`. `RetryFiscalProjectionsCommand::resetProjectionRow` (`:364-370`) drives `DeadLettered → Pending` — a terminal-state exit — gated only by a private `isRetryable()` predicate (`:357-361`) and no lock; `ParseFailureResolutionService.php:201` and `EnqueueResolvedEventProjectionsCommand.php:358` each independently write `Pending` over whatever was there.

Credit where due: the job itself is genuinely careful (`lockForUpdate()` at `:236`, stale-running guard, symmetric failure reset). The defect is that its care is not extractable or reusable — a seventh caller gets none of it.

**Why it matters:** `DeadLettered` is the operator-visible "this fiscal event never reached its projection" state — it gates `RefundCompensationService.php:127` (refusing compensation) and drives `DeadLettered ProjectionsController`. If any ungoverned caller flips a `DeadLettered` row back to `Pending`, the incident vanishes from the operator queue while the underlying projection failure persists; if one flips an `Applied` row to `Pending`, the projector re-runs and double-applies (the idempotency guarantee is the terminal-state short-circuit, not the projector itself).

**Fix shape:** Move the adjacency map onto `ProjectionStatus` (`allowedTargetsOf()` + `isTerminal()`), exactly as `IngestionStatus::allowedNext()` already does one module over (see CLEAN list), then funnel all six files through a single `ProjectionTransitionService` that consults it and throws a typed exception. The retry command's terminal exit then becomes an explicit, named, force-flagged edge rather than an unmodelled one.

---

### [SEVERITY MEDIUM] Z-chain recovery hands the device a `count()`-derived chain position

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:566-598` (`zChainState`), specifically `:592`.

**Problem:** The endpoint is documented (`:557-560`) as the source the client uses to "resume its Z-chain from server state" after local DB loss. It returns `'z_hash_sequence' => ZReport::forTerminal($terminal->id)->count()` — the chain position is **derived by counting rows**, not read from a stored sequence, while the sibling field on the same response reads the real stored `z_number` off `orderByDesc('z_number')->first()` (`:577-594`). It also issues a second full `count()` query against a set it already loaded.

**Why it matters:** `count()` equals the max chain position only if the Z series is gapless and nothing was ever pruned. `pos_z_reports` has a genuine append-only trigger (`2026_06_11_120000_create_pos_z_reports_immutability_trigger.php`), so deletion isn't the risk — but a terminal that ever had Z reports created outside the current sequence source, or any future partition/archive of old Z rows, makes `count() ≠ sequence`. The device then rebuilds its chain from a wrong offset and every subsequent Z hash links to the wrong predecessor — detected only later, by `verifyZReportChain`, as a broken chain across the recovery boundary.

**Fix shape:** Return the stored position (`$latestZReport->z_number`, or an explicit `z_hash_sequence` column if the two are genuinely different concepts) rather than a row count. If they must stay separate, store the hash sequence on the row.

---

### [SEVERITY MEDIUM] No transition-audit table for any fiscal or device status change — the precedent exists in this repo and was not applied to the compliance surface

**Where:** `grep -rln "transitions" /Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/` returns audit tables only for **Workshop** (`2026_04_19_130004_create_workshop_work_order_status_transitions_table.php`) and **Scheduling** (`2026_04_19_140005_create_scheduling_appointment_status_transitions_table.php`). The remaining hits are immutability-trigger migrations, not transition logs.

**Problem:** There is no append-only record of *who moved what, when* for: `pos_receipts.fiscal_status` (`fiscalized → voided`), `pos_terminals.is_active` / `hardware_identifier` (claim / activate / deactivate), `fiscal_event_projections.projection_status` (notably the operator-driven `DeadLettered → Pending` at `RetryFiscalProjectionsCommand.php:366`), and `device_loss_incidents.recovery_status`. Terminal activate/deactivate/training-toggle do fire domain events (`TerminalActivatedAudit` at `TerminalController.php:246`, `TerminalDeactivated` at `:290`, `TerminalTrainingModeChanged` at `:549`) — but `claim()` (`:334-375`) fires **nothing at all**, and events are not a queryable append-only register.

**Why it matters:** Under NF525-style obligations the question at audit time is "prove nobody un-voided a receipt / re-pointed a terminal / un-dead-lettered a projection". Today the answer has to be reconstructed from `updated_at` and Horizon logs. The Workshop table shows the shape the repo already agrees on.

**Fix shape:** One append-only `fiscal_status_transitions` register (entity type, entity id, from, to, actor, reason, timestamp) written by the single transition service each of the above surfaces should route through — pairing naturally with the `ProjectionTransitionService` from the previous finding. Start with the receipt void edge and the terminal claim/release edge; those are the two that touch money and chain identity.

---

### [SEVERITY MEDIUM] `DeviceLossIncidentStatus` lifecycle is documented in prose and implemented nowhere — zero write sites

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Enums/DeviceLossIncidentStatus.php:7-26`; model `.../Domain/Models/DeviceLossIncident.php:65-94`; migration `.../2026_05_20_100001_create_device_loss_incidents_table.php:66-71, 84-88, 126-130`.

**Problem:** The enum docblock states the intended machine — `Reported → Recovering → Resolved | Unrecoverable` (`:10-14`) — but that map exists **only as a comment**: no `allowedNext()`, no `isTerminal()`. More materially, `grep -rn "recovery_status\|DeviceLossIncidentStatus::" --include='*.php' app/` returns **six hits, all of them the enum file, the model's `$casts`/`$fillable` docblocks, or comments — not one write site anywhere in the application**. The migration builds the CHECK whitelist (`:84-88`), the partial "open incidents" worker index (`:126-130`), and the FK set, and the model deliberately excludes the column from `$fillable` "so transitions go through explicit service code" (`:65-70`) — but that service code does not exist. Nothing in the codebase ever advances an incident past the DB default `'reported'`.

**Why it matters:** This is the register that spec §12 designates as the server-side audit trail for a lost/stolen till, carrying `unsynced_count_at_incident` — the count of fiscal events that existed only on the device. For a single-terminal retail till, terminal loss is the scenario where "how many sales did we lose and were they recovered?" is the entire compliance question. The table is wired and indexed for a workflow that cannot be driven; an incident filed today is permanently stuck at `reported`, so the "open incidents" worker index matches every row forever and nothing distinguishes recovered from lost.

**Fix shape:** Either implement the recovery service with the adjacency map promoted from the docblock into code (`allowedNext()` + `isTerminal()`, `Resolved`/`Unrecoverable` returning `[]`), or explicitly mark the register as not-yet-operational so it isn't mistaken for a live control. Given the launch context, knowing which of the two it is matters more than which is chosen.

---

### [SEVERITY LOW] `SignatureStatus` is structurally unreachable — the immutability trigger freezes the column it is supposed to advance

**Where:** enum `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Domain/Enums/SignatureStatus.php:9-12`; trigger `.../database/migrations/tenant/2026_05_14_100002_create_fiscal_events_immutability.php:96-100`.

**Problem:** The enum models an async signing lifecycle (`NotRequired`, `Pending`, `Signed`, `Failed`). But the `fiscal_events` immutability trigger's frozen-column whitelist (Step 1) explicitly includes `NEW.signature_status IS DISTINCT FROM OLD.signature_status` (`:96`) alongside `signature_algorithm`, `signature_value`, `signature_counter`, `signature_provider`, `signing_device_id`, `certificate_id`, `signed_payload_ref` (`:96-103`) — any change raises. So `Pending → Signed` can **never** occur on a persisted row. Currently latent: all four write sites set `NotRequired` (`OutboxIngestor.php:837`, `TerminalRegistrySnapshotService.php:320`, `VirtualAdminFiscalEventService.php:167` and `:344`), and `VerifyEventChainCommand.php:641` asserts `NotRequired` as the expected value.

**Why it matters:** No live impact for tenant #1. It is a trap for whoever implements electronic signing: the enum advertises a lifecycle the schema forbids, and the failure appears as a runtime PG exception at the first `Pending` row, not at design time.

**Fix shape:** Either drop `Pending`/`Signed`/`Failed` until signing is real, or add the named `signature_status` transitions to the trigger's Step-5 pattern now (with the same "must stamp X in the same UPDATE" atomicity guards the parse-failure resume uses at `:165-178`) so the contract is written down while the table is still small.

---

### [SEVERITY LOW] `VirtualAdminTerminalResolver` locks a row that does not exist yet

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/VirtualAdminTerminalResolver.php:17-56`.

**Problem:** `->lockForUpdate()->first()` at `:22-23` on a *missing* row acquires no lock in PostgreSQL (`SELECT … FOR UPDATE` locks matched rows only). Two concurrent callers both see `null` at `:25`, both reach `Terminal::create()` at `:38`.

**Why it matters:** Mitigated — `2026_05_22_101000_add_virtual_admin_terminal_type.php:16-20` creates `pos_terminals_unique_virtual_admin_per_company`, so the loser gets a `23505` rather than a duplicate admin chain. But nothing catches it: the `QueryException` escapes `resolve()` as an unhandled 500 on whatever server-authored fiscal event triggered the resolve. Listed as LOW only because the DB backstop prevents actual chain duplication.

**Fix shape:** Catch `23505` and re-`first()`, or use an `INSERT … ON CONFLICT DO NOTHING` + re-select (the pattern `OutboxIngestor.php:896` already uses for the sequence slot).

---

### [SEVERITY LOW] `pos_terminals.type` is an unconstrained string while sibling invariants on the same table are DB-pinned

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_02_19_000002_add_type_to_pos_terminals.php:16`.

**Problem:** `$table->string('type', 20)->default('physical')` with no CHECK, though `TerminalType` is a PHP enum and the *same migration* adds a regex CHECK for `code` (`:24`) and a partial unique index keyed on `type = 'web'` (`:27`). An out-of-set `type` silently disappears from `->physical()` (`TerminalController.php:313`), from the `virtual_admin` partial unique index, and from the `claim()` type gate (`TerminalController.php:346`) — which would then reject the claim with `NOT_PHYSICAL_TERMINAL` and no way to diagnose it. Same class as the fiscal CHECK gap above but on a lower-consequence column.

**Fix shape:** Add the `type IN (…)` CHECK in the same migration as the fiscal ones.

---

## CLEAN — genuinely well-governed surfaces

**`fiscal_events` immutability trigger** — `database/migrations/tenant/2026_05_14_100002_create_fiscal_events_immutability.php:53-272`. The best-in-repo example, better than the Workshop precedent for this problem shape: explicit frozen-column whitelist (`:73-111`), named transitions with an `ELSE RAISE` default (`:206-235`), write-once enforcement on `payload` (`:184-199`) *and* on `integrity_exception_class` (`:129-137`, with the docblock spelling out the exact two-step bypass it closes), atomicity requirements binding multiple columns into one UPDATE (`:216-231`), DELETE and statement-level TRUNCATE both blocked (`:59-62`, `:244-247`), plus a `REVOKE TRUNCATE` belt-and-suspenders (`:274-283`) and a documented break-glass runbook. If the receipt trigger is rewritten, this is the template.

**`fiscal_events` sequence-slot claim** — `OutboxIngestor.php:896` uses raw `INSERT … ON CONFLICT (tenant_id, company_id, terminal_id, chain_context, sequence_number) DO NOTHING` against the DB unique index from `2026_05_14_100001_create_fiscal_events_table.php:92-94`, with the follow-up SELECT issued on a still-live transaction (the class docblock at `:55-67` documents why the naive try/catch pattern aborted the PG transaction). Losers route to `fiscal_event_quarantine` as `sequence_conflict`. This is a real DB backstop, not a check-then-set — exactly what defect class (d) asks for.

**`ApplyFiscalEventProjectionJob`** — `.../Application/Jobs/ApplyFiscalEventProjectionJob.php:230-320`. Short `T_lock` transaction with `lockForUpdate()`, terminal-state idempotent short-circuit, stale-running detection, `last_attempted_at` stamped at the Running flip so the in-flight guard survives a cache-lock fail-open, and symmetric status reset on every failure path. The reasoning is documented round-by-round. Its only weakness is that none of it is reusable by the other five writers (see finding 4).

**`IngestionStatus` (DocumentIngestion)** — `.../DocumentIngestion/Domain/Enums/IngestionStatus.php:20-30` carries a real `allowedNext()` adjacency map with both terminal states returning `[]`; `.../Domain/DocumentIngestion.php:68-76` `transitionTo()` is the enforcing write path throwing `InvalidIngestionTransition`. The commit path uses a genuine single-winner atomic claim — a conditional `UPDATE … WHERE status = 'needs_review'` checking `$claimed !== 1` (`DocumentIngestionController.php:175-199`, with a comment explaining why `Committing` is deliberately excluded from the prior-state set under READ COMMITTED) plus a `lockForUpdate()` recovery path (`:305-329`). The `forceFill` bypasses at `:208` and `:325` are deliberate post-claim reconciliation, not drift. This is the enum the fiscal enums should be modelled on. (Its `status` column is an unconstrained `string(20)` at `2026_07_06_200000_create_document_ingestions_table.php:19` — same CHECK gap, non-fiscal surface, folded into finding 3 rather than reported separately.)

**Device SQLite `fiscal_events` mirror** — `apps/pos/src/lib/db/migrations.ts:954-998, 1039, 1426`. `sync_status` has a real `CHECK (sync_status IN ('pending','syncing','synced','failed'))` (`:969`), an append-only trigger that names the only three mutable columns (`:1039`), and a partial index over the non-terminal set (`:997-998`). Crash recovery correctly demotes only `syncing → pending` (`repositories/fiscalEventRepository.ts:135`), `updateFiscalEventSyncStatus` returns `rowsAffected` so callers can assert exactly-one-row (`:151-171`), and `countUnsyncedFiscalEvents` deliberately counts `!= 'synced'` rather than the narrower drain set so a crash-parked row is not miscounted (`:88-102`). Server/device divergence here is bounded by the ingestor's idempotency contract; this is not an instance of the CLAUDE.md rule-20 class.

**`pos_z_reports`** — `(terminal_id, z_number)` unique (`2026_01_08_190644_create_pos_z_reports_table.php:59`), `z_number > 0` CHECK (`:65`), append-only trigger that cross-validates the mirror against the sealing fiscal event's payload (`2026_06_11_120000_create_pos_z_reports_immutability_trigger.php:97-105`), plus the shift-scoped unique from `2026_04_23_000001`. `ZReportSyncController.php:160-195` handles the duplicate-z_number case idempotently by hash comparison (409 `HASH_MISMATCH` on divergence). Note `ZReportHashService::getNextZNumber` (`:192-199`) *is* a read-then-`+1` — but it is backed by the unique index, so it fails closed rather than duplicating; that is the correct shape and is why it is listed here rather than as a finding.

**`pos_shifts`** — the migration the brief cites as proof of the idiom, and it holds up: value CHECK, state-invariant CHECK coupling `status` to `closed_at`/`closed_by`, and the partial unique index enforcing one OPEN shift per terminal (`2026_01_08_190641_create_pos_shifts_table.php:69-87`).

**Legacy terminal chain-head advance** — `ReceiptFinalizationService.php:84-123` takes `Terminal::lockForUpdate()` inside a transaction before reading `last_hash`/`current_sequence` and writing both back; `ReceiptCreationService.php:140-142` and `ReceiptReturnService.php:255-257` both acquire the same lock before touching `current_sequence`. I specifically chased the return path (which reads `current_sequence` at `ReceiptReturnService.php:789` without advancing it) and confirmed it is correct — the advance happens in `finalize()`, which the return path calls at `:512`.

**`fiscal_event_quarantine` / `device_loss_incidents` schema design** — both migrations pin their whitelists at the DB layer where they pinned anything at all (`2026_05_14_100004:112-116`, `2026_05_20_100001:84-88`), use partial indexes over the open/unresolved subset, exclude lifecycle columns from `$fillable`, and document *why* in the migration docblock. The quarantine migration even flags its own future obligation ("once this CHECK widens beyond a single value, Task 8's BEFORE UPDATE trigger pattern becomes mandatory on this table", `:104-107`) — the gaps in finding 3 are omissions against this table's own stated standard, not an absent standard.

---

## POS retail-till sweep

*Source: background agent transcript `agent-afa69e5379ff5a103.jsonl` — final assistant message, extracted verbatim.*

Audit complete. Full report below.

---

# POS Retail-Till Non-Order State — Lifecycle Governance Audit

**Calibration read first:** `apps/api/app/Modules/Workshop/WorkOrder/Domain/Services/StatusMachine.php:28-88` (pure adjacency map, terminal states have no outgoing edges, self-loops forbidden) + `WorkOrderTransitionService.php` (single write path) + `WorkOrderTransitionException` + `database/migrations/tenant/2026_04_19_130004_create_workshop_work_order_status_transitions_table.php`. Schema idiom read: `database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:70-89` (status CHECK + `pos_shifts_closed_logic` state-invariant CHECK + `pos_shifts_one_open_per_terminal` partial unique index).

Grep note: `workshop_work_order_status_transitions` and `scheduling_appointment_status_transitions` are the **only two** transition-audit tables in the entire tenant schema (verified by listing `database/migrations/tenant/`). Nothing in my surface has one.

---

## NEW FINDINGS

### [HIGH] Terminal hardware claim is a check-then-set race with no DB backstop — two devices can bind to one fiscal chain
**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:362` (check) → `:371-373` (write)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:405` (second, unchecked writer)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:501` (reader)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:51`

**Problem:** `claim()` reads the terminal with `firstOrFail()` (`:340-342`), checks `if ($terminal->hardware_identifier !== null)` at `:362`, then `$terminal->update([...])` at `:371`. No `DB::transaction`, no `lockForUpdate` — verified: `grep -n "lockForUpdate" TerminalController.php` returns **zero hits**. The migration declares `$table->string('hardware_identifier', 100)->nullable();` with **no unique index** — the only uniqueness on the table is `pos_terminals_unique_code` on `(tenant_id, company_id, location_id, code)` (`:65`). `requestTerminal()` at `:405` writes `hardware_identifier` with no collision check at all. There is **no release/unclaim path** anywhere: `grep -rn "unclaim\|release" app/Modules/POS/Presentation/` returns only `TableController::releaseTable` — so the claim is a one-way edge with no legitimate reverse, yet it is trivially overwritable.

**Why it matters:** Two tills powered on together (the realistic parapharmacy setup: counter till + back-office till, or a re-imaged device) both call `POST /pos/terminals/claim` against the same `terminal_id`. Both reads see `null`, both pass `:362`, both write. Both devices now believe they own that terminal and author receipts against the same `pos_terminals.current_sequence` and the same NF525 hash chain. Because `findByDevice` (`:501`) does `->where('hardware_identifier', $hw)->first()` on a non-unique column, a device can also silently bind to an arbitrary row among duplicates after a re-request. The receipt-creation path *does* lock the terminal (see CLEAN list), so you don't get a duplicate receipt number — you get two physical devices interleaving into one fiscal chain, which is an NF525 chain-attribution break that no report will surface.

**Fix shape:** Partial unique index `CREATE UNIQUE INDEX pos_terminals_one_device_per_hw ON pos_terminals (company_id, hardware_identifier) WHERE hardware_identifier IS NOT NULL AND deleted_at IS NULL` (mirrors the `pos_shifts_one_open_per_terminal` idiom). Wrap `claim()` in `DB::transaction` + `lockForUpdate`, or make it a conditional `UPDATE ... WHERE id = ? AND hardware_identifier IS NULL` and 409 on zero affected rows. Add an explicit `release()` endpoint behind `pos.manage_terminals` plus a claim/release audit row.

---

### [HIGH] Loyalty reward redemption is an unlocked absolute-value balance write — points double-spend
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Loyalty/Application/Services/RedemptionProcessingService.php:48` (read), `:62` (transaction opens), `:70-72` (eligibility), `:100-102` (write)

**Problem:** `$enrollment = $this->enrollmentRepository->findById($enrollmentId)` at `:48` runs **outside** the `DB::transaction` that starts at `:62`. Inside, the balance is written as an absolute value derived from that stale read: `$enrollment->current_balance = bcsub($enrollment->current_balance, (string) $pointsRequired, 3);` (`:100`). `grep -rn "lockForUpdate" app/Modules/Loyalty` returns **zero hits across the entire module**. There is no idempotency key on redemption and `loyalty_transactions` has no unique index covering `transaction_type = 'redeem'` — the only partial unique index is `loyalty_txn_earn_source_unique ... WHERE transaction_type = 'earn'` (`database/migrations/tenant/2026_07_06_100000_add_source_columns_to_loyalty_transactions.php:96-100`). Separately, `redeemReward` never checks `EnrollmentStatus` — `PointAdjustmentService.php:34` does (`if ($enrollment->status !== EnrollmentStatus::Active) throw`), and `LoyaltyMemberController.php:270` does; this path does not.

**Why it matters:** The earn side was hardened at the DB in July precisely because "two queued earn paths could race the same receipt → double credit" (that migration's own docblock). The redeem side never got the equivalent backstop. A cashier double-tapping "Redeem reward", or a POS retry after a timeout, produces two `Redeem` transactions each computing `balance_after = 100 - 10 = 90` from the same stale read: the member receives two rewards and is debited once. `balance_before`/`balance_after` on the two transaction rows will be identical, so the ledger looks internally consistent and the drift is invisible in the transaction history. An opted-out member can also still redeem.

**Fix shape:** Move the enrollment load inside the transaction with `lockForUpdate`; write the decrement conditionally (`UPDATE ... SET current_balance = current_balance - ? WHERE id = ? AND current_balance >= ?`, assert one affected row); add an `EnrollmentStatus::Active` guard mirroring `PointAdjustmentService.php:34`; add a redemption idempotency key with a partial unique index mirroring `loyalty_txn_earn_source_unique`.

---

### [HIGH] Coupon usage recording has no lock, no transaction and no unique index — single-use coupons are reusable
**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Coupon/Application/Services/CouponApplicationService.php:56-82`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Coupon/Domain/Services/CouponValidationService.php:35-56`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_02_200004_create_coupon_usages_table.php:22-24`

**Problem:** Validation (`validateAndCalculate`, cart time) and mutation (`recordUsage`, post-checkout) are separated by the whole checkout. `recordUsage` (`:56-82`) has no `DB::transaction`, no `lockForUpdate` (`grep -rn "lockForUpdate" app/Modules/Coupon` → **zero hits**), and `coupon_usages` carries only plain indexes on `['coupon_id','used_at']`, `['receipt_id']`, `['partner_id','coupon_id']` — **no unique constraint** on `(coupon_id, receipt_id)` or `(coupon_id, partner_id)`. `max_uses` is enforced *only* by the post-hoc auto-exhaust at `:74-77`; `validateAndCalculate` checks status, dates, per-customer count and minimum order — it **never compares `use_count` to `max_uses`** (`:35-56`). So the global usage cap has no synchronous enforcement point at all.

**Why it matters:** Two concurrent checkouts on a `max_uses = 1` promo coupon both validate against status `Active`, both call `recordUsage`, both insert a usage row and both `increment('use_count')` — the coupon is honoured twice and the auto-exhaust fires only after both discounts are already on sealed receipts. A POS sync retry of the same receipt produces a duplicate usage row for the same `receipt_id`, permanently inflating `use_count` against the customer. For a launch parapharmacy running a "10 DT off, first 100 customers" opening promo, the cap is advisory.

**Fix shape:** `unique(['coupon_id','receipt_id'])` on `coupon_usages` (and the same on `promotion_usages`, which has the identical index-only shape at `2026_03_02_200001_create_promotion_usages_table.php:22-24`); wrap `recordUsage` in a transaction with `lockForUpdate` on the coupon; add the `use_count >= max_uses` check to `validateAndCalculate` so the cap is enforced where the discount is granted, not after.

---

### [HIGH] Manual voucher void writes no GL reversal, while every sibling voucher event does
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:325-360` (specifically `'gl_journal_entry_id' => null` at `:346`)

**Problem:** Three code paths write `VoucherStatus::Voided`. Two of them create a GL entry: `VoucherLookupService.php:272` (`$glEntry = $this->generalLedger->createVoucherLedgerEntry(...)`, auto-fraud void) and `VoucherRedemptionService.php:200` (redemption). The back-office manual void at `VoucherController::void` creates none — it inserts a `VoucherEvent::Voided` ledger row with `'gl_journal_entry_id' => null` (`:346`) and zeroes `current_balance` (`:358`), with no `GeneralLedgerService` call anywhere in the method.

**Why it matters:** A voucher is a real liability (Cr VoucherLiability at issuance — `VoucherRedemptionService` docblock §5.2). Voiding it zeroes the customer-facing balance but leaves the liability sitting on the balance sheet forever. Every back-office void (expired card, customer dispute, mis-issued goodwill) silently overstates liabilities. The discrepancy is not detectable from the voucher table — you have to reconcile `voucher_ledger.gl_journal_entry_id IS NULL` against GL to find it.

**Fix shape:** Route the manual void through the same `createVoucherLedgerEntry` call the auto-fraud void uses, or better: extract a single `VoucherVoidService` that both the controller and `VoucherLookupService` call, so there is one write path with one GL contract.

---

### [MEDIUM] Voucher void is an unlocked check-then-set on a money surface — a double-click writes two Voided ledger rows
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:290-292` (unlocked read), `:299` (terminal check), `:309-311` (redemption check), `:325` (transaction *starts here*), `:327` (stale balance read)

**Problem:** The voucher is loaded with `->find($id)` — no `lockForUpdate` (`grep -n "lockForUpdate" VoucherController.php` → **zero hits**). The terminal-state check at `:299` and the has-redemptions check at `:309` both run **before** `DB::transaction` opens at `:325`. Inside the transaction, `$voidedBalance = $voucher->current_balance;` (`:327`) reads the **stale in-memory model**, not a re-read. Contrast `VoucherRedemptionService.php:87-89`, which does `->where('code', $code)->lockForUpdate()->first()` inside its transaction and re-checks status at `:105` under the lock.

**Why it matters:** Two concurrent voids (a double-clicked Void button — there is no idempotency key on this endpoint) both pass `:299`, both write a `Voided` ledger row for the full balance. `SUM(voucher_ledger.amount)` then no longer reconciles to `vouchers.current_balance`, which is the only integrity check that exists for the append-only ledger. Worse cross-path: a POS redemption holds the row lock and commits `FullyRedeemed` + a GL debit; the concurrent void, which already passed its `hasRedemptions` check, then overwrites `status = Voided` and `current_balance = 0` from its stale snapshot — the redeemed voucher reads as voided, and the ledger double-counts the reversal.

**Fix shape:** Move the load inside the transaction with `lockForUpdate` and re-check status + redemptions under the lock, exactly as `VoucherRedemptionService::redeem` does. Add a client-supplied idempotency key on the void endpoint.

---

### [MEDIUM] `vouchers.status` is an unconstrained string with no adjacency map — while its own sibling table has a value CHECK
**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_02_000001_create_vouchers_table.php:32` — `$table->string('status', 32);` and **no `pgsql` block at all** in the migration
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_03_000003_add_event_check_constraint_to_voucher_ledger.php:20-35` — the sibling `voucher_ledger.event` **does** get `voucher_ledger_event_check`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Voucher/Domain/Enums/VoucherStatus.php:7-14`

**Problem:** This is defect class (c) in its purest form: the *same feature*, shipped one day apart, added a CHECK to the ledger's `event` column and left the aggregate's `status` column unconstrained. `VoucherStatus` is 5 bare cases — no `canTransitionTo`, no `isTerminal`, no `label()`, nothing. Consequently "which states are terminal" is duplicated across three files with **three different answers**: `VoucherController.php:299` says `[Voided, FullyRedeemed]`; `VoucherRedemptionService.php:105` says redeemable is `[Issued, PartiallyRedeemed]`; `VoucherLookupService.php:99` and `:107-108` split `Voided` from `Expired|FullyRedeemed`. `Expired` is terminal in the lookup service but voidable per the controller.

**Why it matters:** With no DB CHECK and no adjacency map, any future write path (a back-office bulk action, a data fix, a queued job) can set an arbitrary string and every one of the three hand-rolled guard lists will fall through its `in_array`/`===` comparisons to the permissive branch. The three lists are already inconsistent, which is the leading indicator that they will drift further.

**Fix shape:** Add `vouchers_status_check` mirroring `voucher_ledger_event_check`. Give `VoucherStatus` `allowedTargetsOf()`/`isAllowed()` in the `StatusMachine` shape and make all three guard sites consult it.

---

### [MEDIUM] `VoucherStatus::Expired` and `VoucherEvent::Expired` are unreachable — no expiry job exists
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Voucher/Domain/Enums/VoucherStatus.php:11`, `VoucherEvent.php` (`case Expired = 'expired'`), reads only at `VoucherLookupService.php:107`

**Problem:** `grep -rn "VoucherStatus::Expired\|VoucherEvent::Expired" app/` finds **only reads** — `VoucherLookupService.php:107` and `:162`. Nothing writes either. There is no scheduled command (`grep -rn "voucher" routes/console.php app/Console` → nothing). The `expiry_extended` event was even added to the ledger CHECK (`2026_05_08_000001_add_expiry_extended_to_voucher_ledger_event_check.php`) and there is an `ExtendExpiryRequest` — so expiry is a first-class concept with no engine behind it.

**Why it matters:** A voucher past `expires_at` stays `status = 'issued'` forever. Redemption is safe (`VoucherRedemptionService.php:109-111` checks `expires_at->isPast()` at runtime), so this is not a leakage of value — but the liability is never released to breakage income, the back-office "Vouchers & Credits" list (indexed on `['tenant_id','source','status']`) shows dead vouchers as live, and the `expired` GL treatment has no trigger. For a store issuing refund vouchers as its correction rail, the liability account grows monotonically.

**Fix shape:** A scheduled per-tenant command that flips `Issued|PartiallyRedeemed` + `expires_at < now()` → `Expired`, writing a `VoucherEvent::Expired` ledger row with the GL breakage entry, using the same `lockForUpdate` discipline as redemption.

---

### [MEDIUM] The `pos_receipts` immutability trigger has no branch for 4 of its 6 legal `fiscal_status` values — a voided receipt is fully mutable and can be un-voided
**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:59-152` (current `prevent_receipt_modification()` body; the fall-through is at `:142-152` → `END IF; RETURN NEW;`)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:54-55` (the CHECK that admits all six values)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Enums/FiscalStatus.php:7-14`

**Problem:** The CHECK constraint admits `'pending_seal','fiscalized','voided','pending_sync','synced','sync_failed'` — two orthogonal lifecycles (fiscal seal state and device sync state) conflated into one column with no adjacency map. The trigger only ever branches on `OLD.fiscal_status = 'pending_seal'` and `OLD.fiscal_status = 'fiscalized'`. Every branch and both `RAISE EXCEPTION` guards (`:142-152`) are gated on those two values. For `OLD.fiscal_status IN ('voided','pending_sync','synced','sync_failed')` control reaches `END IF; RETURN NEW;` — **the UPDATE is permitted unconditionally**, including `fiscal_hash`, `receipt_number`, `total`, `chain_sequence`, and `fiscal_status` itself.

**Why it matters:** This is a terminal state with a reachable outgoing edge, enforced nowhere: `UPDATE pos_receipts SET fiscal_status='fiscalized', total=… WHERE fiscal_status='voided'` un-voids a receipt and rewrites its totals, with the NF525 trigger standing by. Exposure caveat, stated honestly: tenant #1 is v3-from-birth and the server void path was retired (`ReceiptController.php:254-260`, DPA V9 ruling D3 — `POST /pos/receipts/{id}/void` is now a 410 tombstone), and `grep` confirms no server code writes any of those four values (`ReceiptCreationService.php:584`, `ReceiptFinalizationService.php:117`, `ReceiptReturnService.php:821`, `PosCoreReceiptProjection.php:398` write only `pending_seal`/`fiscalized`). So for a fresh tenant no row can currently reach the hole. It bites on any tenant carrying legacy-era voided rows, and it means the immutability defense is one careless `fiscal_status` write away from being bypassed everywhere.

**Fix shape:** Invert the trigger's default — end the `TG_OP = 'UPDATE'` block with an unconditional `RAISE EXCEPTION` and whitelist explicitly, instead of falling through to `RETURN NEW`. Separately, split the sync states out of `fiscal_status` into their own column (they are device-sync state, not fiscal state) and narrow the CHECK to the three real fiscal values.

---

### [MEDIUM] Held-order recall: unlocked check-then-set, not terminal-scoped, unconstrained status column
**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:139-162` (`recallOrder`), `:167-181` (`discardOrder`)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/HeldOrder.php:157-170` (`canBeRecalled`)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_11_500000_create_pos_held_orders_table.php:26` (`$table->string('status', 20)->default('held');` — no `pgsql` block in the migration at all)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Enums/HeldOrderStatus.php` (3 cases, only `label()`)

**Problem:** Four write sites for `status` (`HeldOrderService.php:70`, `:158`, `:230`, plus the `pos_held_orders.status` default). `recallOrder` does `findOrFail` (`:145-148`, no `lockForUpdate` — `grep -n lockForUpdate HeldOrderService.php` → **zero hits**), calls `canBeRecalled()` at `:150`, then `update(['status' => Recalled])` at `:157` — no transaction wrapping the two. The scope filter is `tenant_id` + `company_id` **only** (`:145-147`) — not `terminal_id`, even though `listHeldOrders` is terminal-scoped (`:205`) and the table is indexed `['terminal_id','status']`. `HeldOrderStatus` has no adjacency map, and there is no CHECK on the column (contrast `pos_shifts_status`). `discardOrder` (`:167-181`) hard-`delete()`s a row in any status with no guard and no audit.

**Why it matters:** Two cashiers on two tills recall the same parked basket at the same moment: both pass `canBeRecalled()`, both get the full `cart_snapshot`, both ring it up → the customer's parked basket is sold twice, and stock is decremented twice. Because recall isn't terminal-scoped, till B can steal till A's parked basket with a guessed/leaked id even without a race. And `discardOrder` can permanently delete a `Recalled` order, destroying the only trace of what was parked.

**Fix shape:** Wrap `recallOrder` in a transaction with `lockForUpdate` (or a conditional `UPDATE ... WHERE status = 'held'` asserting one affected row); scope the lookup by `terminal_id`; add a status CHECK to `pos_held_orders`; give `HeldOrderStatus` an adjacency map (`Held → {Recalled, Expired}`, both terminal); soft-delete instead of hard-delete in `discardOrder`.

---

### [MEDIUM] No transition audit on any state change in this surface — the two tables that exist are both outside it
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/` — only `2026_04_19_130004_create_workshop_work_order_status_transitions_table.php` and `2026_04_19_140005_create_scheduling_appointment_status_transitions_table.php` exist.

**Problem:** (Exclusion 5 covers POS order / line / shift — these are different surfaces.) Terminal claim (`TerminalController.php:371`) writes no audit row of any kind — no event, no log, nothing (contrast `activate`/`deactivate` at `:243-256` and `:292-299`, which *do* dispatch `TerminalActivatedAudit` / `TerminalDeactivated` for NF525). Held-order recall/expire (`HeldOrderService.php:157`, `:230`) writes nothing. Coupon revoke/reactivate (`CouponManagementService.php:21`, `:34`) writes nothing — no actor, no timestamp, no reason. Voucher status changes get a `voucher_ledger` row, but that row records the *event and amount*, never the from→to status pair, so a status that was set incorrectly cannot be traced.

**Why it matters:** When the parapharmacy owner asks "who voided this 200 DT voucher / who reactivated this exhausted coupon / which device took over this till", there is no answer for three of the four. For a fiscal deployment these are precisely the discretionary money-adjacent actions an auditor asks about.

**Fix shape:** The `workshop_work_order_status_transitions` shape (append-only: entity id, from, to, actor, reason, occurred_at) generalised into a shared transitions table, written by the single-write-path services proposed above. `CustomerAccountStatusService` (see CLEAN) shows the lighter column-level variant if a table is too heavy.

---

### [LOW] Coupon revoke is not terminal, and `CouponStatus::Expired` is unreachable
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Coupon/Application/Services/CouponManagementService.php:15-37`; `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Coupon/Domain/Services/CouponValidationService.php:39`

**Problem:** `reactivate()` rejects only `status === Active` (`:31`) — so `Revoked → Active` and `Exhausted → Active` are both permitted, with no `use_count` reset and no actor/reason recorded. `Exhausted → Active` is self-correcting (the next `recordUsage` re-exhausts it), and `Expired → Active` is caught downstream by `$coupon->isValid()` (`CouponValidationService.php:46`) — so the blast radius is small. Separately, `CouponStatus::Expired` is **never written** by any code (`grep -rn "CouponStatus::Expired" app/` → only the read at `CouponValidationService.php:39`), so that enum case and its guard branch are dead. `coupons.status` is `$table->string('status')` with no length and no CHECK (`2026_03_02_200003_create_coupons_table.php:20`); same for `promotions.status`.

**Fix shape:** Give `CouponStatus` an adjacency map with `Revoked` terminal; require a reason on both edges; add a status CHECK; either wire an expiry job or delete the `Expired` case.

---

### [LOW] `TableStatus` is settable to any value from the API with no adjacency check
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/TableController.php:156` (`$status = TableStatus::from($request->validated('status'));`); `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_13_100001_create_pos_tables_table.php:21`

**Problem:** Five write sites (`OrderManagementService.php:179`, `:454`, `:518`; `TableManagementService.php:108`, `:188`, `:215`) plus a free-form API setter that accepts any enum value with no from-state check. `TableStatus` has only `label()`. `pos_tables.status` is `string(20)` with no CHECK. `Occupied → Available` while an order is open is unguarded.

**Why it low:** Restaurant/table-service surface; the parapharmacy till does not use it. Listed for completeness since it was in scope.

**Fix shape:** Adjacency map + route the controller setter through `TableManagementService` + status CHECK.

---

### [LOW] Device `offline_receipts.voided` has no production writer, and the offline Z report hardcodes `voided_count = 0` behind a stale comment
**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/zReportService.ts:834` (`const voidedCount = 0;`) and `:840-841` (comment: *"offline receipts don't have a voided flag"*); column added at `/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/migrations.ts:292-296`

**Problem:** Migration 15 added `voided INTEGER NOT NULL DEFAULT 0` to `offline_receipts`, and `apps/pos/src/api/reportApi.ts:786` reads it (`is_voided: receipt.voided === 1`), but the Z-report authoring path still hardcodes `voidedCount = 0` at `:834` with a comment asserting the column doesn't exist. Independently, `grep -rn "voided\s*=\s*1\|SET voided"` across `apps/pos/src` finds writers **only in test files** (`productSalesAggregateRepository.test.ts:138`, `reportApi.shiftReceipts.test.ts:239`) — no production code ever sets `voided = 1`. The `WHERE ... voided = 0` filters in `salesHistory.ts:119`, `endOfDayPreview.ts:183`, `offlineReceiptRepository.ts:367` and `productSalesAggregateRepository.ts:43` are therefore currently no-ops.

**Why it matters:** This is defect class (f): the void concept exists in the device schema, in the wire DTO, in four query filters and in the Z-report shape, but has no writer on either side of the boundary (the server void was retired too). Today that is consistent-by-accident, so nothing is wrong on the till. The risk is the stale comment at `:840-841` — it tells the next author the column doesn't exist, so the first person to wire a device-side void will leave `voided_count` structurally zero on every Z report while the filters silently start excluding rows, producing exactly the class of Z/reports divergence CLAUDE.md rule 20 documents.

**Fix shape:** Delete the stale comment and either compute `voidedCount` from the column or assert the column is unwritten; when a void path is reintroduced, wire both ends in the same change.

---

## CLEAN — genuinely well-governed

1. **`CustomerAccountStatus` + `CustomerAccountStatusService`** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Partner/Domain/Enums/CustomerAccountStatus.php:14-28` is the only real adjacency map in this surface: self-loops forbidden, `Closed` genuinely terminal (`=> false`). `CustomerAccountStatusService.php:47-87` is a textbook single write path — `lockForUpdate` (`:52`), `canTransitionTo` guard (`:64`), monotonic `account_status_version` (`:73`), `changed_at`/`changed_by`/`reason` audit columns (`:74-76`), and a fiscal event appended (`:79-85`). The DB backs it with `partners_account_status_check` (`2026_05_22_102000_add_account_status_to_partners_table.php:26-27`). This is the pattern the voucher and held-order surfaces should copy.

2. **`VoucherRedemptionService::redeem`** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Voucher/Application/Services/VoucherRedemptionService.php:82-296`. `FOR UPDATE` lock inside the transaction (`:87-89`), status re-checked under the lock (`:105`), expiry/terminal/currency/customer-bind guards in spec order (`:109-138`), a duplicate-in-transaction guard (`:141-150`), append-only ledger with the GL entry created *before* the INSERT so `gl_journal_entry_id` is never null (`:196-217`), typed exceptions throughout. The redemption race the brief asked about **does not exist** on this path.

3. **`ExchangeService` idempotency** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ExchangeService.php:139-190`. `lockForUpdate` on the idempotency row, three explicit status branches (Completed → replay, Pending → `ExchangeInProgressException`, Failed → reset with a fresh group id), backed by a real DB constraint `unique(['company_id','exchange_request_id'])` (`2026_05_06_000001_create_pos_exchange_requests_table.php:46`). Failure tracking (`:363-390`) re-locks and re-checks `Pending` before writing `Failed` — a proper idempotent guard in a separate transaction so the record survives the rollback.

4. **Receipt numbering and chain-sequence allocation** — `ReceiptCreationService.php:137-142` (`DB::transaction` + `Terminal::…->lockForUpdate()`), `:499`/`:643` (read and advance under that lock), `ReceiptFinalizationService.php:84-86` (same), `ReceiptReturnService.php:198`/`:232`/`:257` (original receipt locked, idempotency re-checked *under* the lock at `:241-247`, then terminal locked). Backed by `pos_receipts_location_receipt_number_unique` (`2026_03_24_100000_scope_receipt_number_unique_to_tenant.php:27-30`). No `receipt_number` race.

5. **Receipt fiscal seal transition** — `ReceiptFinalizationService.php:73-117` is a correct 2-state machine: rejects already-`Fiscalized` (`:73`), rejects anything not `PendingSeal` (`:77`), then writes `Fiscalized` (`:117`), with `pos_receipts_fiscal_status_check` at the DB and the trigger enforcing `pending_seal → {pending_seal, fiscalized}` only (`2026_07_31_940000…:148-151`). The *within-the-two-known-states* machine is sound; the finding above is about the four states outside it.

6. **Loyalty earn idempotency** — `2026_07_06_100000_add_source_columns_to_loyalty_transactions.php:96-100` adds `loyalty_txn_earn_source_unique` as a partial unique index precisely to convert a TOCTOU check-then-insert into a DB-enforced invariant, with a documented duplicate-backfill strategy. Exactly the right shape — which is what makes its absence on the redeem side a finding rather than an oversight.

7. **POS account-charge and deposit projections** — `2026_05_21_150000_create_pos_account_charge_receipts_table.php:17` and `2026_06_09_130000_create_pos_deposit_receipts_table.php:17` both put `->unique()` on `fiscal_event_id`, so replayed fiscal events cannot double-project a charge or a deposit. No draft-state machine to govern; the idempotency backstop is at the DB where it belongs.

8. **`voucher_ledger`** — append-only by construction (only `forceCreate`, no update sites found), with `voucher_ledger_event_check` enforcing the enum at the DB (`2026_05_03_000003…:20-35`) and extended additively rather than mutated (`2026_05_08_000001_add_expiry_extended_to_voucher_ledger_event_check.php`). Correct handling of an immutable ledger.

9. **`pos_shifts` CHECK constraints** (spot-checked for gaps per the brief, not re-praised): `pos_shifts_status`, `pos_shifts_closed_logic` and `pos_shifts_one_open_per_terminal` are internally complete — `closed_logic` ties `status` to both `closed_at` and `closed_by` in both directions, so there is no half-closed state. The one gap worth noting is that `pos_shifts_variance_calc` permits `variance IS NOT NULL AND actual_cash IS NULL` only via the second disjunct's arithmetic (which would be NULL, hence not false, hence passing) — a `CHECK` on a NULL operand evaluates to unknown and is accepted. That is a latent hole rather than an exploitable one and no code writes that combination, so I am not raising it as a finding.

---

## Import + opening-batches sweep

*Source: background agent transcript `agent-adfee5d57e15878c7.jsonl` — final assistant message, extracted verbatim.*

Sweep complete. Report below.

---

# IMPORT + OPENING BALANCES + ACCOUNTING PERIODS — state-machine sweep

Baseline read first: `apps/api/app/Modules/Workshop/WorkOrder/Domain/Services/StatusMachine.php:20-88` (pure adjacency map, terminal states with empty target lists, self-loops forbidden), its single write path `.../Application/Services/WorkOrderTransitionService.php`, the typed `.../Domain/Exceptions/WorkOrderTransitionException.php`, the append-only table `database/migrations/tenant/2026_04_19_130004_create_workshop_work_order_status_transitions_table.php`, and — notably — a PHPStan rule that *enforces* the single write path: `apps/api/app/PHPStan/Rules/WorkOrderStatusWriteOnlyViaTransitionService.php`. Nothing on my surface has any of the four.

---

## [HIGH] Opening-balance post is a check-then-set on a stale in-memory model, outside the transaction, with no row lock and no DB idempotency backstop — a double-click double-posts the entire opening batch

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:239` (guard) vs `:258` (transaction opens)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:256` vs `:275`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:198` vs `:256`
- Stale re-check inside the transaction: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:325` → `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/OpeningBalanceBatch.php:168-171` (`canPost()` reads the in-memory enum attribute)
- Entry point: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:553-588` (`post()`); `$batch` is loaded once at `:557` and never re-read or locked.

**Problem:** All three `postBatch()` implementations read `$batch->canPost()` (status === DRAFT) *before* `DB::transaction()` and never `lockForUpdate()` the batch row. The re-check inside the transaction (`markBatchValidated`, `OpeningBalanceBatchService.php:325`) evaluates the **same stale in-memory model**, not a fresh read, so it cannot catch a concurrent winner. There is no unique index backstop: `grep` over `database/migrations/tenant/` shows `unique_journal_entries_source_*` partial-unique indexes for **procurement** (`2026_06_26_120000`), **treasury_transfer** (`2026_07_12_100000`), **repository_adjustment** (`2026_08_08_120100`) and **inventory_movement** (`2026_08_11_000100`) — and **none for `source_type = 'opening_balance'`**.

**Why it matters:** Two overlapping `POST .../opening-batches/{id}/post` calls (a double-click, or a client retry on a slow first response) both pass the DRAFT check. If the first commits before the second's transaction reads the numbering max, the second allocates the *next* free number and writes a **complete second opening journal entry** (GL: a second `OB-YYYY-nnnnnn` doubling opening equity) or a **complete duplicate set of `HIST-INV-*` open items** (AR/AP — the parapharmacy's opening customer/supplier balances, doubled). The second run then re-locks: `OpeningBalanceBatchService.php:382-388` rewrites `status`, `locked_at`, `locked_by`, `hash` *and* `previous_hash` unconditionally, so the batch's SHA-256 chain link is silently re-pointed and the batch still reports `LOCKED` — no trace of the duplicate. Inventory escapes only by accident, via the unrelated per-product guard at `OpeningBalancePostingService.php:88-101`.

**Fix shape:** Re-read the batch with `lockForUpdate()` as the *first statement inside* the transaction and evaluate the guard there; add a partial unique index `CREATE UNIQUE INDEX ... ON journal_entries (source_type, source_id) WHERE source_type = 'opening_balance'` (copy `2026_08_11_000100_unique_journal_entries_source_inventory_movement.php` verbatim, including its pre-flight duplicate scan) plus the AR/AP equivalent keyed on the batch id, so the DB refuses the second post even if the app layer races.

---

## [HIGH] A LOCKED (immutable, hash-sealed) opening batch can be re-validated, silently rewriting POSTED row statuses and `mapped_data`

**Where:**
- Route/controller with no status guard: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:461-500`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:90-117` (no `isLocked()`/`isEditable()` check; row selection at `:97` is `where('status','!=',Skipped)` — which **includes `POSTED`**)
- Same shape: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:61,67` and `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:56,62`
- The unguarded writer: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:482-501`

**Problem:** `OpeningImportRowStatus` write sites, counted: **5** — `OpeningBalanceBatchService.php:174` (insert), `:282` (`updateImportRow`), `:298` (`skipImportRow`), `:493` (`applyValidationResults`), `:514` (`markRowsPosted`). Three of the five are guarded (`:274` checks `$row->status->isEditable()`, `:294` checks `isPosted()`); the two that matter — `:493` and `:514` — are **completely unguarded**, writing `status` with no source-state check. `applyValidationResults` will happily overwrite `POSTED` → `VALID`/`INVALID` and replace `mapped_data`. `OpeningImportRowStatus::isEditable()` (`/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Enums/OpeningImportRowStatus.php:29-32`) correctly excludes `Posted` — the enum knows the rule; the write path just doesn't consult it.

**Why it matters:** `POST /companies/{c}/opening-batches/{b}/validate` on an already-locked batch is accepted with a 200. Every posted row flips back to `VALID`, `mapped_data` is recomputed, and the batch's stats (`getBatchStats`, `OpeningBalanceBatchService.php:396-408`) report `posted: 0` for a batch that did post. Because `calculateBatchHash` (`:445-459`) folds `mapped_data` into the SHA-256 seal, any later hash verification of the LOCKED batch fails against a seal that was computed over pre-rewrite data. The whole point of the LOCKED terminal state — an auditable, immutable record of what was loaded at cutover — is defeated by an endpoint the UI exposes one button away.

**Fix shape:** Guard `validateBatch()` in all three services with `if (! $batch->isEditable()) throw ...` (the model already exposes it, `OpeningBalanceBatch.php:155-163`), exclude `Posted` from the row selection alongside `Skipped`, and add the missing source-state check to `applyValidationResults`/`markRowsPosted` so the row enum's own `isEditable()` is honoured at the write path, not just at `updateImportRow`.

---

## [MEDIUM] Per-product opening reset silently invalidates a LOCKED inventory batch — state duplicated across two layers with no link between them

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:61-80` (guards: `hasActiveOpening` + `hasDownstreamMovements` only)

**Problem:** The reset guard consults **stock movements**, never `opening_balance_batches`. Nothing in the file references `OpeningBatchStatus` (`grep -rn "OpeningBatchStatus::" app/` returns zero hits in `Inventory/`). So a product whose opening quantity was posted as part of a LOCKED `INVENTORY` batch can be individually reset; once reset, the "active opening exists" guard at `OpeningBalancePostingService.php:88-101` no longer trips, and the product can be re-opened via `ProductController.php:534-553` with a different quantity and cost.

**Why it matters:** The batch row still reads `LOCKED` with a sealed `hash`/`previous_hash`, but the stock and GL it claims to describe have been reversed and re-entered at different values. Two systems of record for "what the opening was" that can diverge with no detection, on the exact surface (parapharmacy opening stock) that the go-live depends on.

**Fix shape:** Have `reset()` refuse when the product's opening movement traces to a batch in `LOCKED` status (or, if per-product correction is intentional, demote the batch out of `LOCKED` through an explicit, audited `Locked → Corrected` edge rather than leaving the seal standing).

---

## [MEDIUM] Present-dated opening-balance reversal posts straight to `Posted`, bypassing both the closed-period guard and the hash chain

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:157-168` — `'entry_date' => now()->toDateString()`, `'status' => JournalEntryStatus::Posted`, `'is_historical' => true`

**Problem:** The house chokepoint for posting is `GeneralLedgerService::postEntryNow`, which takes a per-company advisory lock (`/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3532`) and then rejects entries dated into a closed period (`:3541`, throwing `ClosedFiscalPeriodException`). `ResetOpeningBalanceService` calls `JournalEntry::create()` directly with `status => Posted`, so neither runs. The `is_historical => true` flag is doing the excusing, but the entry is dated **today**, not at cutover — it is a present-period correction wearing a historical label.

**Why it matters:** `FiscalPeriodAutoLockService.php:96-98` bulk-closes past open periods and `:134-135` bulk-locks them; a reset run after a month-end close writes a GL entry into a CLOSED or LOCKED period with no refusal, and the entry carries no `chain_sequence`/`fiscal_hash`, so it is invisible to chain verification. The same direct-create bypass exists at `AccountingOpeningService.php:262-274` and `OpeningBalancePostingService.php:232` — defensible there (cutover-dated), not defensible for a `now()`-dated reversal.

**Fix shape:** Route the reversal through `postEntryNow` (it is a present-period, chainable act), or at minimum call `FiscalPeriodResolverService::isDateInClosedPeriod()` before creating it — the guard is already published behind `FiscalPeriodLockReaderInterface` (`/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Contracts/Accounting/FiscalPeriodLockReaderInterface.php`) and currently has exactly **one** consumer outside Accounting (`VatPeriodBackdatingGuard.php:57`).

---

## [MEDIUM] `ImportStatus` write-after-dispatch clobbers a running worker's `Importing` back to `Pending`, and `Pending` is an accepted start state

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:536` (dispatch) then `:539` (`$job->update(['status' => ImportStatus::Pending])`) — same ordering bug at `:753`/`:756` for the product-images path
- Worker sets `Importing`: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:116-119`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Import/Domain/Enums/ImportStatus.php:16-20` — `canStartImport()` returns true for **`Pending`** as well as `Validated`, with a comment acknowledging it
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Import/Services/ImportService.php:276-282` — `getValidRows()` filters `is_valid = true` only, **never `is_imported = false`**

**Problem:** The status write happens *after* `ProcessImportJob::dispatch()`. On a warm `imports` queue the worker can claim the job and set `Importing` before line 539 executes; line 539 then unconditionally writes `pending` over it. The job now advertises a start-eligible status while a worker is mid-run, and `POST /imports/{id}/execute` will accept it again (`ImportController.php:490` → `canStart()`), dispatching a second worker over the same row set. There is no DB backstop — no claim column, no partial unique index — compare `pos_shifts` migration line 68 ("Partial unique index: only one OPEN shift per terminal"). `ImportStatus` write sites, counted: **15** `'status' => ImportStatus::` assignments across 5 files (`ImportController` ×4, `ProcessImportJob` ×4, `ProcessProductImageImport` ×3, `ImportService` ×4 at `:61,:137,:174,:328` plus `:372`), with zero adjacency validation anywhere.

**Why it matters — stated honestly:** the *effect* layer is largely idempotent and limits the blast radius. Row writers are upserts (`ImportService.php:518` `productService->upsert`, `:489` `partnerService->upsertWithTypeMerge`, `:584` `compositeItemService->upsert`); `ProductOpeningStockPhase` skips `is_imported = true` rows (`:39`) and has an `opening_exists` guard (`:112`); `AccountingBalancesPhase.php:151` explicitly refuses when the batch is already `LOCKED`, with a docblock that spells out the re-run reasoning. So this is **not** a "double opening stock" path today. What it does produce: two workers racing on the same rows, `processed_rows`/`successful_rows`/`failed_rows` counters written concurrently by both (`ProcessImportJob.php:152,:170`) so the final tally and the completion broadcast are wrong, and duplicated `productPlacementImportService->commitRow` effects (`ImportService.php:524`). It also stands one refactor of any writer away from being a genuine double-apply.

**Fix shape:** Write the status *before* dispatching (or `DB::afterCommit(fn () => ProcessImportJob::dispatch(...))`), and make the worker **claim** the job with a conditional update — `UPDATE import_jobs SET status='importing' WHERE id=? AND status IN ('pending','validated')`, treating an affected-row count of 0 as "already claimed, return". Add `->where('is_imported', false)` to `getValidRows()` so any re-run is inherently resume-shaped rather than replay-shaped.

---

## [MEDIUM] Every status column on this surface is an unconstrained string; the sibling table `pos_shifts` proves the CHECK + state-invariant + partial-unique idiom is house style

**Where (all absolute, all read):**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_150000_create_import_tables.php:18` — `$table->string('status', 50)->default('pending')`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_12_11_100000_create_opening_balance_tables.php:21` — `$table->string('status', 20)->default('DRAFT')`; and `:51` — `$table->string('status', 20)->default('PENDING')` for `opening_balance_import_rows`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:20` — `$table->string('status')->default('draft')` (no length at all)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_03_23_200000_create_vat_periods_table.php:38` — `$table->string('status', 20)->default('OPEN')`
- Precedent: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:68` (partial unique index), `:81` (`pos_shifts_status CHECK`), `:84` (`pos_shifts_closed_logic CHECK` — a *state-invariant* check tying columns to status)

**Problem:** No value CHECK on any of them, and — more consequentially — no state-invariant CHECK. Nothing at DB level prevents `status='LOCKED' AND locked_at IS NULL`, or `status='LOCKED' AND hash IS NULL`, which is exactly the shape the race in finding 1 and the re-validate hole in finding 2 leave behind. Separately, the "only one unlocked batch per (company, type)" invariant is enforced **only in PHP**, as a check-then-insert with no lock: `OpeningBalanceBatchService.php:62-72`. Two concurrent `POST .../opening-batches` both pass and create two DRAFT `ACCOUNTING` batches; from then on the import path's `hasUnlockedBatch` conflict (`AccountingBalancesPhase.php:190-202`) fires permanently until one is manually deleted. Note the import-originated path *is* covered — `database/migrations/tenant/2026_07_03_200001_add_unique_import_reference_to_opening_balance_batches.php:16` creates a partial unique on `(import_file_reference->>'import_job_id', type)` — so the gap is specifically the manual API path.

**Fix shape:** Add value CHECKs from the enum cases on all five columns; add `opening_balance_batches` state invariants (`status='LOCKED'` ⇒ `locked_at IS NOT NULL AND hash IS NOT NULL`; `status IN ('VALIDATED','LOCKED')` ⇒ `validated_at IS NOT NULL`); add `CREATE UNIQUE INDEX ... ON opening_balance_batches (company_id, type) WHERE status <> 'LOCKED'` to make the "one unlocked batch" rule a DB fact rather than a PHP hope.

---

## [MEDIUM] No adjacency map, no transition service, and no transition audit on any money-surface enum here — while the codebase already ships all three for Workshop and Scheduling

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Enums/OpeningBatchStatus.php:7-61` — five predicate helpers (`isEditable`, `isDeletable`, `canPost`, `canLock`, `isImmutable`), **no `canTransitionTo`, no `allowedTargetsOf`, no `isTerminal`**. `canPost()` returning `Draft` while `markBatchValidated` uses it as its guard is actively confusing naming.
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Domain/Enums/PeriodStatus.php:10-15` — three bare cases, **zero methods**. Writes are bulk query-builder updates that bypass model events entirely: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php:96-98` (`where('status', Open)->update(['status' => Closed])`) and `:134-135` (`whereIn('status',[Open,Closed])->update(['status' => Locked])`). No per-row validation, no audit row, no actor recorded.
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Enums/JournalEntryStatus.php:7-12` — three bare cases; **55** `'status' => JournalEntryStatus::…` assignment sites across **17** files.
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Import/Domain/Enums/ImportStatus.php` — has `isTerminal()` (`:22`) but nothing consults it; `grep -rn "isTerminal()" app/Modules/Import/` returns no caller.
- Audit tables: `ls database/migrations/tenant/ | grep -i transition` yields exactly **two** — `2026_04_19_130004_create_workshop_work_order_status_transitions_table.php` and `2026_04_19_140005_create_scheduling_appointment_status_transitions_table.php`. Nothing for import jobs, opening batches, opening rows, fiscal periods, or journal entries.

**Why it matters:** Opening batches and fiscal periods are the two surfaces where a wrong edge is unrecoverable (a locked batch cannot be deleted; a locked period cannot be reopened — see CLEAN note). Yet the only record that `DRAFT → VALIDATED → LOCKED` happened is four overwritable scalar columns (`validated_at/by`, `locked_at/by`), and finding 1 shows they *do* get overwritten. For a fiscal-compliance product this is the gap the Workshop precedent exists to close.

**Fix shape:** Lift the existing predicates into an `OpeningBatchStatusMachine` with an explicit `allowedTargetsOf()` (`Draft → [Validated]`, `Validated → [Locked]`, `Locked → []`), funnel the three writes in `OpeningBalanceBatchService` through a single `OpeningBatchTransitionService` that takes the row lock and appends to a new `opening_balance_batch_status_transitions` table, and add a PHPStan rule modelled on `WorkOrderStatusWriteOnlyViaTransitionService.php` so future write sites can't bypass it. Same treatment for `PeriodStatus`, where the bulk updates should at minimum record actor + timestamp per period.

---

## [LOW] Opening-balance numbering repeats the TOCTOU its own sibling documents as fixed

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:428-445` — read-max-then-increment for `OB-{year}-{seq}`, no lock
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:414-437` — same for `HIST-INV-`/`HIST-CN-`
- The corrected sibling: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:277-292` — takes `pg_advisory_xact_lock(hashtext('inv-ob-seq:{company}:{year}'))` with a docblock naming the TOCTOU explicitly

**Problem:** Two of the three opening-number generators lack the advisory lock the third documents. They are backstopped by `unique(['tenant_id','entry_number'])` (`2025_11_30_100000_create_journal_entries_table.php:41`) and `unique(['tenant_id','type','document_number'])` (`2025_12_30_085029_...:24`), so a collision rolls back rather than corrupting — but it surfaces as an unhandled `QueryException` (500), not the clean 422 the controller's `catch (RuntimeException)` (`OpeningBalanceBatchController.php:621`) is written for. Note this only catches the *simultaneous* race; the *sequential* race in finding 1 sails past both indexes.

**Fix shape:** Copy the advisory-lock preamble from `OpeningBalancePostingService::generateOpeningEntryNumber` into both generators.

---

## [LOW] `JournalEntryStatus::Reversed` and the three reversal columns are dead — the schema and the code model reversal differently

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Enums/JournalEntryStatus.php:11`; columns at `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:32-35`

**Problem:** `grep -rn "JournalEntryStatus::Reversed" app/` returns **zero hits** — nothing ever writes it. `reversed_at` / `reversed_by` / `reversal_entry_id` appear only in the model's `$fillable`/`$casts` (`/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/JournalEntry.php:67-69,:84`); no write site exists. Reversal is instead modelled as a sibling counter-entry discovered by existence check (`/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:1001-1007`, `:1573-1579`). The codebase already knows: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:466-469` carries a comment stating "nothing writes journal_entries.reversed_at / reversal_entry_id, so there is currently NO queryable predicate to exclude already-reversed originals."

**Why it matters:** Any consumer that reasons about reversal from the status column or the reversal columns — a report, an export, a future guard — will read "never reversed" for every entry. `InstrumentLifecycleService` is already working around it with a `latest('created_at')` heuristic guarded by a documented single-active-write-off assumption.

**Fix shape:** Either stamp the linkage on the original when a reversal posts (making the enum case and columns live), or delete both so the schema stops advertising a model the code doesn't use. Half-implemented is the worst of the three.

---

# CLEAN — genuinely well-governed on this surface

1. **`VatPeriodStatus` lifecycle** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Taxation/Application/Services/VatPeriodManagementService.php:80-190`. Every edge is explicitly guarded at its own entry point: `closePeriod` requires `isOpen()` (`:81`), `filePeriod` requires `isClosed()` (`:176`), and `reopenPeriod` requires `isClosed()` **and** `! hasClosedOrFiledSuccessor()` (`:135,:139`) — a real ordering invariant, not just a status check. `Filed` is genuinely terminal: the only path out is `reopenPeriod`, which demands `isClosed()`. Reopening also *undoes its effects* (deletes breakdowns, nulls all totals and `closed_at`/`closed_by`, `:145-159`) rather than leaving stale derived data, and every mutation is inside `DB::transaction` with `DB::afterCommit` event dispatch. Backdating into a closed/filed period is refused through a dedicated guard with typed refusal codes (`VatPeriodBackdatingGuard.php:107-109`, `PeriodLockRefusalCode.php:29-31`, both `throw new \LogicException` on the impossible `Open` arm rather than silently defaulting). Only gaps: no DB CHECK on the column (folded into finding 6) and the guards read an unlocked model.

2. **The closed-fiscal-period posting guard itself** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/FiscalPeriodResolverService.php:257-292`. `isDateInClosedPeriod()` is deliberately **not** the inverse of `isDateInOpenPeriod()`, with a docblock explaining exactly why (absence of configuration ≠ deliberate closure — it must not brick posting for an unconfigured company), and it uses `whereIn([Closed, Locked])->exists()` specifically to be order-independent, with a comment recording that the previous `->first()->isClosed()` form was row-order-dependent and could sample an Open period overlapping a Closed one. This is a fix that was reasoned about and documented. It is correctly enforced at the GL chokepoint `GeneralLedgerService::postEntryNow` (`:3541`) *after* the per-company advisory lock (`:3532`), so the rejection is consistent with the same serialized view used for chain-sequence allocation.

3. **`PeriodStatus` terminal reachability** — genuinely one-way. Exhaustive grep of `PeriodStatus::` write sites shows creation (`FiscalYearCreationService.php:123,134,139,142`) and exactly two forward transitions (`FiscalPeriodAutoLockService.php:98` Open→Closed, `:135` [Open|Closed]→Locked). **No reopen path exists at all** — nothing writes `PeriodStatus::Open` outside fiscal-year creation. Terminality is airtight. (Worth flagging to the owner as a *product* question rather than a defect: there is no in-product way to reopen a period closed in error, which for a first tenant mid-onboarding may become an operational problem.)

4. **Inventory opening enter-once guard** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:86-109`. The "active opening already exists for product+location" check is *inside* the transaction, inside a cost lock, uses `whereNull('reverses_movement_id')->whereDoesntHave('reversalOf')` so a properly reversed opening correctly re-opens the slot, throws a typed `OpeningAlreadyExistsException`, and is immediately followed by `lockForUpdate()` on the stock-level row. This is the correct shape and it is why the Inventory batch type survives the race in finding 1 while GL and AR/AP do not — it should be the template for both.

5. **Import finalize re-entrancy** — `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Import/Services/AccountingBalancesPhase.php:141-205`. Explicitly designed for re-running: the `LOCKED`-batch early return at `:151` carries a docblock reasoning about *why only Locked is checked* (post marks Validated and locks in the same transaction, so a Validated-but-unposted batch cannot exist) and what happens to a hypothetical stray Validated batch. The all-or-nothing file-scoped guard at `:164-176` catches rows the import's own ingress rejected before they can be silently dropped from an undeletable posted batch. `ProductOpeningStockPhase.php:39,:112` similarly filters `is_imported = true` and warns `opening_exists` rather than double-applying. `PartiesBalancesPhase.php:94` guards on `[Validated, Locked]`. This layer is the reason finding 5 is MEDIUM rather than HIGH.

6. **Import row writers are idempotent by construction** — `ImportService.php:518` (`upsert`), `:489` (`upsertWithTypeMerge`), `:584` (`upsert`). No `create()` on the import hot path. The retired `StockLevels` import type is refused at three layers with the owner ruling cited inline (`ImportService.php:409-417`).

7. **Import job tenant isolation and single execution entry point** — `ProcessImportJob.php:74-108` rebinds tenant context before any DB access and re-asserts `where('tenant_id', ...)` on every lookup as defense-in-depth; `failed()` (`:282-318`) independently rebinds context because Laravel invokes it in a fresh worker. `$tries = 1` (`:51`) means no silent automatic retry. Route enumeration (`ImportServiceProvider.php:71-80`) confirms there is **no** re-validate endpoint — `validateJob()` is reachable only from `store()` on a fresh job, which closes the "push a Completed job back to Validated" path I went looking for.

8. **Opening-balance ingress precision** — `OpeningBalanceBatchController.php:396-429` applies per-column regex ceilings (money `{1,3}`, quantity `{1,4}`) alongside `numeric`, exactly per rule 19, with custom messages; `OpeningBalanceBatchService.php:196-241` canonicalizes JSONB numerics to fixed-scale strings *before* `json_encode` with a docblock explaining why the per-currency display scale would truncate; `AccountingOpeningService.php:48-83` resolves scale from the owning company's currency via `getScaleSafe`, with a comment naming both hazards (unbound `CompanyContext` in the queued worker, and display-vs-storage scale silently plugging 0.005 into equity). Not my surface, but it is the best-documented code I read today.

---

## Tenancy + doc-adjacent sweep

*Source: background agent transcript `agent-ac338462624c45d5e.jsonl` — final assistant message, extracted verbatim.*

I've completed the sweep. Full report:

---

# NEW FINDINGS — document-adjacent + platform/tenancy lifecycles

## [HIGH] Per-company membership lifecycle is entirely dead — a deactivated employee keeps their POS manager-override PIN

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Domain/Enums/MembershipStatus.php:7-14`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/PinVerifier.php:53-59` (and `:20-33`)
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:156-165`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:617-624`

**Problem:** `MembershipStatus` has four cases. Exhaustive grep over `app/` for `MembershipStatus::Revoked`, `::Suspended`, `::Pending` returns **zero hits — no write site, and no delete path either** (`grep UserCompanyMembership:: | grep -i 'delete\|destroy'` is empty). Every write is `::Active` (`TenantProvisioningService.php:183`, `AuthController.php:451`, `UserController.php:248`, `CompanyController.php:139`, `BackfillMembershipsCommand.php:169`). Meanwhile **four hardened authorization sites read nothing but that column**: `CompanyContext::userHasAccessToCompany` (`CompanyContext.php:148-152`, gating every company-scoped route), `PinVerifier::verifyForApproval` (`:53-59`), `PosAuthController` operator-PIN mirror (`:156-165`), `AuthorizedManagersController` (`:41-42`). Their comments explicitly cite prior findings F-3/FU-2 — "a suspended/revoked member who still holds a pos_pin + approval permission must not…" — but **no code can ever produce a suspended/revoked member**. The only offboarding lever, `UserController::deactivate` (`:617-624`), sets `users.status = Inactive` and revokes tokens but **does not touch memberships**, and neither `PosAuthController`'s operator query nor `PinVerifier` filters on `users.status`.

**Why it matters:** Fire a manager. Their web tokens die, but they remain in the device's cached `operator_pins` roster and `ManagerPinController::verify` → `PinVerifier::verifyForApproval` still returns `Approved` for their PIN. They can authorize discounts, price overrides, returns, and variance shift-closes — online and offline — indefinitely. This is exactly the exposure the F-3/FU-2 hardening was written to close; the state that would close it is unreachable.

**Fix shape:** Cascade `deactivate()`/`destroy()` to `UserCompanyMembership` (`status = Revoked`, `revoked_at`, `revoked_by`) inside the same transaction; add a per-company "remove from company" endpoint that writes `Revoked` rather than deleting; add `->where('status', UserStatus::Active)` to `PosAuthController:161` and a `$user->isActive()` check in `PinVerifier::verifyForApproval`; add a `MembershipStatus` adjacency map + append-only transition rows.

---

## [HIGH] Nightly fiscal-period auto-lock: one-way mass transition, Tunisia's threshold applied to every country, no audit, no reopen

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php:89-99`, `:110-116`, `:126-137`; scheduled at `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/routes/console.php:32-53`; enum `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Domain/Enums/PeriodStatus.php:10-15`; column `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_30_132000_create_compliance_tables.php:117`

**Problem:** Four defects stacked on one surface.
1. `lockOldPeriods()` at `:91` calls `$this->rulesProvider->getRulesForCountry('TN')` **unconditionally**, then applies that threshold to `FiscalPeriod::where('status', Open)` with **no company or country filter**. A French or UK company in the same tenant gets Tunisia's 1-month lock window. (Comment at `:90-91` admits "For now, all countries use 1 month" — the seeded-settings rule is bypassed.)
2. Three bulk `->update(['status' => …])` calls (`:98`, `:115`, `:136`) — mass fiscal state transitions with **no per-row event, no audit row, no actor**. Only an aggregate `Log::info` count at `:66-71`.
3. `PeriodStatus` has no adjacency map and no `isTerminal()`. `PeriodStatus::Open` is written in exactly one place — `FiscalYearCreationService.php:142`, at fiscal-year creation. Grep for reopen/unlock across `app/Modules/Company` and `app/Modules/Accounting` returns nothing. **Closed and Locked are terminal with zero in-product outgoing edges.**
4. `fiscal_periods.status` is `string(20)` with an inline comment listing the values and **no CHECK constraint**, while `pos_shifts` and `bank_statement_lines` (`2026_07_19_110002_create_bank_statement_lines.php:66`) prove the idiom exists in this codebase.

**Why it matters:** For the first client this runs nightly per tenant. One month after go-live, opening-period corrections silently become impossible with no UI path back — the operator's only recovery is a manual `UPDATE fiscal_periods`. And a non-TN company is locked out on the wrong legal calendar, which is a compliance statement the product is making on the customer's behalf.

**Fix shape:** Resolve the threshold per company country inside the loop (`$company->country_code`); write an append-only `fiscal_period_status_transitions` row per period (Workshop precedent); add a permissioned reopen path Closed→Open guarded on "no closed/filed successor" (copy `VatPeriodManagementService::reopenPeriod`, which already does this correctly); add the CHECK.

---

## [MEDIUM] `documents.match_status` — no CHECK, no state machine, and the post-time authoritative value is overwritable on a POSTED supplier invoice

**Where:**
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_06_26_100000_add_supplier_invoice_match_status_and_quantity_invoiced.php:16-21` and `:31`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:261-280`
- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Domain/Enums/SupplierInvoiceMatchStatus.php:15-22`

**Problem:** The migration comment at `:16-21` **explicitly declines** a CHECK ("to keep the migration simple and SQLite-compatible… the cast ensures only valid enum values are persisted") — but the sibling `bank_statement_lines.match_status` in the same codebase does add one (`2026_07_19_110002_create_bank_statement_lines.php:66`), and the Eloquent cast is not a constraint against raw SQL, seeders, or `DB::table()` writes. The column is also `string()` with no length. Separately, `SupplierInvoiceMatchStatus` is a bare 5-case enum — no adjacency, no terminal notion, and `Exception` carries no stickiness.

Write sites: 5 (`CreateSupplierInvoiceService.php:146`, `:220`; `SupplierInvoiceReceiptLinkingService.php:158`; `SupplierInvoicePostingService.php:291`; `SupplierInvoiceController.php:273`). The last one is `POST /supplier-invoices/{id}/match` and has **no status guard whatsoever** — it recomputes and overwrites on any supplier invoice, including one already Posted. `SupplierInvoicePostingService.php:291` writes the match status at posting time and `SupplierInvoiceController.php:347` calls it "the authoritative post-time match_status"; `:354` then branches GL/variance handling on `match_status === PriceVariance`.

**Why it matters:** Post a supplier invoice at `price_variance` (variance booked to the variance account), later receipt data changes or someone hits the match endpoint — `match_status` silently flips to `matched`. The document now claims a clean match while the ledger holds a variance posting, and there is no transition log to reconstruct which state the GL was written against. `RematchDraftSupplierInvoicesCommand.php:48` correctly restricts itself to `DocumentStatus::Draft`; the HTTP endpoint does not.

**Fix shape:** Add the PG CHECK (pgsql-guarded, same shape as `bank_statement_lines`); refuse `match()` unless `$doc->status === DocumentStatus::Draft`; snapshot the posted match status into an immutable field or an append-only transition row.

---

## [MEDIUM] Tenant lifecycle has no reactivation edge, and `Pending`/`Archived` are unreachable-by-code states that hard-fail a tenant

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Domain/Enums/TenantStatus.php:10-16`; `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:20`; `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantDeprovisioningService.php:107-116`

**Problem:** `TenantStatus::Active` is written at three creation sites only (`CreateTenantCommand.php:77`, `TenantProvisioningService.php:89`, `AuthController.php:373`). `suspend()` at `:113-115` writes `Suspended` and is idempotent — but grep for unsuspend/reactivate/resume across `app/Modules/Tenant` and `app/Modules/Company` returns only the `DeprovisionTenantCommand.php:48` help text promising "access is revoked **until reactivated**". **There is no reactivate path in the codebase.** `TenantStatus::Archived` has zero writes (only reads at `ResolveTenancy.php:69` and `AuthController.php:272`, plus tests). `TenantStatus::Pending` also has zero writes — yet it is the **column default** (`create_tenants_table.php:20`), so any tenant row inserted without an explicit status is permanently non-`Active`, and `Tenant::isActive()` (`Tenant.php:196`) returns false forever. No CHECK constraint on `tenants.status`, nor on `tenant_subscriptions.status`, `billing_invoices.status`, or `billing_payments.status` — the only central migrations carrying CHECKs are the three impersonation ones.

Bonus divergence: `2025_12_01_193759_create_tenant_subscriptions_table.php:18` documents the value set as `// trial, active, expired, suspended`, but `SubscriptionStatus` has no `suspended` and adds `past_due/unpaid/paused/cancelling/cancelled`.

**Why it matters:** Support suspends a tenant for non-payment; when they pay, nobody can turn them back on without a manual `UPDATE tenants`. Combined with the missing CHECK, a typo or a stale enum value written by any raw path yields a tenant that `ResolveTenancy` neither blocks nor accepts cleanly.

**Fix shape:** Add `TenantDeprovisioningService::reactivate()` (guarded `Suspended → Active` only) plus an admin endpoint; add CHECK constraints to the four central status columns; either implement `Archived` or delete the case; reconcile the migration comment with the enum.

---

## [MEDIUM] `IngestionStatus` has a real adjacency map — and the controller bypasses it at five write sites, including a terminal `Committed → NeedsReview` edge

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/DocumentIngestion/Domain/Enums/IngestionStatus.php:20-30`; guard at `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/DocumentIngestion/Domain/DocumentIngestion.php:68-76`; bypasses at `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/DocumentIngestion/Presentation/Controllers/DocumentIngestionController.php:180-183`, `:208`, `:220-225`, `:230-236`, `:325`, plus `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/DocumentIngestion/Application/Services/IngestionService.php:159-162`

**Problem:** This module has the good precedent — `allowedNext()` with `Committed, Rejected => []` and `transitionTo()` throwing `InvalidIngestionTransition`. But the write path splits: the two jobs use `transitionTo()` (`ExtractDocumentJob.php:103`, `:119`, `:136`) while the controller uses **raw `update()` and `forceFill()->save()` at 5 sites**, and `IngestionService.php:159-162` uses a raw scoped `update()`. `forceFill` bypasses fillable *and* the domain guard entirely. The most consequential is the catch block at `:230-236`, which sits **outside** the `DB::transaction` at `:219-226`: if the transaction commits `status = Committed` and then `$result->toResponseArray()` at `:228` throws, the catch resets a **terminal** `Committed` row to `NeedsReview` — an edge `allowedNext()` explicitly forbids. Also `document_ingestions.status` is `string(20)` with no CHECK (`2026_07_06_200000_create_document_ingestions_table.php:19`).

**Why it matters:** The row re-enters the reviewable pool and the partial unique index (`:41-43`, `WHERE status NOT IN ('rejected','failed')`) still covers it, so it looks committable again. It does *not* produce a duplicate document — the `committed_type !== null` early return at `:207-211` self-heals on the retry — but the recorded lifecycle lies, and a guard that five sites can walk around will not survive the next edit.

**Fix shape:** Route all controller writes through `transitionTo()` (keep the atomic single-winner claim at `:175-183`, which is well-designed — just have it call the guard after the claim); make the catch block re-read status and only reset when still `Committing`; add the CHECK.

---

## [LOW] SupportAccess: `expireIfElapsed()` overwrites terminal `Revoked`/`Rejected` with `Expired`

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/SupportAccess/Application/Services/GrantLifecycleService.php:484-493`, called at `:161`, `:205`, `:248`

**Problem:** `expireIfElapsed()` checks only `$grant->expires_at->isFuture()` and writes `Expired` unconditionally — it runs *before* the status precondition checks at `:167`, `:210`, `:253`. The sibling `GrantExpiryService.php:127` gets this right: it explicitly skips `[Rejected, Revoked]`. So a grant revoked with a reason, whose window later elapses, can be flipped to `Expired` by any tenant admin calling approve/reject on it — and `appendExpiry()` writes a `GrantExpired` audit event over the revocation narrative.

**Why it matters:** No access is granted (the middleware at `ImpersonationContext.php:132` checks `status === Revoked || revoked_at !== null`, and the belt-and-braces `revoked_at` saves it). The damage is audit fidelity on a security surface: a status-based report will show "expired" for a grant a customer actively revoked.

**Fix shape:** Mirror `GrantExpiryService:127` — `if (in_array($grant->status, [Rejected, Revoked, Expired], true)) return false;` at the top of `expireIfElapsed()`.

---

## [LOW] `ElevationStatus::Cancelled` and `::Expired` are unreachable; an approved write-elevation has no revoke edge and its window is duplicated onto the session

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/SupportAccess/Domain/Enums/ElevationStatus.php:9-13`; `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/SupportAccess/Application/Services/ElevationService.php:60`, `:105`, `:156`, `:107-112`

**Problem:** Exhaustive grep across `app/`, `tests/`, `database/` finds writes for `Pending`, `Approved`, `Rejected` only — `Cancelled` and `Expired` are never written by anything (they *are* CHECK-constrained at `2026_08_06_230000_create_impersonation_access_tables.php:145`, so the constraint is wider than reality). And the elevation's real effect is duplicated: approval writes `expires_at` on the elevation (`:107`) **and** `write_expires_at` on the session (`:112`). Nothing consumes `ImpersonationElevation` outside `ElevationService`/`SupportAccessQueryService`/the DTO, so the enforcement lives entirely on the session copy. There is no path to revoke an approved elevation short of ending the whole session, and no sweep ever marks an elapsed elevation `Expired`.

**Why it matters:** Two representations of one write window that can drift, and an "approved" elevation list that never ages out — a support-access review will show stale approvals as live. Low because the enforced copy (session) is the one that actually gates writes.

**Fix shape:** Either drop the two dead cases or wire them (`ExpireSupportAccessCommand` sweeps elevations too; a cancel endpoint for the requesting operator). Make the session's `write_expires_at` derived from the elevation rather than a second stored copy.

---

## [LOW] Two different billing invoice-number generators for one globally-unique column; the Stripe one is tenant-scoped and will collide deterministically

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php:586-597` vs `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Billing/Application/Services/InvoiceService.php:338-355`; column at `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/2025_12_16_100002_create_billing_invoices_table.php:29-30`

**Problem:** `billing_invoices.number` is `->unique()` **globally** (comment: "globally unique for compliance"). `InvoiceService::generateInvoiceNumber()` scans globally (`where('number','like', "{$prefix}-{$year}{$month}%")`) — a check-then-set race, but the unique index is a correct backstop. `StripeWebhookController::generateInvoiceNumber($tenantId)` instead does `Invoice::where('tenant_id',$tenantId)->whereYear(...)->count() + 1` — **tenant-scoped counter against a global unique index**, and it uses a different format (`INV-2026-00001`, 5 digits, no month) than `InvoiceService` (`INV-202608-0001`, 4 digits, with month).

**Why it matters:** Tenant A's first Stripe invoice of the year takes `INV-2026-00001`. Tenant B's first takes the same string and dies on SQLSTATE 23505 inside the webhook handler — Stripe sees a 500 and retries forever, and with no event-id idempotency (a known deferred item documented at `StripeWebhookController.php:67`) the retries compound. Not a corruption path, a hard failure; and it only bites at tenant #2 on Stripe billing, hence LOW for the first-client launch.

**Fix shape:** Delete the controller's generator and call `InvoiceService`'s (or a shared sequence service); if per-tenant numbering is wanted, change the index to `unique(['tenant_id','number'])` and make both generators agree on the format.

---

## [LOW] Stripe status mapping fails open to `Active` and ignores the current status, so terminal `Cancelled`/`Expired` can be resurrected

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php:569-582`, consumed at `:157` and `:189`

**Problem:** `mapStripeStatus()` ends in `default => SubscriptionStatus::Active`, and both call sites additionally default a missing payload field to `'active'` (`(string) ($stripeSubscription['status'] ?? 'active')`). `SubscriptionStatus::isTerminal()` (`SubscriptionStatus.php:48-54`) declares `Cancelled`/`Expired` terminal, but nothing consults it before these writes — no adjacency check, no current-status guard.

**Why it matters:** An unrecognised or newly-introduced Stripe status, or a malformed/replayed payload, silently writes `Active` — and `SubscriptionStatus::hasAccess()` then grants the tenant full product access. Fail-open on the billing gate. Low for tenant #1 (not on Stripe) but it is a billing-integrity hole the moment self-serve turns on.

**Fix shape:** `default => throw` (or map to `PastDue` and alert); remove the `?? 'active'` fallbacks; consult `isTerminal()` before overwriting.

---

## [LOW] `Document::getPaymentStatus()` / `getFulfillmentStatus()` ignore `documents.status`

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Domain/Document.php:890-926` and `:934-957`; exposed at `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:388` and `:453`

**Problem:** Both computed statuses branch only on `DocumentType` and then sum allocations / count delivery notes. Neither reads `$this->status`. A `Cancelled` invoice that carried allocations before cancellation reports `payment_status: "paid"` on the allocation-detail endpoints.

**Why it matters:** Cosmetic rather than corrupting — `AgedReceivablesService` correctly filters `->where('status', DocumentStatus::Posted)` at `:49`, `:198`, `:210`, `:262`, `:328`, so AR aging and the balance rollups exclude cancelled documents; and `DocumentPostingService::cancel()` refuses to cancel a fiscal document that has allocations at all (`:167`, `:190`). The exposure is a detail screen asserting a void invoice is paid.

**Fix shape:** Return a `Voided`/`NotApplicable` value (or short-circuit on `$this->isCancelled()`, which already exists at `Document.php:539`) in both computed accessors.

---

## [LOW] `OpeningBalanceBatchService::lockBatch()` builds a hash chain with an unlocked read-then-write

**Where:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:362-387`

*(Flagging per the coordinator's note that another lane is already fixing opening-batch races — this is independent confirmation, expect overlap.)*

**Problem:** `lockBatch()` guards on `$batch->canLock()` (Validated only — correct), then reads the previous locked batch at `:374-378` and writes `status`, `hash`, `previous_hash` at `:381-386`. There is **no `DB::transaction` and no `lockForUpdate`** around that read-modify-write (the `DB::transaction` calls in this file are at `:138`, `:484`, `:510`, all elsewhere). `markBatchValidated()` at `:323-335` has the same unlocked shape for `Draft → Validated`. Contrast `CorrectingEntryService::post()` (`CorrectingEntryService.php:122-137`), which documents exactly this TOCTOU and closes it with a per-company `pg_advisory_xact_lock`.

**Why it matters:** Two concurrent locks of sibling batches for the same company+type both read the same `$previousBatch` and both write it as `previous_hash` — the opening-balance hash chain forks, and chain verification then fails or silently accepts a branch. `OpeningBatchStatus` itself is well-shaped (`canPost`/`canLock`/`isImmutable`), so the enum is not the problem; the persistence is.

**Fix shape:** Wrap `lockBatch` in `DB::transaction` with `pg_advisory_xact_lock` keyed on company+type (copy `postCorrectingEntryGl`'s pattern), re-read the batch `lockForUpdate` inside, and re-assert `canLock()`.

---

# CLEAN — surfaces checked and found genuinely well-governed

**`documents` sibling status columns — structurally cannot diverge.** `payment_status`, `delivery_status`, and `fulfillment_status` are **not columns**. Grep across `database/migrations/` finds them only on unrelated tables (`scheduling_appointment_reminders.delivery_status`, which *does* carry a CHECK at `2026_04_19_140007:52-54` plus a partial unique index). They are computed on demand from allocations and delivery notes (`Document.php:809`, `:890`, `:934`) with `getOutstandingAmount()` documented as the source of truth and `balance_due` explicitly labelled a trigger-maintained cache (`Document.php:849-851`). Single source of truth, nothing to drift — this is the right design, and it means exclusion #9's hypothetical "CHECK gap on those columns" does not exist.

**`DocumentPostingService::cancel()`** (`:149-252`) — genuinely strong: idempotent early return, `lockForUpdate()` re-read inside the transaction with a comment naming the exact double-reversal race it closes (`:174-184`), `hasBlockingAllocations()` checked twice (`:167`, `:190`), VAT-period lock before the GL reversal (`:215`), GL reversal and void made atomic (`:238-241`). *Latent, not reported as live:* the non-fiscal branch (`:245-247`) writes no GL reversal, but `FISCAL_DOCUMENT_TYPES` is `{Invoice, CreditNote}` only (`:47-50`), no caller ever passes a supplier invoice (all four call sites are `SalesOrderService.php:221` and `RefundService.php:131/281/859`), and `VatPeriodCancellationGuard.php:200-217` documents the gap in detail and refuses purchase-document cancellation as the deliberate mitigation. Known, mitigated, annotated.

**VAT-period lifecycle** (`VatPeriodManagementService.php:133-193`) — the model to copy. `reopenPeriod()` requires `isClosed()` (so `Filed` is truly terminal) **and** `hasClosedOrFiledSuccessor()` is false; `filePeriod()` requires `isClosed()`; both are transactional and emit domain events. `VatPeriodStatus` values are uppercase and the column default `'OPEN'` (`2026_03_23_200000_create_vat_periods_table.php:21`) matches exactly — I checked for a case mismatch and there isn't one.

**SupportAccess grants** — the best-governed surface I swept, apart from the one `expireIfElapsed` hole. Every write in `GrantLifecycleService` is preceded by an explicit status precondition that throws (`:167`, `:210`, `:253`, `:295`); all mutations go through `grants->mutateLocked()` inside `centralTransaction()`; every transition appends to a hash-chained audit mirror; open sessions are force-ended on revoke (`:317-331`); `2026_08_06_230000_create_impersonation_access_tables.php:136-159` generates **CHECK constraints from `Enum::cases()`** for all seven status/type columns and adds append-only UPDATE/DELETE triggers on the event tables. This is the tenancy-side equivalent of the Workshop precedent.

**`CorrectingEntryService`** (r2f4) — `confirm()` and `post()` (`:99-121`, `:139+`) both do idempotent early-return, then an explicit `!== <required predecessor>` throw; `post()` documents its TOCTOU window and closes it with a `pg_advisory_xact_lock`, with a comment forbidding removal of either half. `create()` runs a structural pre-flight so an unpostable correction can't be saved.

**`DocumentIngestion` concurrency** (separate from the bypass finding) — the single-winner atomic claim at `DocumentIngestionController.php:175-183` (conditional UPDATE on `status = NeedsReview`, with a comment explaining why adding `Committing` to the prior-state set would break it) and the `WHERE status NOT IN ('rejected','failed')` partial unique index on `(company_id, checksum)` are both correct.

**`POSAccountChargeDraftService`** — creates drafts idempotently and asserts an existing draft matches on both `status` and `fiscal_status` before reuse (`:101-118`). No issue.

**`UserStatus`** — unlike `MembershipStatus`, this one is fully wired: activate/deactivate both exist with guards, self-deactivation refused, tokens revoked, central identity index cleaned, audit event logged (`UserController.php:529-545`, `:598-640`).

**`VerificationStatus`** (`Company/Domain/Enums/VerificationStatus.php`) — noting rather than reporting: all five cases have zero writes and `Company::isVerified()` (`Company.php:475`) has zero callers. Dead scaffolding, not a defect, since nothing gates on it.
