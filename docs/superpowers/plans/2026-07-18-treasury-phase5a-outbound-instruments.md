# Treasury Phase ⑤a — Outbound Instruments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **Spec:** [`../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`](../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md) §4 (Rev 2). Codex spec review: [`../reviews/2026-07-18-treasury-phase5-spec-codex-review.md`](../reviews/2026-07-18-treasury-phase5-spec-codex-review.md).

**Goal:** Supplier-direction (outbound) check/effet lifecycle with correct payable-instrument accounting: issue posts Dr AP / Cr payable-purpose with NO cash movement; a dedicated outbound state machine clears/bounces/cancels with per-action idempotency and atomic subledger reopening.

**Architecture:** Extend the shipped Phase-② instrument portfolio (`InstrumentAccountPurpose` + `InstrumentAccountResolver` + `InstrumentLifecycleService`) with two payable purposes and a dedicated `OutboundInstrumentService` (separate service — never touches inbound `clear()`/`bounce()` internals, which structurally reject outbound at `InstrumentLifecycleService.php:196-204,323-331`). Fix the deferred-supplier path in `PaymentController` (movement suppression + issue JE). All GL via `postEntryNow()`, all movements via the port.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PHPUnit (pgsql, by-path only), PHPStan L8, React 19 + Vitest for the two FE touches.

## Global Constraints

- Worktree: `../erp.treasury-phase5` (branch `feat/treasury-phase5-design`; implementation branches off it or continues on it per dispatch brief). NEVER commit to shared `dev`.
- Money: `decimal(15,3)`, numeric-strings end-to-end, `CurrencyScale::bcformatStrict` + injected `CurrencyScaleResolverInterface` with explicit currency (CLAUDE rule 19). No float ever.
- GL: `postEntryNow()` only (synchronous in-transaction, period-guarded). The `DB::afterCommit` posting path is FORBIDDEN for these flows (spine §5).
- Movements: port only (`TreasuryMovementServiceInterface`). Idempotency keys enforced BEFORE GL posting; exact replay returns original result; semantic mismatch throws.
- Enums for all statuses (rule 9); constructor injection `private readonly` only (rule 13); no placeholder code.
- Tests: PHPUnit by path with `RefreshDatabase` + `RolesAndPermissionsSeeder`; NEVER run the full suite (standing rule). Frontend: Vitest by path.
- Every task ends: `cd apps/api && ./vendor/bin/phpstan analyse <touched paths> && ./vendor/bin/pint <touched paths> --test` clean, then commit.
- 🚦 **HARD GATE** after each wave: `treasury-reviewer` (Opus) adversarial pass on the wave's diff; REJECT ⇒ fix before the next wave. Human merges only.
- ⛔ **Pre-build gate (owner):** §4.1 chart codes (`403`/`4035`) are provisional pending expert-comptable confirmation. Waves 1–4 may build; do not merge to dev until confirmed.

## File Structure

```
apps/api/app/Modules/Treasury/
├── Domain/Enums/InstrumentAccountPurpose.php          (modify: +2 cases)
├── Domain/Enums/InstrumentStatus.php                  (no change — statuses reused)
├── Domain/PaymentInstrument.php                       (modify: +presentation_cycle fillable/cast)
├── Application/Services/InstrumentAccountResolver.php (modify: +2 match arms)
├── Application/Services/OutboundInstrumentService.php (create — the ⑤a state machine)
├── Application/DTOs/OutboundTransitionResult.php      (create)
├── Presentation/Controllers/PaymentController.php     (modify: guard + issue JE)
├── Presentation/Controllers/InstrumentLifecycleController.php (modify: outbound routes)
├── Presentation/Console/ReconcileTreasuryCommand.php  (modify: check-4 outbound)
├── Presentation/Console/InstrumentMaturityAlertsCommand.php   (verify/extend)
└── routes.php                                         (modify: outbound actions)
apps/api/database/migrations/tenant/
└── 2026_07_18_100000_add_presentation_cycle_to_payment_instruments.php (create)
apps/api/database/seeders/{Tunisia,France,Generic}ChartOfAccountsSeeder.php (modify)
apps/api/app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php  (create)
apps/api/app/Modules/Expense/Application/Services/ExpenseService.php        (modify: instrument mode)
apps/web/src/features/treasury/…                       (Wave 4: pay-dialog mode + échéancier grouping)
```

