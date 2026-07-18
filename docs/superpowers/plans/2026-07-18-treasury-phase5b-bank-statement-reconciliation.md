# Treasury Phase ⑤b — Bank Statement Import & Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **Spec:** [`../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`](../specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md) §5–§8 (Rev 2). **Depends on plan ⑤a** (tier-3 outbound clearing consumes `OutboundInstrumentService::clear()`); Waves 0–2 here may run in parallel with ⑤a Waves 3–4.

**Goal:** Import bank statements (CSV/Excel, per-bank saved mapping profiles), reconcile every line against `repository_movements` through an allocation model with immutable execution provenance, and make `payment_repositories.last_reconciled_at` a real, port-enforced external checkpoint.

**Architecture:** Treasury-native statement aggregate (5 new tables). Matching is metadata (allocations); financial side effects happen only through recorded domain actions (outbound/inbound clear, expense settle, acquirer fee, create-from-line) executed in the same transaction as their allocations. Wave 0 ships the payment-method→repository routing the card tier requires. Legacy `bank_reconciliations` mutation routes are removed at cutover.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PHPUnit (pgsql, by-path), PHPStan L8; React 19 / TanStack Query 5 / RHF+zod / Vitest; `SpreadsheetParserService` reused as a library only.

## Global Constraints

Same as plan ⑤a (worktree, money rules, `postEntryNow()`, port-only movements, enums, DI, per-task phpstan+pint+commit, no full suite, 🚦 Opus `treasury-reviewer` hard gate per wave, human merges). Additionally:
- **Matching is metadata, never money** — no task may post GL or movements from an allocation write; only `bank_statement_match_executions`-recorded actions do, atomically with their allocations.
- Sum rule invariants (spec §6.1) are validated **under lock** wherever asserted.
- All new routes: `['api','auth:sanctum',SetPermissionsTeam::class]` + `can:bank-statements.*`.
- FE: design tokens, FR+AR i18n in-phase, `tenantScopedKey`, `MoneyInput`/`formatCurrency`, no parseFloat on money.

## File Structure

```
apps/api/app/Modules/Treasury/
├── Domain/BankStatement.php · BankStatementLine.php · BankStatementLineAllocation.php
│   · BankStatementMatchExecution.php · StatementImportProfile.php        (create)
├── Domain/Enums/BankStatementStatus.php · StatementLineMatchStatus.php
│   · StatementLineIgnoreReason.php · MatchType.php · MatchActionType.php
│   · StatementParserKey.php · StatementDirectionConvention.php           (create)
├── Application/Contracts/StatementParserInterface.php                    (create)
├── Application/DTOs/ParsedStatement.php · ParsedStatementLine.php
│   · StatementColumnMap.php                                              (create)
├── Application/Services/CsvStatementParser.php                           (create)
├── Application/Services/StatementImportService.php                       (create)
├── Application/Services/StatementMatchingService.php                     (create — allocations+executions)
├── Application/Services/StatementSuggestionEngine.php                    (create — 4 tiers, read-only)
├── Application/Services/StatementCompletionService.php                   (create — §6.5)
├── Application/Services/AcquirerFeeService.php                           (create — §6.4)
├── Presentation/Controllers/BankStatementController.php
│   · StatementLineController.php · StatementProfileController.php        (create)
├── Presentation/Console/ReconcileTreasuryCommand.php                     (modify — §6.7 checks)
└── routes.php                                                            (modify; remove legacy mutation routes :249-279)
apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php (modify — Wave 0 routing)
apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php  (modify — checkpoint guard)
apps/api/database/migrations/tenant/  (5 creates + payment_methods.default_repository_id)
apps/web/src/features/treasury/statements/  (create — list, wizard, workspace)
```

---

## Wave 0 — Payment-method → repository routing (tier-4 prerequisite; the in-code Phase-1.5 item)

### Task 1: `payment_methods.default_repository_id` + bridge resolution

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_19_100000_add_default_repository_to_payment_methods.php`
- Modify: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:712-741` (`resolveDefaultRepository`), `PaymentMethodController.php:98-139` (accept/expose the field)
- Test: `apps/api/tests/Feature/Treasury/PaymentMethodRepositoryRoutingTest.php`

**Interfaces:**
- Produces: nullable FK `payment_methods.default_repository_id → payment_repositories` (nullOnDelete). Bridge resolution becomes: per tender, `method.default_repository_id` when set and GL-linked → else today's first-GL-linked-ordered-by-id fallback (unchanged behavior for unmapped methods and single-repo tenants). Projection context: NO CompanyContext (rule 20) — resolve via explicit tenant/company ids exactly as the current query does.

