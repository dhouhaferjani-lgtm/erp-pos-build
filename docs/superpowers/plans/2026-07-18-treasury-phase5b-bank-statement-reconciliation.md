# Treasury Phase ⑤b — Bank Statement Import & Reconciliation Implementation Plan (Rev 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **Spec:** [`../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`](../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md) §5–§8 (Rev 2). **Depends on plan ⑤a** — see Sequencing.
> **Rev 2 (2026-07-18):** reconciled against the three pre-dispatch reviews (Codex REJECT; treasury + tenancy-authz APPROVE-WITH-FIXES — files in `../reviews/`). Key deltas: signed-allocation equation; movement-row locking in stable ID order (first-allocation race); migration order fixed (profiles first); action-handler seam breaking the T5→T7 inversion; checkpoint guard covers `record()` AND `transfer()` with inclusive boundary + dedicated audit field; routing wave gets PaymentMethod model changes, resolver signature, replay stability + guarded backfill; parser registry (csv+xlsx dispatch); legacy cutover moved AFTER the replacement workspace with explicit FE file list; `ScopedExists::tenant` on all FK inputs; roles pinned (`admin`+`accountant`, `reopen`=admin-only); multi-location §3 = enforced merge gate; execution replay compares semantic digest.

**Goal:** Import bank statements (CSV/XLSX, per-bank saved mapping profiles), reconcile every line against `repository_movements` through a signed allocation model with immutable execution provenance, and make `payment_repositories.last_reconciled_at` a real, port-enforced external checkpoint.

**Architecture:** Treasury-native statement aggregate (5 tables). Matching is metadata (allocations); financial side effects happen only through recorded domain actions executed atomically with their allocations, dispatched through typed action handlers. Wave 0 ships payment-method→repository routing. Legacy `bank_reconciliations` is cut over only after the replacement workspace ships.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PHPUnit (pgsql, by-path), PHPStan L8; React 19 / TanStack Query 5 / RHF+zod / Vitest.

## Global Constraints

Everything in plan ⑤a's Global Constraints (worktree, money rules, builders+`postEntryNow`, port-only movements, routes-inside-existing-group + `EnforceTokenTenantClaim`, `{bankStatement}`/`{line}` route-model binding or `Str::isUuid` guards, module boundaries, per-task phpstan+pint+commit, no full suite, 🚦 Opus `treasury-reviewer` gate per wave, human merges). Additionally:
- **Matching is metadata, never money**; only `bank_statement_match_executions`-recorded actions post, atomically with their allocations.
- **Signed-sum convention (normative everywhere):** `contribution(allocation) = movement.direction == line.direction ? +matched_amount : −matched_amount`; `lineTotal = Σ contribution`. Completion requires `lineTotal == line.amount` (line amount positive; its own direction is the reference frame). Opposite-direction allocations are legal ONLY inside an execution-produced group (e.g. card fee) — a plain manual allocation with opposite direction is rejected unless the line already carries an execution for that group.
- **Movement-row locking:** any operation reading or asserting per-movement allocation totals first `lockForUpdate`s the affected `repository_movements` rows **in stable ID order** (locking allocation rows alone cannot serialize two FIRST allocations — empty result sets lock nothing). Completion locks every referenced movement row too.
- **FormRequests:** every `payment_repository_id` / `parser_profile_id` / `repository_movement_id` input validated with `ScopedExists::tenant(...)` (the shipped `bank_id` hardening pattern) + company scoping.
- **Permissions:** `bank-statements.view/import/reconcile` → `admin`+`accountant`; `bank-statements.reopen` → **admin only**. Deny-path tests use a non-privileged role.
- **Deploy notes (produce `docs/handoff/treasury-phase5b-deploy-checklist.md` in Wave 5):** 6-7 tenant migrations + `RolesAndPermissionsSeeder` re-run + `permission:cache-reset` per tenant + Horizon check (no new queues expected) — stacking on ⑤a + ③/④ owes.
- **Multi-location merge gate:** ⑤b's location column ships nullable-soft; **no by-location statement surface and no ⑤b merge to dev before the multi-location §3 package lands** (tracked dependency, not an external hope — the exit gate checks it).

## Sequencing (corrected)

