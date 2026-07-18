# Treasury Phase ⑤ — Outbound Instruments + Bank Statement Import & Reconciliation

> **Date:** 2026-07-18 · **Status:** awaiting owner review
> **Program context:** Phase 5 of: ① spine → ② instrument portfolio → ③ cash visibility → ④ expense depth → **⑤ this spec** → TEJ platform integration.
> **Inputs:** [spine design](2026-07-07-treasury-spine-design.md) · [Phase ④ design](2026-07-13-treasury-phase4-expense-depth-design.md) · [outbound-instruments handoff](../../handoff/HANDOFF-outbound-instruments-2026-07-13.md) (G20) · [multi-location design §3](2026-07-16-multi-location-management-design.md) (payment→location attribution contract) · code-state map (agent-verified file:line, 2026-07-16).
> **Owner rulings captured (2026-07-16):** ⑤ splits into **⑤a outbound instruments first, ⑤b statement import + reconciliation second** (one spec, two sequenced plans); launch formats = **CSV/Excel with pluggable parser port** (CFONB120/MT940/camt.053 later); matching = **auto-suggest, human confirms** (no auto-confirm at launch); **create-from-line enabled** (fees/agios/interest); acquirer settlement = **match-side netting only** (no clearing-account remodel); architecture = **Treasury-native statement aggregate** (supersede legacy manual scaffold).
> ⚠️ Section-by-section checkpoints were only skimmed by the owner mid-flight; this document is the review artifact. Every ruling above is re-openable at spec review.

---

## 1. Goal

Close the loop between the ERP's internal money ledger and the bank's version of reality:

- **⑤a** — supplier-direction (outbound) check/effet lifecycle with correct payable-instrument accounting, so money promised to suppliers is visible, aged, and cleared properly. Prerequisite for ⑤b: supplier-check debits on a statement need something to match against.
- **⑤b** — import real bank statements, reconcile every line against the movements ledger, and make `last_reconciled_at` a true external checkpoint instead of a manual claim.

**The load-bearing invariant (inherited from the spine):** every cash event is a `repository_movement`. The matcher's one canonical target is movements. Nothing in ⑤b posts GL or moves balances by itself — matching is metadata; only real domain actions (clear, settle, create expense/income) move money, always through the existing port.

## 2. Scope

**In (⑤a):** `ChecksToPay`/`EffetsPayable` account purposes + seeding + brownfield backfill; direction-aware supplier-payment posting (issue-time Dr AP / Cr payable-instrument, **no movement**); outbound clearing action (GL + movement, atomic); outbound maturity alerts + payables échéancier; expense pay-by-instrument; reconcile-command portfolio check extended to outbound purposes.

**In (⑤b):** `bank_statements` + `bank_statement_lines` + `bank_statement_line_matches` + `statement_import_profiles` (Treasury module); `StatementParserInterface` with CSV/Excel column-mapping implementation; deterministic suggestion engine (4 tiers incl. card-batch netting); reconciliation workspace UI; create-from-line expense/income; fee auto-creation wiring `payment_methods.has_deducted_fees`/`fee_account_id`; statement completion stamping `payment_repositories.last_reconciled_at/balance`; legacy `bank_reconciliations` scaffold hidden/read-only; alert-only `treasury:reconcile` statement checks.

**Out (explicit):** escompte (discount) on outbound effets; check printing; CFONB120/MT940/camt.053 parsers (port defined, implementations later); auto-confirm matching (revisit after real-TN-data precision is proven); acquirer clearing-account remodel of POS card tenders (spine ruling "traced, not remodeled" stands); bank API/PSD2 feeds; multi-currency statements (hard guard: statement currency must equal repository currency); PDF statement OCR (scan-to-doc track may reuse the aggregate later); any change to the multi-location `location_id` model (⑤b **consumes** the contract, never re-models it).

## 3. Current-state anchors (verified)

