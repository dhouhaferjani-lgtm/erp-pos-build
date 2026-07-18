# Treasury Phase ⑤a — Outbound Instruments Implementation Plan (Rev 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **Spec:** [`../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`](../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md) §4 (Rev 2).
> **Rev 2 (2026-07-18):** reconciled against three pre-dispatch reviews — [Codex](../reviews/2026-07-18-treasury-phase5-plans-codex-review.md) (REJECT → fixes applied), [treasury-reviewer](../reviews/2026-07-18-treasury-phase5-plans-treasury-review.md), [tenancy-authz-reviewer](../reviews/2026-07-18-treasury-phase5-plans-tenancy-authz-review.md) (both APPROVE-WITH-FIXES → applied). Key deltas: durable action-key idempotency on `instrument_events` with replay-check-BEFORE-transition-validation; outbound GL builders owned in Task 3; deferred-supplier `Dr 401/Cr bank` branch suppression (double-post fix); `expense_metadata.payment_instrument_id` migration + pending-instrument settlement state; module-boundary events for expense effects; `OutboundRepositoryValidator`; real controller anchors (`PaymentInstrumentController`); roles `admin`+`accountant`; routes inside the existing group.

**Goal:** Supplier-direction (outbound) check/effet lifecycle with correct payable-instrument accounting: issue posts Dr AP / Cr payable-purpose with NO cash movement; a dedicated outbound state machine clears/bounces/cancels with durable per-action idempotency and atomic subledger reopening.

**Architecture:** Extend the shipped Phase-② portfolio (`InstrumentAccountPurpose` + `InstrumentAccountResolver` + `instrument_events`) with two payable purposes, four outbound GL builders in `GeneralLedgerService`, and a dedicated `OutboundInstrumentService` (never touches inbound `clear()`/`bounce()` internals — they structurally reject outbound at `InstrumentLifecycleService.php:196-204,323-331`). Fix the deferred-supplier path in `PaymentController` (suppress the existing immediate-settlement JE + movement; post the issue JE instead). All GL via builders + `postEntryNow()`, all movements via the port, all cross-module effects via domain events.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PHPUnit (pgsql, by-path), PHPStan L8, React 19 + Vitest for the two FE touches.

## Global Constraints

- Worktree: `../erp.treasury-phase5`. NEVER commit to shared `dev`.
- Money: `decimal(15,3)`, numeric-strings end-to-end, `CurrencyScale::bcformatStrict` + injected `CurrencyScaleResolverInterface` with explicit currency (rule 19). No float ever.
- GL: entries built ONLY by the Task-3 `GeneralLedgerService` builders, posted ONLY via `postEntryNow()` (`GeneralLedgerService.php:2889` — posts a pre-built entry, returns void; it does NOT create entries). The `DB::afterCommit` posting path is FORBIDDEN for these flows.
- Movements: port only. **Idempotency = durable action keys on `instrument_events` (Task 2), checked FIRST — before transition validation** (a replayed action must return the original result, not throw an invalid-transition error because the first run already moved the status).
- **Routes:** new routes go INSIDE the existing `api/v1` group in `app/Modules/Treasury/Presentation/routes.php` (carries `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` at `:33`); per-route add only `->middleware('can:…')`. NEVER a parallel group (would drop `EnforceTokenTenantClaim`). Instrument endpoints use `{instrument}` route-model binding (siblings at `:129-143`) — never raw `{id}` (malformed UUID vs PG uuid ⇒ 500).
- **Module boundaries (rule 6):** Treasury never writes Expense models. Expense effects go through `InstrumentCleared`/`InstrumentCancelled` events consumed by an Expense-module listener.
- Enums for statuses (rule 9); constructor injection `private readonly` (rule 13); no placeholder code.
- Tests: PHPUnit by path, `RefreshDatabase` + `RolesAndPermissionsSeeder`; NEVER the full suite. Every task: PHPStan+Pint clean on touched paths, then commit.
- 🚦 **HARD GATE** after each wave: `treasury-reviewer` (Opus) adversarial pass on the wave diff; REJECT ⇒ fix before next wave. Human merges only.
- **Chart codes:** `403`/`4035` are seeder-owned and **final for this build** (owner ruling 2026-07-18). If different numbers are ever preferred, that is a later seeder edit + `treasury:backfill-payable-instrument-accounts` re-run — never a blocker here.
- **Deploy notes (produce `docs/handoff/treasury-phase5a-deploy-checklist.md` in Wave 4, stacking on the owed ③/④ checklists):** 2 tenant migrations + chart-seed/backfill command per tenant + `RolesAndPermissionsSeeder` re-run + `permission:cache-reset` per tenant (tenant-blind Spatie cache bug — otherwise existing tenants silently 403).