- [ ] **Step 1: Failing tests:** mapped CARD method routes its tender's movement to the mapped repository while CASH falls back; unmapped method keeps today's deterministic fallback (regression, cite the P2-6 comment behavior); projection test clears `CompanyContext`.
- [ ] **Steps 2-5:** red → migration + bridge lookup + controller field (validated `ScopedExists::tenant('payment_repositories')` pattern — same as the shipped `bank_id` hardening) → green → commit `feat(treasury): per-method default repository routing in receipt bridge`.

*(No historical-movement backfill: movements are immutable; the matcher treats pre-wave card movements as tier-2/manual candidates — spec §6.2.)*

🚦 **GATE 0 — treasury-reviewer + fiscal-pos-reviewer (Opus)** — this touches a fiscal projection. Focus: replay determinism, no CompanyContext, fallback regression.

---

## Wave 1 — Schema + parser

### Task 2: The five tables + models + enums

**Files:**
- Create: 5 tenant migrations `2026_07_19_11000{0..4}_create_{bank_statements,bank_statement_lines,bank_statement_line_allocations,bank_statement_match_executions,statement_import_profiles}_table.php`; 7 enums; 5 models
- Test: `apps/api/tests/Feature/Treasury/BankStatementSchemaTest.php`

**Interfaces:**
- Produces: exactly the spec §5.1 columns/constraints. Load-bearing constraints (name them in the migrations so tests can assert): `bank_statements` unique `(payment_repository_id, source_file_sha256)`; `bank_statement_lines` unique `(bank_statement_id, line_number)` + unique `(payment_repository_id, fingerprint)` + `CHECK (amount > 0)`; allocations unique `(bank_statement_line_id, repository_movement_id)` + `CHECK (matched_amount > 0)`; executions unique `action_key`. Line `payment_repository_id` integrity: composite FK `(bank_statement_id, payment_repository_id)` referencing a unique `(id, payment_repository_id)` index on `bank_statements` (pgsql supports this; simpler than a trigger). Statement status enum WITHOUT `Draft` (`Imported/Reconciling/Reconciled/Voided`); line status `Unmatched/Partial/Matched/ResolvedByCreation/Ignored`.

- [ ] Steps: failing schema-assertion tests (constraint violations throw: duplicate fingerprint, duplicate file hash, zero amount, over-unique allocation pair, cross-statement repository mismatch) → migrations+models (fillable/casts/relations; `decimal:3` string casts on money) → green → commit `feat(treasury): bank statement aggregate schema (5 tables)`.

### Task 3: Parser port + profile-aware CSV/Excel parser

**Files:**
- Create: `StatementParserInterface.php`, `ParsedStatement.php`, `ParsedStatementLine.php`, `StatementColumnMap.php` (typed DTO for `column_map` JSONB), `CsvStatementParser.php`
- Test: `apps/api/tests/Unit/Treasury/CsvStatementParserTest.php` + fixtures `tests/Fixtures/statements/{biat_semicolon.csv, amen_debit_credit.csv, portal_preamble.xlsx, locale_decimals.csv}`

**Interfaces:**
- Produces: `parse(string $storedFilePath, StatementImportProfile $profile): ParsedStatement`. `ParsedStatementLine`: `lineNumber:int, valueDate:string(Y-m-d), bookingDate:?string, direction:MovementDirection, amount:string, reference:?string, bankTransactionId:?string, label:string, counterpartyHint:?string`. `ParsedStatement`: `lines:list<ParsedStatementLine>, droppedZeroAmountRows:int, unparseableRows:list<{row:int,reason:string}>, detectedOpening:?string, detectedClosing:?string`. Required behaviors (spec §5.2, each a fixture-backed test): `header_rows` preamble skip; configured `date_format` incl. Excel serial dates; `decimal_format` normalization (`1 234,56`, `1.234,56`, `7.140`-thousands trap); `direction_convention` signed-single vs debit/credit columns; formula cells → evaluated value or unparseable-row (never raw formula string); encoding/delimiter detection for the CSV path; zero-amount rows dropped+counted. Uses `SpreadsheetParserService` (or its underlying library) strictly as a library — trace `:44-66,129-176` first and prefer a raw-row entry point; if none exists without modification, implement raw-row reading in `CsvStatementParser` directly (spec §11 Q4 — do NOT add statement types to `ImportType`).

