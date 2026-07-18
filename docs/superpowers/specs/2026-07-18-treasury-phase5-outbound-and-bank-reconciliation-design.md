# Treasury Phase ⑤ — Outbound Instruments + Bank Statement Import & Reconciliation

> **Date:** 2026-07-18 · **Rev 2 (same day):** reconciled against the Codex adversarial review — [reviews/2026-07-18-treasury-phase5-spec-codex-review.md](../reviews/2026-07-18-treasury-phase5-spec-codex-review.md) (5 BLOCKER / 10 HIGH / 4 MED / 1 LOW, verdict REJECT on Rev 1; all findings accepted after firsthand verification of the three load-bearing claims; resolutions in §12) · **Status:** awaiting owner review
> **Program context:** Phase 5 of: ① spine → ② instrument portfolio → ③ cash visibility → ④ expense depth → **⑤ this spec** → TEJ platform integration.
> **Inputs:** [spine design](2026-07-07-treasury-spine-design.md) · [Phase ④ design](2026-07-13-treasury-phase4-expense-depth-design.md) · [outbound-instruments handoff](../../handoff/HANDOFF-outbound-instruments-2026-07-13.md) (G20) · [multi-location design §3](2026-07-16-multi-location-management-design.md) (payment→location attribution contract) · code-state maps (agent 2026-07-16 + Codex review verification log 2026-07-18).
> **Owner rulings captured (2026-07-16):** ⑤ splits into **⑤a outbound instruments first, ⑤b statement import + reconciliation second** (one spec, two sequenced plans); launch formats = **CSV/Excel with pluggable parser port**; matching = **auto-suggest, human confirms**; **create-from-line enabled**; acquirer settlement = **match-side netting** (no clearing-account remodel — Rev 2: netting now rides on an explicit payment-method→repository routing wave, §6.2); architecture = **Treasury-native statement aggregate** (supersede legacy manual scaffold).

---

## 1. Goal

Close the loop between the ERP's internal money ledger and the bank's version of reality:

- **⑤a** — supplier-direction (outbound) check/effet lifecycle with correct payable-instrument accounting, so money promised to suppliers is visible, aged, and cleared properly. Prerequisite for ⑤b: supplier-check debits on a statement need something to match against.
- **⑤b** — import real bank statements, reconcile every line against the movements ledger, and make `last_reconciled_at` a true external checkpoint instead of a manual claim.

**The load-bearing invariant (inherited from the spine):** every cash event is a `repository_movement`. The matcher's one canonical target is movements. Nothing in ⑤b posts GL or moves balances by itself — matching is metadata; only real domain actions (clear, settle, create expense/income, acquirer-fee) move money, always through the existing port, always with immutable execution provenance (§6.1).

## 2. Scope

**In (⑤a):** `ChecksToPay`/`EffetsPayable` purposes in the **`InstrumentAccountPurpose`** system + chart seeding + brownfield backfill; direction-aware supplier-payment posting (issue-time Dr AP / Cr payable-instrument, **no movement**, deferred-movement guard extended to supplier direction); full **outbound state machine** (§4.3 transition table — clear, cancel, bounce, re-presentation) with per-action idempotency keys and atomic subledger reopening on cancel; issue/clear bank-repository contract; maturity-alert command outbound coverage + échéancier FE grouping (controller already direction-aware); expense pay-by-instrument incl. atomic cancellation semantics; reconcile-command portfolio check extended to outbound purposes.

**In (⑤b):** `bank_statements` + `bank_statement_lines` + `bank_statement_line_allocations` + `bank_statement_match_executions` + `statement_import_profiles` (Treasury module); profile-aware `StatementParserInterface` with CSV/Excel implementation; deterministic suggestion engine (4 tiers); **payment-method→repository routing wave** (`payment_methods.default_repository_id` + bridge resolution + backfill — prerequisite for tier 4); reconciliation workspace UI; create-from-line expense/income with extended DTOs (date/location/account); dedicated acquirer-fee action wiring `has_deducted_fees`/`fee_account_id`; race-safe statement completion stamping `payment_repositories.last_reconciled_at/balance` + movement-port checkpoint enforcement; legacy `bank_reconciliations` mutation routes **disabled**; alert-only `treasury:reconcile` statement checks.

**Out (explicit):** escompte (discount) on outbound effets; check printing; CFONB120/MT940/camt.053 parsers (port defined, implementations later); auto-confirm matching (revisit after real-TN-data precision is proven); acquirer clearing-account remodel of POS card tenders (spine ruling "traced, not remodeled" stands — routing wave changes repository *selection*, not the gross-at-sale model); bank API/PSD2 feeds; multi-currency statements (hard guard: statement currency must equal repository currency); PDF statement OCR; any change to the multi-location `location_id` model (⑤b **consumes** the contract, never re-models it — dependency ordering in §8).