## File Structure

```
apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php   (modify: 4 outbound builders)
apps/api/app/Modules/Treasury/
├── Domain/Enums/InstrumentAccountPurpose.php          (modify: +2 cases)
├── Domain/PaymentInstrument.php                       (modify: +presentation_cycle)
├── Domain/InstrumentEvent.php                         (modify: +action_key/semantic_digest/journal_entry_id/movement_id)
├── Domain/Events/{InstrumentCleared,InstrumentCancelled}.php (create)
├── Application/Services/InstrumentAccountResolver.php (modify: +2 match arms)
├── Application/Services/OutboundInstrumentService.php (create)
├── Application/Services/OutboundRepositoryValidator.php (create)
├── Application/DTOs/OutboundTransitionResult.php      (create)
├── Presentation/Controllers/PaymentController.php     (modify: issue path)
├── Presentation/Controllers/PaymentInstrumentController.php (modify: 4 outbound actions — NOT a new controller)
├── Presentation/Console/ReconcileTreasuryCommand.php  (modify: check-4 outbound)
├── Presentation/Console/InstrumentMaturityAlertsCommand.php (verify/extend)
└── Presentation/routes.php                            (modify: 4 routes inside existing group)
apps/api/database/migrations/tenant/
├── 2026_07_18_100000_add_presentation_cycle_to_payment_instruments.php
├── 2026_07_18_100100_add_action_key_to_instrument_events.php
└── 2026_07_18_100200_add_payment_instrument_to_expense_metadata.php
apps/api/database/seeders/{Tunisia,France,Generic}ChartOfAccountsSeeder.php + RolesAndPermissionsSeeder.php (modify)
apps/api/app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php  (create)
apps/api/app/Modules/Expense/Application/Services/ExpenseService.php        (modify: instrument mode)
apps/api/app/Modules/Expense/Application/Listeners/SyncExpenseOnInstrumentLifecycle.php (create)
apps/web/src/features/…                                (Wave 4: pay-dialog mode + échéancier grouping)
```

---

## Wave 1 — Accounting + idempotency foundation