| Fact | Where |
|---|---|
| `RepositoryType::BankAccount` exists; repository has `iban`/`bic`/`bank_id`/`currency`/`gl_account_id`/`location_id`/`last_reconciled_at`/`last_reconciled_balance` | `PaymentRepository.php:23-52`; migrations `2026_07_08_100000`, `2026_07_12_111000` |
| Movements ledger + port shipped per spine spec; direct balance writes DB-trigger-forbidden | `2026_07_08_100100/100200`; `TreasuryMovementServiceInterface.php:30-111` |
| Instrument lifecycle: `direction` (inbound/outbound), `kind`, `maturity_date`, remittances with per-line `cleared_at`/`bounced_at`; Phase ④ guards reject outbound on all inbound collection actions | `2026_07_12_100000`; `InstrumentStatus.php`; `InstrumentLifecycleService.php` (`clear()` :196-322 posts GL via `postEntryNow` + movement `Instrument`) |
| `treasury:reconcile` checks internal coherence only (ordinals/balances, GL linkage, transfer netting, inbound portfolio vs GL); no external-statement comparison | `ReconcileTreasuryCommand.php` (:482-521, :522-577, :579-599, :249-424) |
| POS card tenders post **gross** to repository at receipt time; no acquirer/settlement layer; `has_deducted_fees` + `fee_type/fee_fixed/fee_percent/fee_account_id` exist on `payment_methods`, unwired | `ReceiptPaymentService.php:230,290-338`; `PaymentMethod.php:31,72,96` |
| Expense settlement `POST /expenses/{id}/pay` idempotent on `expense:{id}:settlement`; `expense_metadata` carries repository/date/amount/vendor — natural matcher join | `ExpenseService.php:445-497`; `expense_metadata` migration |
| **No statement-import code exists.** Legacy `bank_reconciliations`/`_items` = manual checklist against hand-typed totals (boolean matches to `payments` only) | `2025_12_14_150000`; `BankReconciliationService.php:78-227` |
| Import module upload/mapping/batch plumbing generic; `ImportType` closed enum → statement type would be real cross-module code | `ImportType.php:7-135`; `ImportService.php:413-419` |
| Multi-location contract: bank-imported instruments/statement lines attach to a repository → inherit its `location_id` by default; `location_id` reserved as import-settable | multi-location design §3, "Phase ⑤ compatibility" |

## 4. Phase ⑤a — outbound instruments

### 4.1 Accounting contract

Two new `SystemAccountPurpose` cases: **`ChecksToPay`** and **`EffetsPayable`** (TN 40x fournisseurs — chèques/effets à payer family), following the Phase ② collection-purpose pattern: chart-seeder mapping + brownfield backfill artisan command for existing tenants + chart-verification. `ReconcileTreasuryCommand`'s company-level portfolio check (check 4, `:249-424`, currently inbound-only) is extended: linked **outbound** instrument totals vs the two payable-purpose GL balances, alert-only (consistent with today).

> Accounting-contract sign-off: the Dr/Cr mapping in 4.2/4.3 is standard TN practice, but per the GL-roadmap discipline the plan's adversarial review should include the expert-comptable question list if one is pending anyway. Not a build blocker.

### 4.2 Issue-time posting (deferred-supplier rework)

When a supplier payment is made by check/effet (`PaymentController` supplier-direction path, today shaped for inbound collection accounts — the paths Phase ④ guards block):

- Post **Dr AP (401 partner) / Cr ChecksToPay or EffetsPayable** via `postEntryNow()` — synchronous, in-transaction, period-guarded.
- Register the instrument `direction=outbound`, `status=Received` (semantics: *registered/issued*), with `maturity_date` (effets) and `bank_id`/repository of the account it will draw on.
- **No repository movement** — cash has not left. The AP subledger closes; the payable-instrument liability opens.

Idempotency: instrument-scoped key on the JE, same discipline as inbound registration.

### 4.3 Outbound clearing