- Parallel-safe with ⑤a: **Waves 0-1 only** (routing, schema, parser) on this branch while ⑤a Waves 1-2 run.
- **Wave 2 (routes+permissions) serializes AFTER ⑤a Task 7** — both edit `Presentation/routes.php` + `RolesAndPermissionsSeeder`.
- **Wave 3 requires ⑤a Tasks 3-5 shipped** (action handlers call `OutboundInstrumentService`); Task 7 additionally edits `ExpenseService` and serializes after ⑤a Task 9.
- Legacy cutover (Task 12) runs in Wave 5 AFTER the replacement workspace (Tasks 10-11).

## File Structure

As Rev 1, plus/corrected:
```
apps/api/app/Modules/Treasury/Application/Contracts/StatementActionHandlerInterface.php (create)
apps/api/app/Modules/Treasury/Application/Services/Actions/{OutboundClearHandler,InboundClearHandler,
  ExpenseSettleHandler,AcquirerFeeHandler,CreateExpenseHandler,CreateIncomeHandler}.php (create)
apps/api/app/Modules/Treasury/Application/Services/StatementParserRegistry.php (create — parser_key → implementation binding)
apps/api/app/Modules/Treasury/Application/Services/XlsxStatementParser.php    (create — CSV and XLSX are separate implementations)
apps/api/app/Modules/Treasury/Domain/PaymentMethod.php                        (modify — default_repository_id fillable/relation)
apps/api/app/Console/Commands/ConfigureMethodRepositoryRoutingCommand.php     (create — guarded mapping backfill)
Migration order (FK-safe): 2026_07_19_1100 00 statement_import_profiles → 01 bank_statements (+unique (id,payment_repository_id))
  → 02 bank_statement_lines → 03 bank_statement_line_allocations → 04 bank_statement_match_executions
routes: app/Modules/Treasury/Presentation/routes.php  (correct path — NOT app/Modules/Treasury/routes.php)
```

---

## Wave 0 — Payment-method → repository routing

### Task 1: `default_repository_id` + bridge resolution + guarded backfill

**Files:**
- Create: migration `2026_07_19_100000_add_default_repository_to_payment_methods.php`; `ConfigureMethodRepositoryRoutingCommand.php`
- Modify: `TreasuryReceiptBridge.php` (`resolveDefaultRepository:733` + its call site `:468`), `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php` (fillable/relation), `PaymentMethodController.php:98-139` (accept/expose field, `ScopedExists::tenant('payment_repositories')`)
- Test: `apps/api/tests/Feature/Treasury/PaymentMethodRepositoryRoutingTest.php`

**Interfaces:**
- Produces: nullable FK `payment_methods.default_repository_id` (nullOnDelete). **Resolver signature changes** to receive the already-resolved `PaymentMethod` per tender (the current method takes only `FiscalEvent` — it cannot see the method): `resolveRepositoryForTender(FiscalEvent $event, ?PaymentMethod $method): ?PaymentRepository` — mapped+GL-linked ⇒ mapped repository; else today's first-GL-linked-ordered-by-id fallback. **Replay stability:** when the bridge re-processes an event whose `Payment` row already exists, it keeps the payment's stored `repository_id` — a later mapping change must never re-route history. Command `treasury:configure-method-routing {--card-to=} {--dry-run}`: guarded per-tenant mapping setter (explicit company iteration).

- [x] **Step 1: failing tests** — mapped CARD tender routes to mapped repo, CASH falls back; unmapped method regression (P2-6 deterministic fallback); **replay after mapping change keeps original repository**; projection test clears `CompanyContext`; command idempotent + dry-run.
- [x] Steps 2-5 → commit `feat(treasury): per-method default repository routing (replay-stable) + config command`.

🚦 **GATE 0 — treasury-reviewer + fiscal-pos-reviewer (Opus).** Fiscal projection touched: replay determinism is the whole gate.

---

## Wave 1 — Schema + parsers

### Task 2: Five tables + models + enums (FK-safe order)

**Files:** migrations in the **corrected order above** (profiles → statements → lines → allocations → executions; `bank_statements.parser_profile_id` FK is now creatable in-line), 7 enums, 5 models.
**Interfaces** (deltas from spec §5.1, all normative):
- `bank_statements`: + unique `(id, payment_repository_id)` (composite-FK anchor — valid pgsql); ownership FKs `tenant_id/company_id` indexed; `cascadeOnDelete` from statements → lines → allocations; executions `restrictOnDelete` (provenance immutable — statement void is blocked by executions anyway); profiles `nullOnDelete` on statements.
- `bank_statement_lines`: composite FK `(bank_statement_id, payment_repository_id)` → `bank_statements(id, payment_repository_id)`.
- `bank_statement_match_executions`: + `semantic_digest` varchar NOT NULL (canonical JSON hash: action_type, line id, target id, amount, value date, repository, method/fee params where applicable).
- State machines (spec §6.6) implemented as enum methods `canTransitionTo()` on statement + derived-only line status recompute helper.
- [x] Steps: failing constraint tests (each unique/CHECK/FK violation incl. cross-statement repository mismatch and **migration rollback order `migrate:rollback` clean**) → implement → commit `feat(treasury): bank statement aggregate schema`.