### Task 1: Payable purposes + resolver + chart seeds + backfill

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Domain/Enums/InstrumentAccountPurpose.php`; `apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:39-51` (match has NO default arm — adding cases without arms fails PHPStan/`UnhandledMatchError`, which is the enforcement); seeders `TunisiaChartOfAccountsSeeder.php` (~:167-216), `FranceChartOfAccountsSeeder.php` (403 exists ~:163-169 — add 4035 only), `GenericChartOfAccountsSeeder.php`
- Create: `apps/api/app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php`
- Test: `apps/api/tests/Feature/Treasury/PayableInstrumentAccountsTest.php`

**Interfaces:**
- Produces: `InstrumentAccountPurpose::ChecksToPay` (`'checks_to_pay'`, code `'4035'` all charts), `::EffetsPayable` (`'effets_payable'`, code `'403'` all charts); artisan `treasury:backfill-payable-instrument-accounts {--dry-run}` (idempotent, iterates companies explicitly — never a nullable `Company` param, per the seeder-trap memory; creates missing accounts as **liability** type under the supplier parent).

- [x] **Step 1: failing tests** — resolver resolves both purposes on TN/FR/generic-seeded companies; resolved accounts are liability-type; `resolveOrFail` throws `MissingInstrumentAccountException` on a gutted chart; backfill idempotent (delete 4035 → run twice → exactly one liability 4035); **backfill flags an existing wrong-type `403`/`4035` account instead of silently reusing it** (report + skip, non-zero exit).
- [x] **Step 2:** run by path → FAIL. **Step 3:** implement. **Step 4:** PASS + PHPStan/Pint. **Step 5:** commit `feat(treasury): payable instrument purposes + chart seeds + backfill`.

### Task 2: Idempotency + linkage migrations

**Files:**
- Create: 3 tenant migrations (`presentation_cycle` int default 1 on `payment_instruments`; `action_key` varchar nullable **unique**, `semantic_digest` varchar nullable, `journal_entry_id` uuid nullable, `movement_id` uuid nullable on `instrument_events`; `payment_instrument_id` uuid nullable **unique** FK on `expense_metadata` — `ExpenseMetadata.php:51` has no such column today)
- Modify: `PaymentInstrument.php`, `InstrumentEvent.php`, `apps/api/app/Modules/Expense/Domain/ExpenseMetadata.php` (fillable/casts/relations)
- Test: `apps/api/tests/Feature/Treasury/OutboundIdempotencyStoreTest.php`

**Interfaces:**
- Produces: **the durable action-key store.** Every ⑤a lifecycle action writes an `instrument_events` row carrying `action_key` (`instrument:{id}:issue|clear:{cycle}|bounce:{cycle}|cancel`), a `semantic_digest` (sha256 of canonical JSON: action, instrumentId, amount, currency, repositoryId, occurredAt-date), and the produced `journal_entry_id`/`movement_id`. Replay lookup = `WHERE action_key = ?` under the instrument lock: digest match ⇒ return original ids (`replayed: true`); digest mismatch ⇒ throw. This is checked **before transition validation** for every action including cancel (uniform return-original contract — supersedes any throw-safe shortcut). `presentation_cycle` = the `{cycle}`, incremented only by `represent()`.

- [x] Steps: failing tests (unique `action_key` violation; digest mismatch detection; expense-metadata FK + uniqueness) → migrations+models → green → commit `feat(treasury): durable instrument action-key idempotency + expense instrument link`.

🚦 **GATE 1 — treasury-reviewer (Opus).** Focus: liability typing, seeder fidelity, action-key/digest design, migration self-guarding.

---

## Wave 2 — Outbound state machine

### Task 3: GL builders + `OutboundRepositoryValidator` + `clear()`

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (4 builders; existing inbound builders `:2481/:2587/:2811` untouched)
- Create: `OutboundInstrumentService.php`, `OutboundRepositoryValidator.php`, `OutboundTransitionResult.php`, `Domain/Events/InstrumentCleared.php`
- Test: `apps/api/tests/Feature/Treasury/OutboundInstrumentServiceTest.php`, `apps/api/tests/Unit/Treasury/OutboundRepositoryValidatorTest.php`

**GL builders (owned here; consumed by Tasks 4-6, 8; no task builds `JournalEntry`/`JournalLine` inline; mirror `createSupplierPaymentJournalEntry`'s shape `:707-775` — entry_number/journal_code(`EF`)/source_type/source_id populated; 401 legs partner-tagged — the partner-balance listener recomputes `payable_balance` from partner-tagged 401 lines):**

```php
public function createOutboundInstrumentIssueEntry(...): JournalEntry;        // Dr 401(partner) / Cr payable-purpose
public function createOutboundInstrumentClearingEntry(...): JournalEntry;     // Dr payable-purpose / Cr repository gl_account
public function createOutboundInstrumentDishonorEntry(...): JournalEntry;     // Dr repository gl_account / Cr payable-purpose
public function createOutboundInstrumentCancellationEntry(...): JournalEntry; // Dr payable-purpose / Cr 401(partner)
```

**Validator (concrete, reused by Task 6):**

```php
final readonly class OutboundRepositoryValidator
{
    /** @throws DomainException on: not found in tenant+company / not BankAccount / inactive /
     *  null gl_account_id / currency mismatch / instrument bank_id set and != repository bank_id */
    public function validate(string $repositoryId, string $tenantId, string $companyId, string $currency, ?string $instrumentBankId): PaymentRepository;
}
```

**Service (signatures consumed by Tasks 4-8 and ⑤b tier-3):**

```php
final readonly class OutboundInstrumentService
{
    public function clear(string $instrumentId, string $tenantId, string $companyId, string $userId, ?string $occurredAt = null): OutboundTransitionResult;
    public function bounce(string $instrumentId, string $tenantId, string $companyId, string $userId, ?string $reason = null): OutboundTransitionResult;
    public function represent(string $instrumentId, string $tenantId, string $companyId, string $userId): OutboundTransitionResult;
    public function cancel(string $instrumentId, string $tenantId, string $companyId, string $userId, string $reason): OutboundTransitionResult;
}
// OutboundTransitionResult: instrumentId, fromStatus, toStatus, journalEntryId, movementId(?), replayed(bool)
```

**Per-action skeleton (all four):** one `DB::transaction`: `lockForUpdate` instrument (tenant+company-scoped) → **replay check via `instrument_events.action_key` (Task 2) — BEFORE anything else**; digest match ⇒ return original; mismatch ⇒ throw → assert `direction === Outbound` → assert transition per §4.3 table (else `InvalidInstrumentTransitionException`) → `OutboundRepositoryValidator` → build JE (Task-3 builder) + `postEntryNow()` → movement via port where the table says so → write the `instrument_events` row (action_key, digest, produced ids) → status/timestamps → afterCommit domain event + audit.

- [x] **Step 1: failing tests** — clear posts Dr payable / Cr `repository.gl_account_id` + out movement keyed `instrument:{id}:clear:1`, status Cleared, fires `InstrumentCleared`; **replay returns original ids, posts nothing new, even though status is already Cleared** (the ordering fix); digest-mismatch replay throws; inbound instrument rejected; GL-failure injection ⇒ full rollback (status Received, zero JEs/movements/events); validator unit matrix: cash-register / inactive / null-GL / wrong-currency / wrong-bank / cross-company each rejected.
- [x] Steps 2-5: red → implement → green → commit `feat(treasury): outbound GL builders, repository validator, clearing`.

### Task 4: `bounce()` + `represent()`

**Files/Test:** as Task 3.

- [x] **Step 1: failing tests** — bounce posts dishonor JE + compensating **in** movement (`reverses_movement_id` = clear movement) keyed `:bounce:1`, status Bounced, **AP(401) balance unchanged**; represent increments `presentation_cycle` → clear semantics on `:clear:2`, cycle-1 artifacts untouched; bounce→represent→bounce lands `:bounce:2`; bounce from Received throws; **representation failure (GL injection) rolls back the cycle increment**; concurrent identical bounce requests → one executes, one replays.
- [x] Steps 2-5 → commit `feat(treasury): outbound bounce + re-presentation cycles`.

### Task 5: `cancel()` — atomic subledger reopen

**Files:**
- Modify: `OutboundInstrumentService.php`; Create: `Domain/Events/InstrumentCancelled.php`
- Test: `apps/api/tests/Feature/Treasury/OutboundCancelReopenTest.php`

**Interfaces:**
- Consumes: deferred-supplier issue artifacts (`Payment` Completed + positive allocations + document `balance_due` reduction + optional paid status — `PaymentController.php:790-813,905-920`).
- Produces: cancel = ONE transaction (lock order: instrument → payment → allocations → documents): cancellation JE via builder → **negative allocations** appended → document `balance_due`/status recomputed (multi-document and partial-allocation payments included) → payment `Reversed` → `instrument_events` row → afterCommit `InstrumentCancelled` + audit. Replay via action key returns original (Task 2 store — cancel produces no movement, so the event row IS the replay anchor).

- [x] **Step 1: failing tests** — cancel from Received reopens document balance atomically; from Bounced allowed; from Cleared throws; multi-document allocation reversal; partial-failure injection between JE and allocations ⇒ nothing survives; replay returns original; concurrent cancel+clear on same instrument → exactly one wins.
- [x] Steps 2-5 → commit `feat(treasury): outbound cancel with atomic subledger reopen`.

🚦 **GATE 2 — treasury-reviewer (Opus).** Focus: replay-before-validation ordering, builder correctness incl. partner tags, rollback proofs, lock order, event emission.

---

## Wave 3 — Issue path + endpoints + reconcile

### Task 6: Deferred-supplier issue posting in `PaymentController`

**Files:**
- Modify: `PaymentController.php` (deferred-supplier block ~:757-813; supplier-JE branch `:975-996`; movement guard `:1096`; repository guard ~:621-642)
- Test: `apps/api/tests/Feature/Treasury/DeferredSupplierPaymentTest.php`

**Interfaces:**
- Produces, for `$isDeferredSupplier`: (a) ⛔ **the `:975-996` branch must NOT call `createSupplierPaymentJournalEntry`** (today it posts `Dr 401 / Cr bank` — immediate settlement — which combined with a new issue JE would double-debit 401 and wrongly credit bank); the **issue JE replaces it**: `createOutboundInstrumentIssueEntry` (partner-tagged 401 / Cr payable-purpose by `kind`: `cheque→ChecksToPay`, `effet→EffetsPayable`), action-keyed `instrument:{instrument_id}:issue` via the Task-2 store; (b) movement guard becomes `! $isDeferredCustomer && ! $isDeferredSupplier` — **no movement at issue**; (c) `OutboundRepositoryValidator` invoked at issue (422 envelope on violation).

- [x] **Step 1: failing tests** — deferred-supplier cheque: **exactly ONE JE**, **zero journal lines on the bank account**, repository balance unchanged, zero movements, instrument outbound Received linked; effet variant hits EffetsPayable; non-BankAccount repository 422; deferred-customer AND immediate-supplier full regression (JE shape + movement present as today); retry with the same client `Idempotency-Key` header ⇒ no second JE/instrument (test states its reliance on the header + `:1131` recovery path).
- [x] Steps 2-5 (minimal diff; no controller restructure) → commit `fix(treasury): deferred-supplier issues payable-instrument JE — suppress immediate-settlement posting`.

### Task 7: Outbound HTTP actions + permissions

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php` (**the real lifecycle controller — `InstrumentLifecycleController` does not exist**), `Presentation/routes.php` (inside the existing group, `{instrument}` binding), `RolesAndPermissionsSeeder.php`
- Test: `apps/api/tests/Feature/Treasury/OutboundInstrumentEndpointsTest.php`