## 3. Current-state anchors (verified; Rev 2 corrections marked ★)

| Fact | Where |
|---|---|
| `RepositoryType::BankAccount` exists; repository has `iban`/`bic`/`bank_id`/`currency`/`gl_account_id`/`location_id`/`last_reconciled_at`/`last_reconciled_balance` | `PaymentRepository.php:23-52`; migrations `2026_07_08_100000`, `2026_07_12_111000` |
| Movements ledger + port shipped per spine spec; port does NOT compare `journal_entry_id` on replay (orphan-JE hazard documented in the contract) and does NOT yet consult `last_reconciled_at` | `2026_07_08_100100/100200`; `TreasuryMovementServiceInterface.php:30-111` (replay caveat :35-48); `TreasuryMovementService.php:46-95` |
| ★ Instrument purposes live in **`InstrumentAccountPurpose`** (Treasury enum, 7 inbound cases) resolved country-aware by `InstrumentAccountResolver` — NOT in `SystemAccountPurpose`. FR chart seeds 403 (effets à payer); TN + generic charts have **no** payable-instrument accounts today | `InstrumentAccountPurpose.php:7-16`; `InstrumentAccountResolver.php:39-51`; `FranceChartOfAccountsSeeder.php:163-169`; `TunisiaChartOfAccountsSeeder.php:167-216` |
| ★ `clear()` and `bounce()` explicitly REJECT outbound; bounce is inbound-shaped end-to-end (remittance required, AR/doubtful accounts, out movement, customer-allocation reversal); re-presentation = inbound remittance `Bounced → Deposited` | `InstrumentLifecycleService.php:196-204, 323-331, 337-353, 398-445, 483-504, 507-543`; `InstrumentRemittanceService.php:162-193` |
| ★ Deferred-supplier issue already creates a completed `Payment` + positive allocations + reduces `balance_due` + can mark the document paid; existing `cancel()` refuses most linked payments; the deferred-movement suppression guard covers **customer** direction only | `PaymentController.php:790-813, 905-920, 1082-1127`; `InstrumentLifecycleService.php:596-639` |
| ★ TWO POS writers: `TreasuryReceiptBridge` posts movements and routes EVERY tender to the FIRST GL-linked repository ordered by id (per-method routing = documented Phase-1.5 deferral, `payment_methods.default_repository_id` proposed in-code); legacy `ReceiptPaymentService` creates Payment/GL but **no movement** | `TreasuryReceiptBridge.php:460-468, 599-623, 664-709, 712-741`; `ReceiptPaymentService.php:276-316` |
| ★ `MaturingInstrumentsController` ALREADY filters both directions/kinds; remaining ⑤a work is alerts-command coverage + FE grouping only | `MaturingInstrumentsController.php:27-46, 82-108` |
| `treasury:reconcile` checks internal coherence only (ordinals/balances, GL linkage, transfer netting, inbound portfolio vs GL); no external-statement comparison | `ReconcileTreasuryCommand.php:482-521, 522-577, 579-599, 249-424` |
| `has_deducted_fees` + `fee_type/fee_fixed/fee_percent/fee_account_id` on `payment_methods`, unwired; `fee_account_id` is a direct account ref — the normal expense path derives its debit account from `ExpenseCategory` instead, and settlement credits generic Bank/Cash purposes, not the repository's exact GL account | `PaymentMethodController.php:98-139`; `ExpenseCategory.php:18-58`; `GeneralLedgerService.php:3349-3378`; `ExpenseService.php:516-540` |
| Expense settlement `POST /expenses/{id}/pay` idempotent on `expense:{id}:settlement`; settlement movement uses `occurredAt: null` (current time); expense creation does not populate `documents.location_id`; income defaults to `ProductRevenue` | `ExpenseService.php:445-497, 535-573, 106-136`; `GeneralLedgerService.php:3493-3505` |
| **No statement-import code exists.** Legacy `bank_reconciliations`/`_items` = manual boolean checklist vs hand-typed totals, mutation routes exposed, no transactions/locks | `2025_12_14_150000`; `BankReconciliationService.php:78-249`; Treasury `routes.php:249-279` |
| Import module: `SpreadsheetParserService` always treats the first physical row as headers, no preamble/locale/Excel-serial-date/debit-credit-column support; `ImportType` closed enum | `SpreadsheetParserService.php:44-66, 129-176`; `ImportType.php:7-135` |
| Multi-location contract: statement lines attach to a repository → inherit its `location_id`; `location_id` import-settable. The §3 package (FK/index, instrument/payment location columns, API exposure) is **planned, not landed** | multi-location design §3 + :101-126 |

## 4. Phase ⑤a — outbound instruments

### 4.1 Accounting contract