---

## Wave 1 — Accounting foundation

### Task 1: Payable purposes + resolver + chart seeds + backfill

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Domain/Enums/InstrumentAccountPurpose.php`
- Modify: `apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:39-51`
- Modify: `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` (~:167-216 supplier block), `FranceChartOfAccountsSeeder.php` (403 exists ~:163-169 — add 4035 only), `GenericChartOfAccountsSeeder.php`
- Create: `apps/api/app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php`
- Test: `apps/api/tests/Feature/Treasury/PayableInstrumentAccountsTest.php`

**Interfaces:**
- Produces: `InstrumentAccountPurpose::ChecksToPay` (`'checks_to_pay'`), `InstrumentAccountPurpose::EffetsPayable` (`'effets_payable'`); resolver codes: EffetsPayable → `'403'` (all charts), ChecksToPay → `'4035'` (all charts); artisan `treasury:backfill-payable-instrument-accounts {--dry-run}` (idempotent, per-company, creates missing 403/4035 as liability-type accounts under the supplier parent). Later tasks call `resolveOrFail(InstrumentAccountPurpose::ChecksToPay|EffetsPayable, $companyId)`.

- [ ] **Step 1: Failing test**

```php
// tests/Feature/Treasury/PayableInstrumentAccountsTest.php
public function test_resolver_resolves_payable_purposes_for_tn_company(): void
{
    // seed a TN company + chart via TunisiaChartOfAccountsSeeder
    $resolver = app(InstrumentAccountResolver::class); // tests may use app(); prod code may not
    self::assertNotNull($resolver->resolve(InstrumentAccountPurpose::EffetsPayable, $this->company->id));
    self::assertNotNull($resolver->resolve(InstrumentAccountPurpose::ChecksToPay, $this->company->id));
}
public function test_resolved_payable_accounts_are_liability_type(): void { /* assert accounts.type = liability for both codes */ }
public function test_resolve_or_fail_throws_when_chart_lacks_payable_accounts(): void
{ $this->expectException(MissingInstrumentAccountException::class); /* company with gutted chart */ }
public function test_backfill_command_is_idempotent_and_creates_missing_accounts(): void
{ /* delete 4035; run command twice; assert exactly one 4035 liability account exists */ }
```

- [ ] **Step 2:** Run `./vendor/bin/phpunit tests/Feature/Treasury/PayableInstrumentAccountsTest.php` → FAIL (cases undefined).
- [ ] **Step 3:** Add the two enum cases; add match arms in `accountCode()` (`ChecksToPay => '4035'`, `EffetsPayable => '403'` — no TN conditional needed; keep arms explicit, no `default`). Seed `403 — Fournisseurs, effets à payer` (TN/Generic; FR has it) and `4035 — Fournisseurs, chèques à payer` (all three) as **liability** accounts in the supplier block, mirroring each seeder's existing row shape exactly. Backfill command: iterate companies, insert missing accounts by code idempotently (`WHERE NOT EXISTS` form), `--dry-run` prints; follows the pattern of the existing productized backfills (see the seeder-optional-Company trap: resolve companies explicitly, never rely on a nullable `Company` param).
- [ ] **Step 4:** Tests PASS. PHPStan/Pint clean on touched paths.
- [ ] **Step 5:** Commit `feat(treasury): payable instrument purposes (ChecksToPay/EffetsPayable) + chart seeds + backfill`.

### Task 2: `presentation_cycle` column + model

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_18_100000_add_presentation_cycle_to_payment_instruments.php`
- Modify: `apps/api/app/Modules/Treasury/Domain/PaymentInstrument.php` (fillable/cast; docblock)
- Test: `apps/api/tests/Feature/Treasury/OutboundInstrumentServiceTest.php` (started here, grown in Wave 2)