**Interfaces:**
- Produces: `POST /payment-instruments/{instrument}/clear-outbound | bounce-outbound | represent | cancel-outbound` → thin delegations. **Permissions: `instruments.clear-outbound`** (clear/bounce/represent — one operational capability) **and `instruments.cancel-outbound`** (cancel — heavier: reopens AP; parity with the inbound `clear`/`cancel` split). **Grants: `admin` + `accountant`** (the roles holding the inbound analogs, seeder `:476-482`/`:700-706`; there is NO `owner` role).
- [x] Steps: failing tests (happy path ×4; 403 for `manager`; inbound instrument 422; invalid transition 422; malformed UUID → 404 not 500) → implement → green → commit `feat(treasury): outbound lifecycle endpoints + split permissions`.

### Task 8: Reconcile check-4 outbound + maturity alerts

As Rev 1: extend `ReconcileTreasuryCommand:249-424` with Σ(outbound Received+Bounced by kind) vs payable-purpose GL balances, alert-only; verify `InstrumentMaturityAlertsCommand` outbound coverage first (`MaturingInstrumentsController` already filters both directions `:27-46` — no controller work), extend only if absent; outbound copy `treasury.maturity.outbound_due`.
- [ ] Steps: failing (seeded outbound drift → alert; clean → silent; inbound regression) → implement → commit `feat(treasury): reconcile outbound portfolio check + maturity alerts`.