### Task 3: Parser port + registry + CSV & XLSX parsers

**Files:** `StatementParserInterface.php`, `StatementParserRegistry.php` (constructor-injected map `StatementParserKey → StatementParserInterface`; service-provider binding), `CsvStatementParser.php`, `XlsxStatementParser.php`, DTOs (`ParsedStatement`, `ParsedStatementLine`, `StatementColumnMap`), fixtures.
**Interfaces:** as Rev 1 DTO shapes, plus: registry dispatch tested for both keys; each behavior fixture-backed (preamble skip, Excel serial dates, configured date formats, locale decimals incl. `7.140` trap, signed vs debit/credit conventions, formula cells evaluated-or-unparseable, encoding/delimiter detection, zero-amount drop + count, canonical normalization for fingerprints — trim/collapse-whitespace/case-fold reference+label before hashing). `SpreadsheetParserService` (`:44-66,129-176` always header-first, string cells) is reused only if a raw-row entry point can be added WITHOUT changing its existing consumers; otherwise parse directly (plan Q4 resolved: prefer direct — no Import-module modification).
- [x] Steps: fixture-driven failing tests per behavior + registry dispatch + **two identical legitimate fees in one file get distinct occurrence indexes** → implement → commit `feat(treasury): statement parser registry + csv/xlsx parsers`.

🚦 **GATE 1 — treasury-reviewer (Opus).** Constraint completeness, FK/delete behavior, normalization matrix, registry binding.

---

## Wave 2 — Import flow + profiles API *(serialize after ⑤a Task 7)*

### Task 4: `StatementImportService` + endpoints + permissions