**Interfaces:**
- Produces: `payment_instruments.presentation_cycle` int NOT NULL default 1 — the `{cycle}` in idempotency keys `instrument:{id}:clear:{cycle}` / `:bounce:{cycle}`; incremented ONLY on re-presentation (`Bounced → Cleared` attempt).

- [ ] **Step 1:** Failing test: create instrument → `presentation_cycle === 1`.
- [ ] **Step 2:** Migration (`integer('presentation_cycle')->default(1)` on `payment_instruments`), model cast `'presentation_cycle' => 'integer'`. Self-guarding (additive, no backfill needed — default covers brownfield).
- [ ] **Step 3:** PASS; commit `feat(treasury): presentation_cycle counter on payment_instruments`.

🚦 **GATE 1 — treasury-reviewer (Opus)** on Wave 1 diff. Focus: liability typing, seeder-shape fidelity, backfill idempotency, enum exhaustiveness (PHPStan `match` coverage).

---

## Wave 2 — Outbound state machine

### Task 3: `OutboundInstrumentService::clear()` (Received → Cleared)

**Files:**
- Create: `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php`
- Create: `apps/api/app/Modules/Treasury/Application/DTOs/OutboundTransitionResult.php`
- Test: `apps/api/tests/Feature/Treasury/OutboundInstrumentServiceTest.php`