🚦 **GATE 3 — treasury-reviewer (Opus).** Focus: the `:975-996` suppression (the double-post), regressions, permission split coverage, alert-only invariant.

---

## Wave 4 — Expense pay-by-instrument + FE

### Task 9: Expense instrument mode + lifecycle listener

**Files:**
- Modify: `ExpenseService.php` (settle ~:445-575), `ExpenseController.php:228-264` + FormRequest
- Create: `apps/api/app/Modules/Expense/Application/Listeners/SyncExpenseOnInstrumentLifecycle.php` (registered in the module's event wiring — trace how Expense registers listeners first)
- Test: `apps/api/tests/Feature/Expense/ExpensePayByInstrumentTest.php`

**Interfaces:**
- Produces: `POST /expenses/{id}/pay` accepts `mode: 'instrument'` + `instrument: {kind, reference, bank_id?, maturity_date?, drawer_name?}`. Settlement: issue JE via `createOutboundInstrumentIssueEntry`, instrument registered via `InstrumentLifecycleService::receive()` (GL-free — verified `:67-131`), **`expense_metadata.payment_instrument_id` set (Task 2 column)**, NO movement, `is_paid` stays false. **Idempotency/second-payment guard:** settle (any mode) REJECTS when `payment_instrument_id` references an instrument in `Received`/`Bounced` (pending) — closes both the retry double-post and the pay-cash-while-check-outstanding hole (the existing `$alreadySettled` movement probe `:480-497` cannot see instrument mode). **Listener** consumes `InstrumentCleared` (sets `is_paid=true, paid_at=cleared date`, repository/method from the instrument) and `InstrumentCancelled` (clears `payment_instrument_id`, resets paid fields) — Treasury never writes `ExpenseMetadata` (rule 6).
- [ ] **Step 1: failing tests** — instrument settle: correct JE, no movement, metadata linked, `is_paid` false; retry rejected; cash-mode settle while instrument pending rejected; clear → listener flips `is_paid` (+ movement exists via Task 3); cancel → listener resets metadata; cash-mode regression; LinkedCost still rejected (`:468-478`).
- [ ] Steps 2-5 → commit `feat(expense): pay-by-instrument settlement + lifecycle listener`.

### Task 10: FE — pay dialog mode + échéancier grouping

As Rev 1 (locate pay dialog via `expenses.pay` mutation; échéancier via `maturing` usage): mode toggle with instrument fields (reuse `BankPicker`), string payloads, direction-grouped échéancier sections, FR+**AR** i18n in-phase, tokens, `tenantScopedKey`, `typescript:transform` after Task 9.
- [ ] Steps: failing Vitest → implement → `pnpm lint && pnpm typecheck` → commit.

🚦 **GATE 4 — treasury-reviewer + frontend-conventions-reviewer (Opus), then ⑤a exit review:** full-branch treasury pass + E2E (issue → échéancier payable → clear → movement/balance → bounce → represent → cancel-from-bounced second instrument → reconcile clean; expense instrument settle → clear flips paid). Produce the deploy checklist.

---

## Self-review (Rev 2)

- Review fixes traced: Codex B1→T2+T3 skeleton (replay-first, durable store), B2→T2 migration + T9 guard/listener, H9→T7 real controller + T3 builders (postEntryNow returns void), H10→T3 validator + matrix, tenancy #1/#4/#5/#6→Global-Constraints/T7, treasury #1→T6, #2→T3, #3/#4→T9, #6→superseded by T2 durable store (uniform return-original).
- Failure-mode test list from the Codex review is distributed across T3-T9 steps; any residual case (multi-document cancel, wrong-type 403 backfill) named in its task.
- Consistency: `OutboundInstrumentService` signatures identical in T3/T5/⑤b-consumption; action keys uniform `instrument:{id}:{action}[:{cycle}]`; permissions split matches inbound convention.