- [ ] Steps: fixture-driven failing unit tests per behavior → implement → green → commit `feat(treasury): profile-aware statement parser (csv/xlsx)`.

🚦 **GATE 1 — treasury-reviewer (Opus)**. Focus: constraint completeness vs §5.1, DTO string-money discipline, parser normalization matrix vs fixtures.

---

## Wave 2 — Import flow + profiles API

### Task 4: `StatementImportService` + upload/preview/confirm/void endpoints

**Files:**
- Create: `StatementImportService.php`, `BankStatementController.php`, `StatementProfileController.php`, FormRequests; permission seeder additions (`bank-statements.view/import/reconcile/reopen`)
- Modify: `routes.php`
- Test: `apps/api/tests/Feature/Treasury/StatementImportFlowTest.php`

**Interfaces:**
- Produces: `POST /bank-statements/upload` (file + repository + profile → stored file + preview payload incl. dropped/duplicate/unparseable reports, sha256-duplicate → 422 with existing statement id; NOTHING persisted); `POST /bank-statements` (confirm: statement+lines one transaction, `Imported`; zero-accepted-lines → 422 unless `acknowledge_empty:true`; duplicate-fingerprint lines skipped+reported; hard guard statement currency == repository currency; repository must be BankAccount); `POST /bank-statements/{id}/void` (only when zero allocations AND zero executions); statement list/show; profile CRUD (company-scoped, repository-bound). Continuity warning (opening vs previous reconciled closing) returned in confirm response, never blocking.

- [ ] Steps: failing feature tests (each behavior above + permission 403s + fingerprint-skip on overlapping second import) → implement → green → commit `feat(treasury): statement import flow (upload/preview/confirm/void) + profiles`.

🚦 **GATE 2 — treasury-reviewer + tenancy-authz-reviewer (Opus)**. Focus: route middleware + permission coverage, company scoping on profiles/statements, staging purity (zero GL/movement writes anywhere in Wave 2).

---

## Wave 3 — Matching engine

### Task 5: `StatementMatchingService` — allocations + executions core

**Files:**
- Create: `StatementMatchingService.php`, `StatementLineController.php` (match/unmatch/ignore endpoints)
- Test: `apps/api/tests/Feature/Treasury/StatementMatchingTest.php`

**Interfaces:**
- Produces:

```php
final readonly class StatementMatchingService
{
    /** @param list<array{movementId: string, amount: string}> $allocations */
    public function allocate(string $lineId, array $allocations, string $userId): void;   // metadata only
    public function unallocate(string $lineId, ?string $movementId, string $userId): void; // deletes allocations, NEVER executions
    public function ignore(string $lineId, StatementLineIgnoreReason $reason, string $text, string $userId): void;
    /** Executes a domain action atomically with its allocation; records execution; replay-safe. */
    public function executeAndAllocate(string $lineId, MatchActionType $action, array $params, string $userId): void;
}
```

Under one locked transaction per call (lock order: statement → line → allocations/executions → movement rows): statement not `Reconciled`/`Voided`; movements belong to the line's repository; per-line Σ ≤ signed line amount; **per-movement Σ across ALL lines ≤ movement amount** (query under `lockForUpdate` on the movement's allocation rows — concurrency test required); `match_status` recomputed in-transaction (`Partial` vs `Matched`). `executeAndAllocate`: unique `action_key` check first — existing execution ⇒ reuse its produced movement(s) for allocation, never re-execute; action dispatch: `OutboundClear → OutboundInstrumentService::clear()` (⑤a), `InboundClear → InstrumentLifecycleService::clear()` (existing, `:196-322`), `ExpenseSettle → ExpenseService::settle()`, `AcquirerFee → AcquirerFeeService` (Task 7), `CreateExpense/CreateIncome` (Task 7). Action failure ⇒ no allocation; allocation failure ⇒ action rolled back (same transaction).

- [ ] Steps: failing tests (sum caps both directions incl. two-lines-one-movement over-allocation race with parallel transactions; unmatch keeps execution + re-confirm reuses it — exactly one fee expense after unmatch/rematch; ignore requires reason; cross-repository rejected; matched statement blocks void) → implement → green → commit `feat(treasury): statement matching core (allocations + execution provenance)`.

### Task 6: `StatementSuggestionEngine` — tiers 1-3

**Files:**
- Create: `StatementSuggestionEngine.php`; suggestions endpoint on `StatementLineController`
- Test: `apps/api/tests/Unit/Treasury/StatementSuggestionEngineTest.php`