**Interfaces:**
- Consumes: `InstrumentAccountResolver::resolveOrFail()`, `GeneralLedgerService::postEntryNow()` (trace exact signature at `GeneralLedgerService.php` before use — the spine's synchronous path), `TreasuryMovementServiceInterface::record()`, Task 1 purposes, Task 2 cycle.
- Produces (used by Tasks 4-8 and ⑤b tier-3):

```php
final readonly class OutboundInstrumentService
{
    public function __construct(
        private InstrumentAccountResolver $accounts,
        private GeneralLedgerService $ledger,
        private TreasuryMovementServiceInterface $movements,
        private DatabaseManager $database,
        private CurrencyScaleResolverInterface $scale,
    ) {}
    /** Received→Cleared: Dr payable-purpose / Cr repository.gl_account_id + movement(out). */
    public function clear(string $instrumentId, string $tenantId, string $companyId, string $userId, ?string $occurredAt = null): OutboundTransitionResult;
    /** Cleared→Bounced: reversal JE + compensating movement(in, reverses_movement_id). AP stays closed. */
    public function bounce(string $instrumentId, string $tenantId, string $companyId, string $userId, ?string $reason = null): OutboundTransitionResult;
    /** Bounced→Cleared: increments presentation_cycle, then clear() semantics on the new cycle. */
    public function represent(string $instrumentId, string $tenantId, string $companyId, string $userId): OutboundTransitionResult;
    /** Received|Bounced→Cancelled: issue-reversal JE + atomic subledger reopen (§4.3). */
    public function cancel(string $instrumentId, string $tenantId, string $companyId, string $userId, string $reason): OutboundTransitionResult;
}
// OutboundTransitionResult: instrumentId, fromStatus, toStatus, journalEntryId, movementId (nullable), replayed(bool)
```

**Shared per-action skeleton (all four actions):** one `DB::transaction`; `lockForUpdate` instrument (tenant+company-scoped) → assert `direction === Outbound` (else canonical domain error, mirroring the inbound guards' error string style at `InstrumentLifecycleService.php:196-204`) → assert transition listed in the §4.3 table (else `InvalidInstrumentTransitionException`) → **repository contract** (Task 5 helper): active `RepositoryType::BankAccount`, non-null `gl_account_id`, currency match, consistent `bank_id` → idempotency: SELECT the action's JE/movement by deterministic key BEFORE posting; exact replay ⇒ return `replayed: true` with original ids; mismatch ⇒ throw → post JE via `postEntryNow()` → movement via port (where the table says so) → status update + timestamps → audit event.

- [ ] **Step 1: Failing tests** (project conventions: `RefreshDatabase`, real seeders, `CompanyContext` cleared where projections apply — rule 20):

```php
public function test_clear_posts_dr_payable_cr_bank_and_out_movement(): void
{ /* issue an outbound cheque instrument (helper: registerOutboundInstrument()); clear; assert JE lines:
     Dr 4035-account == amount, Cr repository gl_account == amount; movement direction=out,
     source_type=instrument, idempotency_key "instrument:{id}:clear:1"; status Cleared */ }
public function test_clear_replay_returns_original_and_posts_nothing_new(): void
{ /* call clear twice; second: replayed=true, same JE+movement ids, JE count unchanged */ }
public function test_clear_rejects_inbound_instrument(): void
public function test_clear_rolls_back_completely_when_gl_post_fails(): void
{ /* bind a GL failure (e.g. gut the payable account to trigger resolveOrFail throw AFTER lock);
     assert: status still Received, zero movements, zero JEs — nothing survives */ }
public function test_clear_rejects_cash_register_repository(): void
```

- [ ] **Step 2:** FAIL. **Step 3:** implement `clear()` + skeleton + `InvalidInstrumentTransitionException` (Domain/Exceptions). **Step 4:** PASS. **Step 5:** Commit `feat(treasury): outbound instrument clearing (Received→Cleared)`.

### Task 4: `bounce()` + `represent()` (Cleared→Bounced→Cleared)

**Files/Test:** same as Task 3.

- [ ] **Step 1: Failing tests:**

```php
public function test_bounce_posts_reversal_and_compensating_in_movement(): void
{ /* clear then bounce; assert JE: Dr repository gl_account / Cr payable-purpose;
     movement in with reverses_movement_id = clear movement id; key "instrument:{id}:bounce:1";
     status Bounced; AP (401) balance UNCHANGED by bounce */ }
public function test_represent_increments_cycle_and_clears_on_new_key(): void
{ /* bounce then represent; presentation_cycle=2; new movement key "...:clear:2"; status Cleared;
     original cycle-1 JEs/movements untouched */ }
public function test_bounce_then_represent_then_bounce_uses_cycle_2_key(): void
public function test_bounce_rejects_from_received(): void  // transition-table exhaustiveness
```

- [ ] **Steps 2-5:** red → implement → green → commit `feat(treasury): outbound bounce + re-presentation cycles`.

### Task 5: `cancel()` — atomic subledger reopen

**Files:**
- Modify: `OutboundInstrumentService.php`
- Test: `apps/api/tests/Feature/Treasury/OutboundCancelReopenTest.php`

**Interfaces:**
- Consumes: the deferred-supplier issue artifacts created by `PaymentController` (`Payment` Completed + positive allocations + document `balance_due` reduction + optional paid status, `PaymentController.php:790-813,905-920`).
- Produces: cancel = ONE transaction that (lock order: instrument → payment → expense_metadata → allocations → documents → repository): posts issue-reversal JE (Dr payable-purpose / Cr AP 401), appends **negative allocations**, recomputes document `balance_due`/status, resets `expense_metadata` (`is_paid=false, paid_at/payment_* nulled`) when expense-linked, marks payment `Reversed`, records audit event; key `instrument:{id}:cancel`.

- [ ] **Step 1: Failing tests:**

```php
public function test_cancel_from_received_reopens_document_balance_atomically(): void
{ /* deferred-supplier payment allocated to a posted supplier invoice → cancel instrument;
     assert: negative allocation rows exist, document balance_due restored, document status
     back to posted/unpaid, payment status Reversed, JE Dr payable / Cr 401, NO movement */ }
public function test_cancel_from_bounced_allowed_from_cleared_rejected(): void
public function test_cancel_partial_failure_leaves_everything_untouched(): void
{ /* inject failure between JE post and allocation reversal (e.g. listener throwing on a
     test-only hook, or delete the document to violate FK) → whole txn rolls back */ }
public function test_cancel_replay_is_idempotent(): void
```

- [ ] **Steps 2-5:** red → implement → green → commit `feat(treasury): outbound cancel with atomic subledger reopen`.

🚦 **GATE 2 — treasury-reviewer (Opus)** on Wave 2 diff. Focus: transition-table exhaustiveness vs spec §4.3, rollback atomicity proofs, idempotency-before-GL ordering, lock order, no inbound-service reuse.

---

## Wave 3 — Writer convergence (issue path) + guards + reconcile

### Task 6: Deferred-supplier issue posting in `PaymentController`

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` (deferred-supplier block ~:757-813; movement guard ~:1096 `! $isDeferredCustomer`)
- Test: `apps/api/tests/Feature/Treasury/DeferredSupplierPaymentTest.php`

**Interfaces:**
- Produces: for `$isDeferredSupplier`: (a) movement guard becomes `! $isDeferredCustomer && ! $isDeferredSupplier` — **no movement at issue**; (b) issue JE = Dr AP(401 partner account, same resolution the supplier-payment path uses at `GeneralLedgerService.php:707-774`) / Cr payable-purpose by `kind` (`cheque→ChecksToPay`, `effet→EffetsPayable`), key `instrument:{instrument_id}:issue`, posted via `postEntryNow()` inside the existing transaction; (c) repository contract enforced at issue (reject non-BankAccount with 422 — extend the existing repository guard block ~:621-642, which requires GL account but not type).

- [ ] **Step 1: Failing tests:**

```php
public function test_deferred_supplier_payment_records_no_movement_and_posts_issue_je(): void
{ /* POST /payments deferred cheque supplier: repository balance unchanged, zero movements,
     JE Dr 401 / Cr 4035, instrument outbound Received linked to payment */ }
public function test_deferred_supplier_payment_rejects_cash_register_repository(): void   // 422
public function test_deferred_customer_and_immediate_supplier_paths_unchanged(): void      // regression
public function test_issue_je_idempotent_on_retry(): void  // replay Idempotency-Key header → no 2nd JE
```

- [ ] **Steps 2-5:** red → implement (minimal diff inside the existing transaction; do NOT restructure the controller) → green → commit `fix(treasury): deferred-supplier issue posts payable-instrument JE, no cash movement`.

### Task 7: Outbound HTTP actions + permission

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/InstrumentLifecycleController.php`, `apps/api/app/Modules/Treasury/routes.php`, permission seeder (locate `instruments.*` block in `RolesAndPermissionsSeeder`)
- Test: `apps/api/tests/Feature/Treasury/OutboundInstrumentEndpointsTest.php`

**Interfaces:**
- Produces: `POST /api/v1/payment-instruments/{id}/clear-outbound|bounce-outbound|represent|cancel-outbound` → thin delegations to `OutboundInstrumentService`; middleware `['api','auth:sanctum',SetPermissionsTeam::class]` + `can:instruments.clear-outbound` (one new permission for all four actions — they are one operational capability; seeded to admin/owner). 403/404/422 envelopes match existing lifecycle endpoints.

- [ ] Steps: failing endpoint tests (happy path per action, permission 403, inbound instrument 422, invalid transition 422) → implement → green → commit `feat(treasury): outbound lifecycle endpoints + instruments.clear-outbound permission`.

### Task 8: Reconcile check-4 outbound + maturity alerts

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php:249-424`, `apps/api/app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php`
- Test: `apps/api/tests/Feature/Treasury/ReconcileOutboundPortfolioTest.php`

**Interfaces:**
- Produces: check 4 additionally compares Σ(outbound `Received`+`Bounced` instrument amounts by kind) vs GL balances of `ChecksToPay`/`EffetsPayable` accounts — alert-only, mirroring the inbound comparison's shape exactly. Alerts command: FIRST verify current outbound coverage (spec §11 Q6 — `MaturingInstrumentsController` already filters both directions); extend the command only if outbound rows are absent from its query; outbound alert copy keyed `treasury.maturity.outbound_due` ("must fund by").

- [ ] Steps: failing test (seed drifted outbound portfolio → alert emitted; clean → silent; inbound check regression) → implement → green → commit `feat(treasury): reconcile outbound portfolio check + maturity alert coverage`.

🚦 **GATE 3 — treasury-reviewer (Opus)** on Wave 3 diff. Focus: controller diff minimality, both regression suites (deferred-customer, immediate-supplier), permission gating on all four routes, alert-only invariant.

---

## Wave 4 — Expense pay-by-instrument + FE

### Task 9: Expense instrument mode

**Files:**
- Modify: `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php` (settle ~:445-573), `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:228-264`, its FormRequest
- Test: `apps/api/tests/Feature/Expense/ExpensePayByInstrumentTest.php`

**Interfaces:**
- Consumes: `OutboundInstrumentService`, `InstrumentLifecycleService::receive()` (`ReceiveInstrumentData` shape as used at `PaymentController:757-779`).
- Produces: `POST /expenses/{id}/pay` accepts `mode: 'instrument'` + `instrument: {kind, reference, bank_id?, maturity_date?, drawer_name?}`; settlement key stays `expense:{id}:settlement`; posts Dr AP / Cr payable-purpose, registers outbound instrument linked to the expense (store `expense_metadata` link — trace the metadata shape at the settle path first), **no movement**; `is_paid` stays FALSE until the instrument clears (clearing flips it inside `OutboundInstrumentService::clear()` when an expense link exists — extend Task 3's clear with this optional step). Cancel runs Task 5's reopen incl. metadata reset. LinkedCost rejection (`:468-478`) untouched.

- [ ] Steps: failing tests (instrument settle posts no movement + correct JE; clear flips `is_paid` and moves cash; cancel resets metadata; cash-mode settlement regression; LinkedCost still rejected) → implement → green → commit `feat(expense): pay-by-instrument settlement mode`.

### Task 10: FE — pay dialog mode + échéancier grouping

**Files:**
- Modify: expense pay dialog (locate via `expenses.pay` mutation usage in `apps/web/src/features/expenses/`), échéancier page consuming `MaturingInstrumentsController` (locate via `maturing` in `apps/web/src/features/treasury/`)
- Test: co-located Vitest files per feature convention

**Interfaces:**
- Produces: pay dialog gains mode toggle (cash/bank vs "by check / by effet") revealing instrument fields (reference, bank picker — reuse the shipped `BankPicker`, maturity date for effets, drawer name); payloads as strings via `MoneyInput` conventions; échéancier page splits direction sections ("Receivables" / "Payables — must fund by") using the existing `direction` filter param. All copy via `t()` with **FR + AR keys shipped now** (spec §7 — don't repeat the Phase-② AR debt); design tokens only; `tenantScopedKey` on new queries; types via `php artisan typescript:transform` after Task 9 DTO changes.

- [ ] Steps: failing Vitest (mode toggle renders instrument fields; submit payload shape; échéancier renders two direction groups from fixture) → implement → green → `pnpm lint && pnpm typecheck` on touched paths → commit `feat(web): expense pay-by-instrument mode + payables échéancier grouping`.

🚦 **GATE 4 — treasury-reviewer (Opus) + frontend-conventions-reviewer (Opus)** on Wave 4 diff. Then **⑤a exit review**: full-branch treasury-reviewer pass + E2E: deferred-supplier cheque issue → échéancier shows payable → clear → movement + balance drop → bounce → re-present → cancel-from-bounced on a second instrument → reconcile clean; expense instrument settle → clear flips paid.

---

## Self-review (done at write time)

- Spec §4.1→T1, §4.2→T6, §4.3→T3-5, §4.4→T8/T10, §4.5→T9, §9-⑤a tests distributed per task, §10 gates present. `{cycle}` produced (T2) before consumed (T3-4). `OutboundInstrumentService` signature consistent across T3-5/T9. Repository-contract helper introduced T3, reused T6 (via shared validation — implementer may extract to `Application/Services/Concerns/ValidatesOutboundRepository.php` if duplication >1 site).
- Known deliberate deltas: one permission for four outbound actions (operational unit); `is_paid` flip lives in `clear()` (single writer of that fact).