Extend **`InstrumentAccountPurpose`** (NOT `SystemAccountPurpose`) with `ChecksToPay` and `EffetsPayable`; extend `InstrumentAccountResolver` country-aware, exhaustively (every enum `match` branch — no default-arm silence). Both purposes resolve to **liability** accounts; resolver behavior on a missing account = throw the canonical missing-purpose-account domain error (fail loud, never fall back to an inbound account).

**Chart codes (provisional — expert-comptable confirmation is a ⑤a gate before build):**

| Purpose | TN | FR | Generic |
|---|---|---|---|
| `EffetsPayable` | `403` Fournisseurs — effets à payer (new in TN seeder) | `403` (already seeded) | `403` (new) |
| `ChecksToPay` | `4035` Fournisseurs — chèques à payer (custom sub-account, new) | `4035` (new) | `4035` (new) |

Seeder additions + brownfield backfill artisan command for existing tenants (productized command per the standing Phase-② ticket, not raw SQL) + chart verification. `ReconcileTreasuryCommand` check 4 (`:249-424`, inbound-only today) extends to: linked **outbound** instrument totals vs the two payable-purpose GL balances, alert-only.

### 4.2 Issue-time posting (deferred-supplier rework)

When a supplier payment is made by check/effet:

- **Repository contract (issue AND clear):** an active, tenant/company-scoped `RepositoryType::BankAccount` with non-null `gl_account_id`, currency equal to the payment currency, and (when the instrument carries `bank_id`) a consistent `bank_id`. Cash registers/safes/inactive repositories are rejected with a canonical domain error.
- Post **Dr AP (401 partner) / Cr ChecksToPay or EffetsPayable** via `postEntryNow()` — synchronous, in-transaction, period-guarded. Idempotency key **`instrument:{id}:issue`** enforced *before* GL posting (persisted with the JE; exact replay returns the original result; semantic mismatch throws) — never a single instrument-scoped key shared across lifecycle actions (the port's replay contract makes a shared key an orphan-JE generator).
- Register the instrument `direction=outbound`, `status=Received` (semantics: *registered/issued*), with `maturity_date` (effets).
- **No repository movement.** The existing deferred-movement suppression guard (`PaymentController:1082-1127`) covers customer direction only — **extend it to the supplier direction** so no legacy Cr-cash/movement path executes alongside the new posting. The supplier `Payment` + allocations + document status updates that the controller already performs (`:790-813, 905-920`) stay — they are exactly what cancel must atomically unwind (§4.3).

### 4.3 Outbound state machine (dedicated — never reuses inbound `clear()`/`bounce()` internals)

The existing inbound actions structurally reject outbound and are inbound-shaped end-to-end (remittances, AR accounts, customer allocations). ⑤a builds dedicated outbound transitions with this explicit table (any transition not listed throws the canonical invalid-transition error; every action: lock instrument + linked payment/expense metadata + repository in the established lock order, enforce its idempotency key before GL, exact replay returns original result, mismatch throws; all events audit-logged):

| Transition | Action | GL (`postEntryNow`) | Movement (port) | Subledger | Idempotency key |
|---|---|---|---|---|---|
| `Received → Cleared` | clear (cash leaves) | Dr payable-purpose / Cr **`repository.gl_account_id`** | `out`, `source_type=Instrument` | — | `instrument:{id}:clear:{cycle}` |
| `Received → Cancelled` | cancel before clearing | reverse issue: Dr payable-purpose / Cr AP | none | **atomic reopen** (below) | `instrument:{id}:cancel` |
| `Cleared → Bounced` | bank dishonors our paper | Dr **`repository.gl_account_id`** / Cr payable-purpose | compensating `in`, `reverses_movement_id` set | AP stays closed — debt remains represented by the instrument | `instrument:{id}:bounce:{cycle}` |
| `Bounced → Cleared` | re-presentation succeeds | as clear, next cycle | `out`, next cycle | — | `instrument:{id}:clear:{cycle+1}` |
| `Bounced → Cancelled` | give up; settle another way | reverse issue: Dr payable-purpose / Cr AP | none | **atomic reopen** (below) | `instrument:{id}:cancel` |

`{cycle}` = a persisted per-instrument presentation counter (starts 1, incremented on each re-presentation) so bounce/re-present cycles never collide on keys.

**Atomic subledger reopen (cancel, both variants) — one orchestration transaction:** lock instrument, payment, expense metadata (when §4.5), allocations, documents, repository in the established order → post the issue-reversal JE → append **negative allocations** → recompute document `balance_due`/paid status → reset expense `is_paid`/`paid_at`/repository/method/date where applicable → mark the payment reversed → record the cancellation event idempotently. Never "reverse the JE now, fix the subledger later."

### 4.4 Maturity alerts + payables échéancier

`MaturingInstrumentsController` already filters both directions (`:27-46`) — no controller work. Remaining: `InstrumentMaturityAlertsCommand` outbound coverage (verify, then extend — separate outbound alert wording: "must fund by") and FE échéancier direction grouping (receivables vs payables sections).

### 4.5 Expense pay-by-instrument

`POST /expenses/{id}/pay` gains an instrument mode: settle by issuing an outbound instrument — Dr AP / Cr payable-purpose, instrument registered, **no movement until clearing**. Idempotency key stays `expense:{id}:settlement` for the settlement itself; the instrument's own lifecycle uses §4.3 keys. Cancellation of an expense-linked instrument runs the §4.3 atomic reopen incl. the expense-metadata reset. LinkedCost-kind rejection (`ExpenseService:468-478`) unchanged. FE: pay dialog gains a "by check / by effet" mode (number, bank, maturity).

## 5. Phase ⑤b — data model & import pipeline

### 5.1 Tables (Treasury module, tenant migrations, self-guarding per push=deploy rule)

**`bank_statements`** — `id`, `tenant_id`, `company_id`, `payment_repository_id` (FK; must be `RepositoryType::BankAccount`; hard guard: statement currency equals repository currency), **`currency` (persisted — historical truth must not depend on mutable repository state)**, `period_start`, `period_end`, `opening_balance` / `closing_balance` `decimal(15,3)`, `status` PHP enum (**`Imported` → `Reconciling` → `Reconciled`**, plus `Voided` — no `Draft`: preview is ephemeral, confirm creates directly as `Imported`), **`source_file_sha256`** (unique per repository — re-importing an identical file is rejected, not silently deduped to zero lines), `source_file_path`, `parser_profile_id` (FK nullable), `imported_by`, `imported_at`, timestamps. Indexes: `(payment_repository_id, period_start)`, status. Continuity: opening balance should equal the previous **reconciled** statement's closing balance — **warn, never block**. Statement/line state-transition matrix is normative (§6.6).

**`bank_statement_lines`** — `id`, `bank_statement_id` (FK), `line_number` (**unique per statement**), `value_date`, `booking_date` (nullable), `direction` (movement `in`/`out` enum, normalized to the account holder's perspective at parse time — the parser owns the sign convention), `amount` `decimal(15,3)` `CHECK (amount > 0)` (zero-amount informational rows are **dropped at parse time**, reported in the preview, never persisted), `reference` (nullable), **`bank_transaction_id`** (nullable — mapped when the export provides one; preferred dedupe identity), `label` (raw), `counterparty_hint` (nullable), `match_status` PHP enum (`Unmatched` / `Partial` / `Matched` / `ResolvedByCreation` / `Ignored`) — **derived state**, recomputed only inside locked aggregate actions, `ignore_reason` (enum + text, required when `Ignored`), `location_id` (nullable FK `locations` — defaults from the repository's `location_id`, import-settable per the multi-location contract), `payment_repository_id` (denormalized; **composite FK/trigger ties it to the parent statement's repository**), `fingerprint` (hash of canonically-normalized `value_date+direction+amount+reference+label` **+ occurrence index** among identical tuples within the file; unique `(payment_repository_id, fingerprint)`; when `bank_transaction_id` exists it replaces the heuristic tuple). Duplicate-fingerprint rows on overlapping imports are skipped and reported at preview; a confirm that would accept zero lines is rejected unless explicitly acknowledged.

**`bank_statement_line_allocations`** (replaces Rev 1's match table — true allocation model) — `id`, `bank_statement_line_id` (FK), `repository_movement_id` (FK), **`matched_amount` `decimal(15,3)` CHECK > 0**, unique **`(bank_statement_line_id, repository_movement_id)`**, `match_type` PHP enum (`Manual` / `SuggestionConfirmed` / `CreatedFromLine`), `matched_by`, `matched_at`. Validation (always under lock): Σ allocations per line ≤ signed line amount (== at completion); **Σ allocations per movement ≤ movement amount** (over-allocation impossible). This supports one-line↔many-movements, many-lines↔one-movement (split bank postings), and partial settlement, which the Rev 1 unique-movement design could not.

**`bank_statement_match_executions`** (immutable provenance — the anti-replay ledger for financial actions triggered from matching) — `id`, `bank_statement_line_id`, `action_type` enum (`OutboundClear` / `InboundClear` / `ExpenseSettle` / `AcquirerFee` / `CreateExpense` / `CreateIncome`), `action_key` (deterministic, unique — e.g. `stmtline:{line_id}:acquirer_fee`), target entity type+id, produced `repository_movement_id`(s), `executed_by/at`. **Unmatch deletes allocations, never executions.** Re-confirming a line whose execution exists reuses the original result (never re-executes). `Voided` requires zero allocations AND zero executions (or completed compensating reversals).

**`statement_import_profiles`** — `id`, `tenant_id`, `company_id`, `payment_repository_id` (FK), `name`, `is_active`, `parser_key` (enum, launch = `csv`/`xlsx`), `column_map` (JSONB, typed DTO), `date_format`, `decimal_format`, `direction_convention` (signed single column vs debit/credit columns), `header_rows` (preamble skip count), timestamps. Company-scoped like every tenant table; a profile is only usable on its own repository.

No new `MovementSourceType`: matching never creates movements directly; created/triggered entries carry their existing source types.

### 5.2 Parser port

`StatementParserInterface` (Treasury `Application` contracts): `parse(fileRef, profile): ParsedStatement` → header DTO + line DTOs (monetary values as numeric-strings, rule 19). The launch CSV/Excel implementation is **profile-aware** — this exceeds what `SpreadsheetParserService` does today (first-physical-row headers only, raw strings): required behaviors = preamble/header-row skipping, Excel serial-date conversion, configured date formats, locale decimal normalization (`1 234,56` / `1.234,56` / `7.140`-style thousands traps from the imports project), separate debit/credit column convention, formula-cell handling (evaluate-or-reject, never raw), encoding/delimiter detection, and a per-row unparseable report (row number + reason) surfaced in preview. Implementation choice (extend `SpreadsheetParserService` behind a raw-row mode vs a Treasury-local parser using the same underlying library) is a plan task — either way Import-module code is a *library* dependency, statements never flow through `ImportJob`/`ImportType`. CFONB120/MT940/camt.053 = future `parser_key` cases.

### 5.3 Import flow (pure staging — zero GL/movement writes)

1. Upload against a bank repository → file stored (private disk) → sha256 computed; duplicate file for the repository ⇒ rejected with a pointer to the existing statement.
2. Preview: first N mapped rows, detected opening/closing, dropped zero-amount rows, duplicate-fingerprint count, unparseable-row report. Nothing persisted.
3. Confirm: statement + lines in one transaction, status `Imported`. Zero-accepted-lines confirm rejected unless explicitly acknowledged.
4. Void: only while the statement has **no allocations and no executions**; voiding deletes nothing financial because nothing financial exists yet.

### 5.4 Legacy scaffold

Legacy `bank_reconciliations` **mutation routes are removed only after the replacement workspace ships** (Wave-5 cutover; `Presentation/routes.php:249-279`) — not merely hidden; index/show may remain read-only if anything consumes them (trace first). The cutover includes the FE surface: delete `features/treasury/api/reconciliation.ts` + `BankReconciliationPage.tsx` (+tests/types/i18n) and repoint the FinanceHub card. Only the new completion action may stamp `payment_repositories.last_reconciled_*`. Tables kept (no data migration); `BankReconciliationService` deleted in the same cutover.

## 6. Phase ⑤b — matching

### 6.1 Semantics

- **Matching is metadata, never money.** Confirming writes allocation rows; financial side effects happen only via domain actions recorded in `bank_statement_match_executions`. A failed action leaves no allocation; a failed allocation rolls back the action (one transaction).
- **Sum rule** (under lock, both directions): per line, Σ(matched_amount) ≤ signed line amount, == at completion; per movement, Σ(matched_amount) across all lines ≤ movement amount.
- Candidate movements must belong to the statement's repository (cross-repository rejected).
- Unmatch (until `Reconciled`): deletes allocations, keeps executions; re-confirm reuses the original execution. Reopen of a `Reconciled` statement: permission-gated, audit-evented, recomputes the repository checkpoint (§6.5).

### 6.2 Suggestion engine (deterministic, computed on demand — suggestions never persisted)

Per unresolved line, candidates ranked over **unreconciled** movements of the statement's repository:

1. **Reference hit** — instrument number / payment reference / remittance number / `bank_transaction_id` found in `label`/`reference`, amount equal.
2. **Unique amount + date** — single movement with equal amount inside a value-date window (default ±5 days, profile-tunable).
3. **Pending events** — no movement yet: uncleared outbound instruments (maturity ≈ value date, amount equal, `out`); deposited inbound instruments/remittance lines awaiting clear (`in`); unsettled expenses on this repository (amount + `expense_metadata` date/vendor hints). Confirming executes the domain action (recorded in match_executions), then allocates its movement.
4. **Card batch (acquirer netting)** — **gated on the routing wave below.** Card-tender movements grouped by **sale business day in the company timezone**; refunds/chargebacks of that day join as negative members; multi-day/partial settlements fall back to manual matching. A group is suggested when `gross − line amount` is a plausible fee under the `payment_methods` fee config. Confirming allocates the day's movements **and** runs the dedicated **acquirer-fee action** (§6.4), whose movement joins the allocation set, closing the sum rule.

**Routing wave (⑤b prerequisite for tier 4; the in-code Phase-1.5 roadmap item):** add `payment_methods.default_repository_id`; `TreasuryReceiptBridge` resolves each tender's repository via its payment method (falling back to today's first-GL-linked rule for unmapped methods); guarded backfill command re-points historical card movements' *matching metadata expectations* — historical movements themselves are immutable, so the backfill populates the mapping and the matcher treats pre-wave card movements as tier-2/manual candidates only. Single-repository tenants are unaffected. Legacy `ReceiptPaymentService` (no-movement writer) is out of matching scope: its payments have no movements and therefore no candidates — enumerate this in the workspace empty-state help.

No auto-confirm at launch; confidence thresholds are a later iteration gated on observed precision over real TN statements.

### 6.3 Create-from-line & ignore

- **Create expense** (fees, agios, commissions) or **create income** (interest): pre-filled from the line and posted through the normal flows **with extended DTOs** — explicit `location_id` (line's), explicit `occurred_at`/document/JE dates = the line's **value date** (subject to period/checkpoint guards), and an explicit account override for income (bank interest → financial-income account, never the `ProductRevenue` default). Execution recorded (`CreateExpense`/`CreateIncome`), movement allocated, line → `ResolvedByCreation`.
- **Ignore** (non-zero lines only — zero rows never persist): mandatory reason enum + text. Ignored lines are **excluded from the completion sum identity**, so completing a statement with any ignored line means knowingly accepting a bank≠ledger discrepancy: completion then requires the `bank-statements.reopen`-level permission and records the ignored total in the audit event.

### 6.4 Acquirer-fee action (dedicated — not the generic expense path)

The generic expense path derives its debit account from `ExpenseCategory` and credits generic Bank/Cash purposes — wrong on both sides for fees. The dedicated action: **Dr `payment_methods.fee_account_id`** (VAT treatment per Phase ④ rules — TN bank-fee VAT status is a plan-time confirmation, §11) / **Cr the statement repository's exact `gl_account_id`**, movement `out` on that repository, `postEntryNow()`, deterministic key `stmtline:{line_id}:acquirer_fee`, execution-recorded. This is the `has_deducted_fees` wiring.

### 6.5 Statement completion & the reconciliation checkpoint

- **One lock order** (statement → lines → allocations/executions → repository row); all §6.1 sums revalidated **under lock** at completion — never trusted from prior requests.
- `Reconciled` requires: every line `Matched`/`ResolvedByCreation`/`Ignored`, per-line sums exact, and Σ(signed non-ignored lines) == `closing_balance − opening_balance`.
- **Checkpoint = `period_end`.** Statements per repository must complete **in period order** (completing out of order rejected). Completion stamps `last_reconciled_at = period_end` and `last_reconciled_balance = closing_balance`.
- **Movement-port enforcement:** the port gains the spine's promised backdating bound — `occurred_at` earlier than the repository's `last_reconciled_at` is rejected for interactive writes (projection writes flag `recorded_while_frozen`-style and alert, mirroring the freeze policy).
- **Reopen** recomputes the checkpoint to the latest remaining reconciled statement's `period_end` (or clears it), and is rejected if a later statement is still `Reconciled` (no gaps in the reconciled prefix).

### 6.6 State machines (normative)

- **Statement:** `Imported → Reconciling` (first workspace action) `→ Reconciled` (§6.5) `→ Reconciling` (reopen); `Imported|Reconciling → Voided` (§5.3.4 conditions). No other transitions.
- **Line `match_status`:** derived from allocations/executions/ignore rows only, recomputed inside the same locked transaction as the mutating action; `Partial` = allocations exist but per-line sum not yet exact. Terminal only while statement `Reconciled`.

### 6.7 `treasury:reconcile` integration

Alert-only additions (no freeze): (a) reconciled statements whose stored sums no longer validate (metadata tamper signal); (b) statements unreconciled past N days (default 30); (c) §4.1 outbound portfolio check. Results to `audit_events`.

## 7. UI surfaces (apps/web, Treasury feature area)

- **Statement list page** (per bank repository + all-banks) with status chips, period, delta-to-close; upload wizard (repository → file → profile/mapping with live preview incl. dropped/duplicate/unparseable reports → acknowledge-and-confirm).
- **Reconciliation workspace** (`BankStatementReconciliationPage`): lines table left (status filters, search; progress header = resolved/total + remaining delta); right panel per line = ranked suggestions with one-click confirm, manual movement search (respecting per-movement remaining allocation), partial-amount entry, create-expense/create-income/ignore, execution-provenance display for already-executed lines. Completion CTA runs §6.5 and surfaces the ignored-total acknowledgment when applicable.
- **Cross-links:** instrument/expense detail show their reconciling statement line; movement drill-down shows allocation state; échéancier gains the §4.4 direction grouping.
- Conventions: design tokens, i18n incl. **AR keys shipped in-phase** (don't repeat the Phase-② AR debt), `tenantScopedKey`, `MoneyInput`/`formatCurrency` (rule 19), RHF+zod, `typescript:transform`.
- **Permissions:** `bank-statements.view/import/reconcile/reopen` + `instruments.clear-outbound` (plan finalizes naming against the permission map), seeded to admin/owner; routes `['api','auth:sanctum',SetPermissionsTeam::class]` + `can:`; deploy owes perm reseed + `permission:cache-reset`.

## 8. Multi-location contract & dependency ordering

⑤b consumes the multi-location §3 contract exactly: line `location_id` defaults from the repository, import-settable; created-from-line entries inherit the line's `location_id`; nothing re-modeled. **Dependency (Rev 2):** the multi-location package that adds location FKs/indexes, instrument/payment location columns, and repository-API location exposure is dispatch-ready but **not landed** — ⑤b's line-location *storage* only needs `locations` + `payment_repositories.location_id` (both exist), but attribution *surfaces* and instrument/payment inheritance depend on that package. Plan rule: ⑤b's location column ships nullable-soft regardless; by-location statement surfaces land only after multi-location §3 merges. If ⑤b reaches the workspace milestone first, the location column stays dormant-but-populated.

## 9. Testing & verification

- TDD per task (rule 2); suites by path, never full-suite locally.
- **⑤a:** transition-table exhaustive tests — every listed transition red-green with rollback atomicity (GL failure ⇒ no state change/movement), every unlisted transition throws; cancel reopen atomicity (allocations negated + document status + expense metadata in ONE transaction, partial-failure injection proves all-or-nothing); per-action idempotency keys (replay returns original, mismatch throws, bounce/re-present cycles don't collide); repository-contract rejections (cash register, inactive, currency mismatch, missing GL); deferred-movement guard covers supplier direction; purpose resolver exhaustive-branch + missing-account throw; backfill idempotency; reconcile check-4 outbound drift.
- **⑤b parser:** fixture tests on real TN-bank CSV/XLSX shapes (preambles, Excel serial dates, locale decimals incl. `7.140` trap, debit/credit columns, encodings); unparseable-row reporting; file-sha256 duplicate rejection; fingerprint occurrence-index (two identical same-day fees both import); zero-amount drop.
- **⑤b matcher:** per-tier unit tests; allocation both-direction sum guards (over-allocating a movement impossible under concurrency — lock test); partial allocations; card-batch grouping (timezone boundaries, refund members, fee bounds, multi-day fallback to manual); execution provenance (unmatch keeps execution, re-confirm reuses it, no double fee expense); void blocked after execution.
- **Completion:** under-lock revalidation race test (concurrent match + complete); out-of-order completion rejected; checkpoint stamp/reopen recompute; movement-port backdating rejection behind the checkpoint.
- **E2E (Playwright):** upload CSV → preview (drops/dupes/unparseables reported) → import → tier-1 confirm, tier-3 outbound clear, tier-4 card batch + fee, create-from-line agio, ignore-with-acknowledgment → statement `Reconciled` → checkpoint stamped → backdated adjustment rejected → `treasury:reconcile` clean.
- Queue-safety: explicit currency to scale resolution everywhere (rules 19/20).

## 10. Delivery & gates

- One umbrella spec; **two implementation plans**: ⑤a (outbound instruments) → ⑤b (import + reconciliation, whose wave 0 = payment-method routing). ⑤b's aggregate/parser/workspace tasks may start once ⑤a's plan is locked; tier-3 outbound matching needs ⑤a shipped.
- Standing gates: adversarial review of each plan **before dispatch**; `treasury-reviewer` at every milestone; expert-comptable confirmation of §4.1 chart codes — **build may proceed on the provisional codes; nothing merges to dev until confirmed** (plan-aligned wording); human merges only.
- Execution in worktree `../erp.treasury-phase5` (branch `feat/treasury-phase5-design`, based on local dev `e948ccaaf`); promotion via batched fast-forward discipline.
- Deploy notes (stacking on the owed ③/④ checklists): ⑤a = purposes seeder migration + backfill command + perm reseed; ⑤b = 5 migrations + routing backfill + perm reseed + cache-reset.

## 11. Open questions for the plans (not design blockers)

1. Outbound clearing implementation shape: dedicated `OutboundInstrumentService` vs direction-branched methods on `InstrumentLifecycleService` — decide after tracing; must not weaken the Phase-④ inbound guards or the §4.3 rejection of inbound internals.
2. TN bank-fee VAT treatment for §6.4/create-from-line defaults (confirm against Phase ④ VAT-split rules with the expert-comptable alongside the §4.1 codes).
3. `column_map` DTO shape — finalize against 2–3 real TN bank exports collected before the ⑤b plan is written.
4. Parser implementation: extend `SpreadsheetParserService` with a raw-row/profile mode vs Treasury-local parser on the same library.
5. Legacy `bank_reconciliations` read endpoints: any consumers? (trace; delete vs keep read-only).
6. `InstrumentMaturityAlertsCommand` current outbound coverage (verify before extending).

## 12. Adversarial-review reconciliation (Codex, 2026-07-18)

Full review: [reviews/2026-07-18-treasury-phase5-spec-codex-review.md](../reviews/2026-07-18-treasury-phase5-spec-codex-review.md). Verdict on Rev 1: REJECT. All findings accepted; the three load-bearing claims (purpose-model conflict, bridge routing, maturing-controller staleness) were re-verified firsthand before reconciling.

| # | Sev | Finding (short) | Resolution |
|---|---|---|---|
| 1 | BLOCKER | Outbound bounce/re-presentation cannot reuse inbound machinery | §4.3 dedicated transition table with per-transition GL/movement/subledger/key contracts |
| 2 | BLOCKER | Cancel doesn't atomically reopen supplier/expense subledger | §4.3 atomic-reopen orchestration transaction (allocations, documents, expense metadata, payment state) |
| 3 | BLOCKER | Card matching depends on unshipped method→repository routing | §6.2 routing wave = ⑤b wave 0 (`default_repository_id` + bridge + backfill); tier 4 gated on it; pre-wave card movements tier-2/manual |
| 4 | BLOCKER | `CHECK (amount>0)` vs zero-amount ignored contradiction | §5.1 zero rows dropped at parse; §6.3 ignore = non-zero only, excluded from sum, elevated-permission acknowledgment |
| 5 | BLOCKER | Match schema can't enforce sum rule both directions | §5.1 allocation model: `(line,movement)` unique + `matched_amount` + per-movement cap + `Partial` state |
| 6 | HIGH | 5a GL contract incomplete (liability type, exact bank account, legacy path suppression) | §4.1 liability requirement + fail-loud resolver; §4.2/4.3 credit `repository.gl_account_id`; guard extended to supplier direction |
| 7 | HIGH | Purposes proposed in wrong enum (`SystemAccountPurpose`) | §4.1 `InstrumentAccountPurpose` + `InstrumentAccountResolver`, exhaustive branches, provisional TN/FR/generic codes + expert-comptable gate |
| 8 | HIGH | Instrument-scoped idempotency insufficient across lifecycle | §4.2/4.3 per-action keys with `{cycle}` counter, enforced before GL |
| 9 | HIGH | No bank-repository contract at issue | §4.2 active BankAccount + currency + gl_account + bank_id consistency, issue AND clear |
| 10 | HIGH | Unmatch/void can replay or conceal financial actions | §5.1/§6.1 immutable `match_executions` provenance; unmatch keeps executions; re-confirm reuses; void requires none |
| 11 | HIGH | Fingerprint false positives/negatives | §5.1 file sha256 unique/repo, `(statement,line_number)` unique, occurrence index, `bank_transaction_id` preferred, canonical normalization, zero-line confirm rejected |
| 12 | HIGH | Completion/reopen race + checkpoint semantics undefined | §6.5 single lock order, under-lock revalidation, checkpoint=`period_end`, in-order completion, reopen recompute, movement-port enforcement |
| 13 | HIGH | Fee expense can't use `fee_account_id` via normal path | §6.4 dedicated acquirer-fee action (Dr fee account / Cr repository GL, deterministic key) |
| 14 | HIGH | Create-from-line loses date/location/income class | §6.3 extended DTOs: explicit location, value-date everywhere, income account override |
| 15 | HIGH | Multi-location prerequisite not landed | §8 dependency ordering: storage ships nullable-soft; surfaces gated on multi-location §3 merge |
| 16 | MED | Profiles/statements lack ownership+integrity columns | §5.1 full ownership FKs, persisted currency, composite repository FK/trigger, indexes |
| 17 | MED | `SpreadsheetParserService` can't satisfy profile contract | §5.2 profile-aware parser requirements enumerated; implementation shape = plan Q4 |
| 18 | MED | "Read-only legacy" left mutation routes live | §5.4 mutation routes removed at cutover; only new completion stamps checkpoints |
| 19 | MED | Statement/line state machines underspecified | §6.6 normative matrices; `Draft` removed; `match_status` derived under lock |
| 20 | LOW | Stale code anchors (maturing controller, POS writers, remittance grain) | §3 corrected (★ rows); §4.4 reduced; §6.2 enumerates both POS writers |