As Rev 1, corrected: routes inside the existing `Presentation/routes.php` group; `{bankStatement}` binding; `ScopedExists::tenant` on `payment_repository_id`+`parser_profile_id`; permission grants per Global Constraints; statement `Imported → Reconciling` transition is triggered by the FIRST allocate/ignore/execute call (owned by Task 5's service, asserted here in the state enum); void conditions (zero allocations AND zero executions); sha256 duplicate → 422 with existing statement id; zero-accepted-lines confirm needs `acknowledge_empty`; continuity warning non-blocking; cross-company profile/repository ownership tests.
- [x] Steps: failing feature tests (each behavior + 403 deny-path via `manager` + cross-company 422s + fingerprint-skip on overlapping import) → implement → commit `feat(treasury): statement import flow + profiles`.

🚦 **GATE 2 — treasury-reviewer + tenancy-authz-reviewer (Opus).** Middleware inheritance, grants, scoping, staging purity (zero GL/movement writes in this wave).

---

## Wave 3 — Matching engine *(requires ⑤a Tasks 3-5; Task 7 after ⑤a Task 9)*

### Task 5: Action-handler seam + `StatementMatchingService`

**Files:** `StatementActionHandlerInterface.php` + the six handlers (thin, constructor-injected over `OutboundInstrumentService`, `InstrumentLifecycleService`, `ExpenseService`, `AcquirerFeeService` (Task 6), creation services), `StatementMatchingService.php`, `StatementLineController.php`.
**Interfaces:**

```php
interface StatementActionHandlerInterface {
    public function supports(MatchActionType $action): bool;
    /** Executes the domain action; returns produced movement ids. MUST be called inside the matcher's transaction. */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult; // {movementIds, targetType, targetId, semanticDigest}
}
final readonly class StatementMatchingService {
    /** @param list<array{movementId: string, amount: string}> $allocations */
    public function allocate(string $lineId, array $allocations, string $userId): void;
    public function unallocate(string $lineId, ?string $movementId, string $userId): void; // deletes allocations, NEVER executions
    public function ignore(string $lineId, StatementLineIgnoreReason $reason, string $text, string $userId): void;   // rejected if allocations/executions exist
    public function unignore(string $lineId, string $userId): void;                                                   // → Unmatched, recompute
    public function executeAndAllocate(string $lineId, MatchActionType $action, array $params, string $userId): void;
}
```

Semantics (all under one transaction per call): statement not `Reconciled`/`Voided`; first mutating call flips `Imported → Reconciling`; **lock affected `repository_movements` rows by stable ID order BEFORE reading allocation sums** (per Global Constraints — first-allocation race); movements belong to the line's repository; **signed-sum convention enforced** (opposite-direction manual allocation rejected without an execution group); per-line `lineTotal ≤ line.amount`, per-movement Σ(matched_amount) ≤ movement.amount; `match_status` recomputed in-transaction (`Partial`/`Matched`). `executeAndAllocate`: existing `action_key` ⇒ **compare `semantic_digest`** — match ⇒ reuse produced movements for allocation (never re-execute); mismatch ⇒ throw. `OutboundClear` dispatches `Received → clear()`, **`Bounced → represent()`** (⑤a contract). Handlers registered for `OutboundClear`/`InboundClear`/`ExpenseSettle` now; `AcquirerFee`/`CreateExpense`/`CreateIncome` handlers land in Task 7 — the registry tolerates unregistered actions with a clean domain error (breaks the T5→T7 dependency inversion).

- [x] **Step 1: failing tests** — signed sums (in-line with out-movement rejected manually; execution-group case deferred to T7); both-direction caps; **first-allocation race: two pgsql connections allocate the same movement concurrently → exactly one wins, no over-allocation**; unmatch keeps execution + re-confirm reuses; digest-mismatch replay throws; ignore-after-allocation rejected; unignore recompute; cross-repository rejected; `Imported→Reconciling` flip; matched statement blocks void.
- [x] Steps 2-5 → commit `feat(treasury): matching core (signed allocations + execution provenance + handler seam)`.

### Task 6: Suggestion engine tiers 1-3

As Rev 1, plus: **partially-allocated movements with remaining capacity stay suggestible** (exclusion is `remaining == 0`, not `∃ allocation`); tier-2 uniqueness respects remaining capacity; tier-3 `OutboundClear` candidates include `Bounced` (→ represent).
- [x] Steps: failing per-tier tests incl. partial-capacity suggestibility → implement → commit.

### Task 7: Create-from-line + `AcquirerFeeService` + card-batch tier 4 *(after ⑤a Task 9)*

As Rev 1, corrected: **`IncomeService::create()` takes an array, not a DTO** (`IncomeService.php:43`) — extend the array contract (explicit account id, occurred date, location) rather than inventing DTOs; expense creation extended for explicit `location_id`/value-date; `AcquirerFeeHandler` + `CreateExpenseHandler`/`CreateIncomeHandler` registered; **card grouping is per payment method per sale business day** (company timezone) — two card methods on one repository/day are NEVER netted together; refunds/chargebacks negative members; multi-day/partial → manual; fee plausibility from that method's fee config; fee movement joins the allocation group with the **signed convention closing `gross − fee == net`** (the T5-deferred test lands here).
- [x] Steps: failing tests (fee JE/movement shape; value-date propagation + checkpoint rejection; income account override array; midnight-boundary + two-methods-same-day grouping; fee bounds; unmatch/rematch single fee via digest reuse) → implement → commit. *(Value-date propagation landed here; the binding H7 correction keeps checkpoint rejection with Task 8's both-port guard.)*

🚦 **GATE 3 — treasury-reviewer (Opus), high effort.** The financial heart: metadata/money separation, signed math, digest replay, locking under concurrency.

---

## Wave 4 — Completion + checkpoint

### Task 8: `StatementCompletionService` + port checkpoint guard (both writes)

As Rev 1, corrected/extended:
- Completion lock order: statement → lines → allocations/executions → **every referenced `repository_movements` row (stable ID order)** → repository row; all sums revalidated under those locks; per-repository in-period-order completion; ignored-total acknowledgment path (`bank-statements.reopen` perm).
- **Checkpoint = end-of-day of `period_end`** (persist `last_reconciled_at` as `period_end 23:59:59.999999` company-timezone, or compare on date-truncation — pick ONE, test the boundary: a movement occurred ON the period_end date is INSIDE the reconciled window and rejected).
- **Port guard in BOTH `record()` AND `transfer()`** (`TreasuryMovementService.php:46-95` + the transfer path; `TransferIntent` carries occurredAt — verify at `TransferIntent.php:26`): after repository lock, after effective-occurrence resolution, before savepoint. Interactive ⇒ canonical domain error; projection ⇒ record + **new dedicated field `recorded_behind_checkpoint` bool default false** on `repository_movements` (additive migration — do NOT repurpose `recorded_while_frozen`) + alert. **Regression tests for every existing value-dated port caller** (inbound clear/bounce pass `occurredAt` = value date `InstrumentLifecycleService:279/:497`; payments; transfers) against a reconciled period.
- Reopen: `bank-statements.reopen` (admin-only), audited, no-gap rule, checkpoint recompute.
- [x] Steps: failing tests (concurrent match-vs-complete; completion racing an allocation from ANOTHER statement on a shared movement; out-of-order completion/reopen; boundary-date rejection; transfer backdating rejected; projection flag+alert; every regression caller) → implement → commit `feat(treasury): race-safe completion + checkpoint enforced in record() and transfer()`.

### Task 9: `treasury:reconcile` statement checks

Alert-only §6.7 checks (tampered reconciled statement; stale unreconciled > 30d) — **legacy cutover moved to Task 12**.
- [x] Steps: failing → implement → commit.

🚦 **GATE 4 — treasury-reviewer (Opus).** Lock-order consistency with Wave 3, boundary semantics, no-freeze invariant, port regressions.

---

## Wave 5 — UI + cutover + E2E

### Task 10: Statement list + upload wizard — as Rev 1 (tokens, FR+AR, `tenantScopedKey`, mapping-pattern copy). [x]
### Task 11: Reconciliation workspace — as Rev 1 (suggestions, partial `MoneyInput` allocation, create-from-line, ignore/unignore, provenance display, completion CTA + acknowledgment, reopen behind permission, instrument/expense cross-link chips).

### Task 12: Legacy cutover *(only after Tasks 10-11 ship)*

**Files:**
- Modify: `Presentation/routes.php:249-279` (remove mutation routes; keep index/show read-only only if still consumed), delete `apps/web/src/features/treasury/api/reconciliation.ts`, `apps/web/src/features/treasury/BankReconciliationPage.tsx` + its tests + `types/treasury.ts` entries + FR/AR/EN i18n keys; **repoint `apps/web/src/features/finance/pages/FinanceHubPage.tsx:75`** (`/treasury/reconciliation` card) to the new workspace route; prune `tenantScope.test.tsx` references.
- Test: backend route-removal tests (mutation routes + `summary` → 404); **Vitest asserting the FinanceHub card resolves to the live workspace route**.
- [ ] Steps: failing → cutover → commit `feat(treasury): legacy bank-reconciliation cutover → statement workspace`.

### Task 13: Playwright E2E + ⑤b exit

Full spec §9 flow (upload → preview reports → import → tier-1 confirm → tier-3 outbound clear → tier-4 card batch + fee → create-from-line agio → ignore+acknowledge → complete → checkpoint stamped → backdated adjustment rejected → reconcile clean).

🚦 **GATE 5 — treasury-reviewer + frontend-conventions-reviewer (Opus); ⑤b exit = full-branch review (CRITICAL — Fable arbitration only on reviewer deadlock, ask owner first). Exit checklist includes: multi-location §3 landed (merge gate), deploy checklist produced. Chart codes are seeder-owned and final for this build (owner ruling 2026-07-18 — no accounting sign-off gates the exit).**

---

## Self-review (Rev 2)

- Codex fixes traced: B3→Global-Constraints signed convention + T5/T7; B4→movement-row locking (Global + T5 + T8); B5→migration order; H6→handler seam + Bounced→represent; H7→T8 both-writes + boundary + `recorded_behind_checkpoint`; H8→T1 (model, resolver signature, replay stability, command); H9→T3 registry/XLSX + T7 array contract; H11→`semantic_digest` (T2+T5); H12→exit merge gate; MED→`Imported→Reconciling` (T4/T5), unignore (T5), per-method grouping (T7), partial-capacity suggestions (T6), ownership/delete enumeration (T2). Tenancy fixes: middleware/binding/ScopedExists/grants/deploy-notes/cutover-ordering (Global, T4, T12). Treasury MED-7→T8 regressions + dedicated field.
- Failure-mode test list from the Codex review distributed: T2 (rollback, FK violations), T3 (occurrence, normalization, dispatch), T5 (race, digest, ignore conflicts), T7 (two methods/day, fee bounds), T8 (shared-movement race, boundary, transfer), T12 (routes incl. `summary`).