**Interfaces:**
- Produces: `suggest(BankStatementLine $line): list<Suggestion>` where `Suggestion = {tier:int, kind:'movement'|'pending_outbound'|'pending_inbound'|'pending_expense'|'card_batch', targets:list<...>, confidence:'exact'|'probable'}` — computed on demand, never persisted. Tier 1: reference/instrument-number/`bank_transaction_id` hit in `label|reference` + equal amount over unreconciled movements of the repository (unreconciled = movement id absent from allocations). Tier 2: unique equal-amount movement in ±5-day value-date window (window from profile). Tier 3 pending events: outbound instruments `Received/Bounced` with maturity within window + equal amount; deposited inbound instruments/remittance lines awaiting clear; unsettled expenses on this repository (amount + `expense_metadata` date/vendor hints, `ExpenseService` join shape).
- [ ] Steps: failing unit tests per tier (incl. tier-2 NON-unique → no suggestion; tier ordering) → implement → green → commit `feat(treasury): suggestion engine tiers 1-3`.

### Task 7: Create-from-line + acquirer fee action + card-batch tier 4

**Files:**
- Create: `AcquirerFeeService.php`; extend `StatementSuggestionEngine` (tier 4), `StatementMatchingService` params, `ExpenseService`/income-creation DTO extensions (explicit `location_id`, `occurred_at`, account override — spec §6.3, anchors `ExpenseService.php:106-136,535-565`, `GeneralLedgerService.php:3493-3505`)
- Test: `apps/api/tests/Feature/Treasury/CreateFromLineTest.php`, `AcquirerFeeTest.php`, `CardBatchSuggestionTest.php`

**Interfaces:**
- Produces: **CreateExpense/CreateIncome** via `executeAndAllocate`: normal flows with extended DTOs — document/JE/movement dates = line **value date** (period/checkpoint-guarded), `location_id` = line's, income account explicit (financial-income, never `ProductRevenue` default); line → `ResolvedByCreation`. **AcquirerFeeService**: Dr `payment_methods.fee_account_id` (VAT per Phase-④ rules; plan-time TN confirmation per spec §11 Q2 — implement VAT-exempt default with a config seam) / Cr statement repository `gl_account_id`, movement `out`, `postEntryNow()`, key `stmtline:{line_id}:acquirer_fee`. **Tier 4**: card movements (source fiscal_event, card methods) grouped by sale business day in company timezone; refunds/chargebacks negative members; suggested when `0 < gross − line ≤ maxPlausibleFee(payment_method fee config)`; multi-day/partial → no suggestion (manual); requires the method to have `has_deducted_fees=true` + `fee_account_id` set.
- [ ] Steps: failing tests (fee JE/movement shape; value-date propagation incl. rejection behind checkpoint; income account override; batch grouping across a midnight boundary; fee-bound exclusion; unmatch/rematch single fee) → implement → green → commit `feat(treasury): create-from-line, acquirer fee action, card-batch tier`.

🚦 **GATE 3 — treasury-reviewer (Opus), high effort** — this wave is the financial heart. Focus: metadata/money separation, execution replay, both-direction sum caps under concurrency, fee accounting, value-date discipline.

---

## Wave 4 — Completion, checkpoint, reconcile, legacy cutover

### Task 8: `StatementCompletionService` + checkpoint + port enforcement

**Files:**
- Create: `StatementCompletionService.php` (+ complete/reopen endpoints)
- Modify: `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:46-95` (checkpoint guard)
- Test: `apps/api/tests/Feature/Treasury/StatementCompletionTest.php`, `MovementCheckpointGuardTest.php`

**Interfaces:**
- Produces: **complete**: one lock order (statement → lines → allocations/executions → repository row), revalidate ALL sums under lock, require every line resolved, Σ(signed non-ignored lines) == closing−opening, non-zero-ignored acknowledgment path requires `bank-statements.reopen` permission + audit payload with ignored total; per-repository **in-period-order** completion (earlier unreconciled statement exists ⇒ 422); stamps `last_reconciled_at = period_end`, `last_reconciled_balance = closing_balance`. **reopen** (permission `bank-statements.reopen`, audited): rejected if a later statement is `Reconciled`; recomputes checkpoint to previous reconciled statement or NULL. **Port guard**: interactive `record()` with `occurred_at < repository.last_reconciled_at` ⇒ canonical domain error; projection writes flag + alert instead (mirror the freeze policy branch in the same method — trace how `allowWhileFrozen` flows first).
- [ ] Steps: failing tests (concurrent match-vs-complete race — complete revalidates under lock; out-of-order completion/reopen rejections; checkpoint stamp/recompute; port rejects backdated interactive write, projection write flagged not thrown) → implement → green → commit `feat(treasury): race-safe statement completion + reconciliation checkpoint enforcement`.