New lifecycle action (plan decides after tracing `clear()`: direction-branch inside `clear()` vs a dedicated `clearOutbound()` — must not weaken Phase ④'s inbound guards):

- Post **Dr ChecksToPay/EffetsPayable / Cr bank GL** + `movement(out, source_type=Instrument)` through the port — one transaction, idempotent on the instrument's key, `postEntryNow()`.
- Status `Received → Cleared`; `cleared` date recorded. This is the moment cash leaves — and in ⑤b, the statement debit line becomes its natural trigger (§6.3 tier 3).
- Bounce/representation of outbound instruments: reuse the existing `bounce()` reversal machinery with direction-aware accounts; `cancel()` before clearing reverses the issue-time JE (Dr payable-purpose / Cr AP) and reopens the AP balance.

### 4.4 Maturity alerts + payables échéancier

`InstrumentMaturityAlertsCommand` and `MaturingInstrumentsController` gain the outbound direction, grouped/labelled separately (receivables = "what arrives", payables = "what we must fund by when"). Échéancier UI splits by direction; location filter arrives via the multi-location work, not here.

### 4.5 Expense pay-by-instrument

`POST /expenses/{id}/pay` gains an instrument mode: instead of an immediate movement, settle by issuing an outbound instrument — Dr AP / Cr payable-purpose, instrument registered, **no movement until clearing**. Idempotency key stays `expense:{id}:settlement` (one settlement per expense, whatever the mode). FE: the pay dialog gains a "by check / by effet" mode with instrument fields (number, bank, maturity). LinkedCost-kind rejection (`ExpenseService:468-478`) unchanged.

## 5. Phase ⑤b — data model & import pipeline

### 5.1 Tables (Treasury module, tenant migrations, self-guarding per push=deploy rule)

**`bank_statements`** — `id`, `tenant_id`, `company_id`, `payment_repository_id` (FK; must be `RepositoryType::BankAccount`; statement currency must equal repository currency — hard guard), `period_start`, `period_end`, `opening_balance` / `closing_balance` `decimal(15,3)`, `status` PHP enum (`Draft → Imported → Reconciling → Reconciled`, plus `Voided`), `source_file_path`, `parser_profile_id` (FK nullable), `imported_by`, `imported_at`, timestamps. Continuity: opening balance should equal the previous **reconciled** statement's closing balance for the repository — **warn, never block** (TN portals produce gaps/overlaps).

**`bank_statement_lines`** — `id`, `bank_statement_id` (FK), `line_number` (ordinal within statement), `value_date`, `booking_date` (nullable), `direction` (reuse the movement `in`/`out` enum, normalized to the **account holder's perspective at parse time** — parser owns the sign convention), `amount` `decimal(15,3)` `CHECK (amount > 0)`, `reference` (nullable), `label` (raw text), `counterparty_hint` (nullable), `match_status` PHP enum (`Unmatched` / `Matched` / `ResolvedByCreation` / `Ignored` — suggestions are computed at read time, never persisted), `ignore_reason` (nullable, required when `Ignored`), `location_id` (nullable FK `locations` — defaults from the repository's `location_id`, import-settable per the multi-location contract), `payment_repository_id` (denormalized from the statement — exists solely to scope the dedupe index), `fingerprint` (hash of `value_date+direction+amount+reference+label`; composite **unique `(payment_repository_id, fingerprint)`** across statements) so overlapping re-imports skip duplicates instead of double-staging (skipped lines reported at preview).

**`bank_statement_line_matches`** — `id`, `bank_statement_line_id` (FK), `repository_movement_id` (FK, **unique** — a movement reconciles at most once), `match_type` PHP enum (`Manual` / `SuggestionConfirmed` / `CreatedFromLine`), `matched_by`, `matched_at`. A line may hold several matches (card-batch case); a movement never matches twice.

**`statement_import_profiles`** — per repository: `name`, `parser_key` (enum, launch = `csv`/`xlsx`), `column_map` (JSONB with typed DTO, rule 3), `date_format`, `decimal_format`, `direction_convention` (signed single column vs separate debit/credit columns), `header_rows`. Saved once per bank; month 2 is upload-and-confirm.

No new `MovementSourceType` is needed: matching never creates movements directly, and created-from-line entries are ordinary expenses/incomes with their existing source types.

### 5.2 Parser port

`StatementParserInterface` (Treasury `Application` contracts): `parse(fileRef, profile): ParsedStatement` → DTO of header (detected opening/closing where derivable) + line DTOs (all monetary values as numeric-strings, rule 19). Launch implementation: CSV/Excel mapping parser reusing the Import module's `SpreadsheetParserService` **as a library only** — statements never flow through `ImportJob`/`ImportType` (module boundary stays clean; the closed enum stays closed). CFONB120/MT940/camt.053 = future `parser_key` cases behind the same port.

### 5.3 Import flow (pure staging — zero GL/movement writes)

1. Upload against a bank repository → file stored (private disk) → parsed via profile.
2. Preview: first N mapped rows, detected opening/closing, duplicate-fingerprint count, unparseable-row report. Nothing persisted until confirm.
3. Confirm: statement + lines created in one transaction, status `Imported`.
4. Void: allowed only while **no line is matched**; voiding deletes nothing financial (there is nothing financial to delete).

### 5.4 Legacy scaffold

`BankReconciliationController` routes and nav entry hidden; tables kept read-only (no data migration — they contain only boolean checklists); service left in place until the workspace ships, then deprecated in a cleanup task.

## 6. Phase ⑤b — matching

### 6.1 Semantics

- **Matching is metadata, never money.** Confirming writes `bank_statement_line_matches` rows only. Unmatching (allowed until statement `Reconciled`) deletes them; movements and GL are untouched either way.
- Domain-action confirms (tiers 3–4, create-from-line) execute the existing service (outbound clear §4.3, inbound remittance-line clear, expense settle, expense/income creation) **in the same transaction** as the match rows — a failed action leaves no match; a failed match rolls back the action.
- **Sum rule on every confirm:** Σ(signed matched movements) must equal the signed line amount. This is what makes one-line↔many-movements safe.
- Candidate movements must belong to the statement's repository. Cross-repository matches are rejected.
- Reopening a `Reconciled` statement: permission-gated, audit-evented, returns it to `Reconciling`.

### 6.2 Suggestion engine (deterministic, computed on demand)

Per unmatched line, candidates ranked in tiers over **unreconciled** movements of the statement's repository:

1. **Reference hit** — instrument number / payment reference / remittance number found in `label`/`reference`, amount equal. Highest confidence.
2. **Unique amount + date** — exactly one movement with equal amount inside a value-date window (default ±5 days, profile-tunable).
3. **Pending events** — things that *should* clear against this line but have no movement yet: uncleared outbound instruments (maturity ≈ value date, amount equal, direction `out`); deposited inbound instruments / remittance lines awaiting clear (direction `in`; remittance total or per-line amounts); unsettled expenses on this repository (amount + `expense_metadata` date/vendor hints). Confirming executes the domain action, then matches its movement.
4. **Card batch (acquirer netting)** — card-tender movements grouped by business day where `gross − line amount` is a plausible fee under the `payment_methods` fee config (`fee_type`/`fee_fixed`/`fee_percent`). Confirming matches the day's movements **and** spawns a pre-filled fee expense against `fee_account_id` (finally wiring `has_deducted_fees`); the fee expense's movement joins the same match group, satisfying the sum rule. Sale-time posting stays gross — no POS projection changes.

No auto-confirm at launch. Confidence thresholds for auto-confirm are an explicit later iteration, gated on observed precision over real TN statements.

### 6.3 Create-from-line & ignore

- **Create expense** (fees, agios, commissions) or **create income** (interest) from an unmatched line: pre-filled (amount, date, repository, label→description, `location_id` from the line), posted through the normal expense/income flow (movement via port, GL, VAT fields where relevant per Phase ④), then auto-matched `CreatedFromLine`.
- **Ignore** with mandatory reason enum + text — for true noise (e.g. information-only lines). Ignored lines count as resolved for completion but are excluded from the sum-of-lines check only if the parser marked them zero-amount; a non-zero ignored line blocks completion (forces honesty).

### 6.4 Statement completion

`Reconciled` requires: every line `Matched`/`ResolvedByCreation`/`Ignored` **and** Σ(signed lines, excluding zero-amount ignored) `==` `closing_balance − opening_balance`. Completion stamps `payment_repositories.last_reconciled_at` / `last_reconciled_balance` — which also gives the spine's backdating bound ("not before last clean reconciliation checkpoint") a real external anchor.

### 6.5 `treasury:reconcile` integration

Alert-only additions (no freeze, consistent with check 4): (a) reconciled statements whose match sums no longer reconcile (tamper/bug signal — matches are metadata, movements immutable, so drift means a reopened/edited path was abused); (b) statements sitting unreconciled past N days (default 30). Results to `audit_events` as today.

## 7. UI surfaces (apps/web, Treasury feature area)

- **Statement list page** (per bank repository + all-banks view) with status chips, period, delta-to-close; upload wizard (repository → file → profile/mapping step with live preview → confirm) — mapping UI copies the Import module's column-mapping *pattern*, not its code.
- **Reconciliation workspace** (`BankStatementReconciliationPage`): lines table left (status filters, search; progress header = matched/total + remaining delta vs closing balance); right panel per selected line = ranked suggestions with one-click confirm, manual movement search (date/amount/source filters), create-expense / create-income / ignore actions. Completion CTA runs the §6.4 validation and celebrates appropriately.
- **Cross-links:** instrument detail and expense detail show their reconciling statement line; movement drill-down (Phase ① Movements tab) shows reconciliation state.
- Conventions: design tokens, i18n keys (namespace `treasury`, AR included — don't repeat the Phase ② AR-keys debt), `tenantScopedKey`, `MoneyInput`/`formatCurrency` (rule 19 — no parseFloat anywhere near amounts), RHF+zod forms, TS types via `typescript:transform`.
- **Permissions:** `bank-statements.view/import/reconcile/reopen` (plan finalizes naming against the existing treasury permission map), seeded to admin/owner; routes `['api','auth:sanctum',SetPermissionsTeam::class]` + `can:`; deploy owes perm reseed + `permission:cache-reset` (tenant-blind cache platform bug).

## 8. Multi-location contract compliance

Exactly as ruled in the multi-location design §3: statement lines carry nullable `location_id` defaulting from their repository, import-settable via the column map; created-from-line expenses inherit the line's `location_id`; nothing in ⑤ adds, renames, or re-models any location column. Unattributed stays a first-class bucket.

## 9. Testing & verification

- TDD per task (rule 2); suites run by path, never full-suite locally (standing rule).
- **⑤a:** issue/clear/cancel/bounce red-green with rollback atomicity (GL failure ⇒ no instrument state change, no movement); direction-guard regression (inbound actions still reject outbound and vice versa); purpose backfill idempotency; reconcile check-4 outbound extension against seeded drift.
- **⑤b parser:** fixture tests on real TN-bank CSV/XLSX shapes (comma/space decimal, dd/mm dates, signed vs two-column conventions, `7.140`-style normalizer trap from the imports project); fingerprint dedupe on overlapping imports.
- **⑤b matcher:** per-tier unit tests; card-batch grouping incl. fee-plausibility bounds and sum rule; cross-repository rejection; movement-uniqueness race (two lines confirming the same movement → one wins, one gets a clean conflict error).
- **Atomicity:** tier-3/4 confirm with an injected domain-action failure ⇒ no match rows; injected match failure ⇒ action rolled back.
- **Completion:** sum-rule pass/fail; non-zero ignored line blocks; reopen is audited.
- **E2E (Playwright):** upload CSV → preview (dupes reported) → import → confirm a tier-1 match, an outbound clear via tier 3, a card batch + fee via tier 4, a create-from-line agio → statement `Reconciled` → repository checkpoint stamped → `treasury:reconcile` clean.
- Projection/queue contexts: explicit currency to scale resolution (rules 19/20) — parsers and matcher run in HTTP context but the fee-expense creation path must be queue-safe.

## 10. Delivery & gates

- One umbrella spec (this doc); **two implementation plans**: ⑤a (outbound instruments) → ⑤b (import + reconciliation). ⑤b depends on ⑤a's clearing action for tier 3 but the aggregate/parser/workspace tasks can start in parallel once ⑤a's plan is locked.
- Standing gates: adversarial review of spec + each plan **before dispatch**; `treasury-reviewer` at every milestone; human merges only.
- Execution in the dedicated worktree `../erp.treasury-phase5` (branch `feat/treasury-phase5-design`, based on local dev `e948ccaaf`) — parallel multi-location session owns local dev; promotion follows the batched fast-forward discipline.
- Deploy notes (accumulating on the treasury checklist stack): ⑤a = 1 purposes migration + backfill command + perm reseed; ⑤b = 4 migrations + perm reseed + cache-reset; both stack on the still-owed ③/④ checklists.

## 11. Open questions for the plans (not design blockers)

1. Outbound clear: direction-branch inside `clear()` vs dedicated `clearOutbound()` — decide after tracing `InstrumentLifecycleService::clear():196-322` and the Phase ④ guard placement.
2. Remittance-line tier-3 matching grain: match the remittance's aggregate clear movement vs per-line movements — trace how Phase ② posts remittance clearing before fixing the candidate shape.
3. Fee-expense category/VAT defaults for tier-4 and create-from-line (bank fees are VAT-exempt in TN in most cases — confirm with the Phase ④ VAT-split rules).
4. `statement_import_profiles.column_map` DTO shape — finalize against 2–3 real TN bank exports collected before the ⑤b plan is written.
5. Whether `MaturingInstrumentsController`'s response shape can absorb direction grouping without breaking the shipped échéancier FE — trace before extending.