### Task 9: `treasury:reconcile` statement checks + legacy cutover

**Files:**
- Modify: `ReconcileTreasuryCommand.php` (§6.7 alert-only checks), `routes.php:249-279` (remove legacy mutation routes; keep index/show ONLY if a consumer exists — trace FE usage of `bank-reconciliations` first and delete dead FE pages in the same commit)
- Test: `apps/api/tests/Feature/Treasury/ReconcileStatementChecksTest.php`

- [ ] Steps: failing tests (tampered reconciled statement → alert; stale unreconciled >30d → alert; clean → silent; legacy POST/PUT routes now 404/410) → implement → green → commit `feat(treasury): reconcile statement checks + legacy bank-reconciliation cutover`.

🚦 **GATE 4 — treasury-reviewer (Opus)**. Focus: lock-order consistency with Wave 3, checkpoint edge cases, no-freeze alert-only invariant, cutover completeness.

---

## Wave 5 — Workspace UI + E2E

### Task 10: Statement list + upload wizard

**Files:**
- Create: `apps/web/src/features/treasury/statements/{StatementListPage.tsx, StatementUploadWizard.tsx, api.ts, types via typescript:transform, i18n FR+AR}`
- Test: co-located Vitest

**Interfaces:** list (status chips, period, delta-to-close, per-repository filter); wizard steps repository → file → profile/mapping (create-or-pick profile; live preview table incl. dropped/duplicate/unparseable reports) → acknowledge-and-confirm. Mapping UI copies the Import-module column-mapping *pattern* (component-level copy, no cross-module import).
- [ ] Steps: failing Vitest (wizard step flow, preview report rendering, empty-acknowledge gate) → implement → green → lint/typecheck → commit.

### Task 11: Reconciliation workspace

**Files:**
- Create: `apps/web/src/features/treasury/statements/{ReconciliationWorkspacePage.tsx, LinePanel.tsx, SuggestionList.tsx, ManualMatchSearch.tsx, CreateFromLineDialog.tsx}`
- Test: co-located Vitest

**Interfaces:** lines table (status filters, search, progress header = resolved/total + remaining delta); per-line right panel: ranked suggestions with one-click confirm (tier badge), manual movement search respecting per-movement remaining allocation, partial-amount entry (`MoneyInput`), create-expense/income, ignore-with-reason, execution-provenance display; completion CTA with ignored-total acknowledgment dialog; reopen action behind permission. Cross-links: instrument/expense detail pages gain "reconciled by statement line" chips (small modifies).
- [ ] Steps: failing Vitest (suggestion confirm calls executeAndAllocate shape; partial allocation arithmetic display via `formatCurrency`; ignore flow; completion dialog gating) → implement → green → commit.

### Task 12: Playwright E2E + ⑤b exit

- [ ] Spec §9 E2E end-to-end on the local stack: upload fixture CSV → preview reports → import → tier-1 confirm → tier-3 outbound clear (uses ⑤a) → tier-4 card batch + fee → create-from-line agio → ignore+acknowledge → complete → checkpoint stamped → backdated adjustment rejected → `treasury:reconcile` clean. Commit fixtures + spec file.

🚦 **GATE 5 — treasury-reviewer + frontend-conventions-reviewer (Opus); ⑤b exit = full-branch review, CRITICAL gate (Fable arbitration only if reviewers deadlock — ask owner first per standing rule).**

---

## Self-review (done at write time)

- Spec coverage: §5.1→T2, §5.2→T3, §5.3→T4, §5.4→T9, §6.1→T5, §6.2→T1+T6+T7, §6.3/6.4→T7, §6.5→T8, §6.6→T2/T5, §6.7→T9, §7→T10-11, §8 dependency honored (location column ships in T2 nullable-soft; no by-location surface tasks — deferred to multi-location merge), §9→distributed + T12.
- Type consistency: `StatementMatchingService` signatures consumed by T7/T11 match T5; `Suggestion` shape consumed by T11 matches T6; `OutboundInstrumentService::clear()` consumed per ⑤a plan interface.
- Deliberate deltas: composite-FK repository integrity instead of trigger (simpler, pgsql-native); acquirer-fee VAT = exempt default behind a config seam pending §11 Q2 confirmation.
