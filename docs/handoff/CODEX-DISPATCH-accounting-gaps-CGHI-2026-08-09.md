# Codex A→Z dispatch — live accounting gaps C/G/H/I (2026-08-09)

**Model/effort (owner directive):** Codex SOL 5.6, HIGH effort. Workhorse mode: implement
end-to-end, TDD red-first, then hand back for the Opus/reviewer quality gates below.
**Source register:** `docs/handoff/HANDOVER-live-accounting-gaps-country-defaults-review-2026-08-09.md`
(items lettered there; evidence trail in
`docs/superpowers/specs/reviews/2026-08-08-country-defaults-spec-review.md`).

## ⚠️ Scope — what is ALREADY DONE elsewhere (do NOT re-implement)
- **Items A, B, D, E, F** landed in the DPA SEEDS lane (branch
  `fix/dpa-seeder-gaps-accounting`, commits `2bee58c48..c05812444`, fiscal-pos gate in
  flight): FR `CostOfGoodsSold→603` + `GeneralExpense→628`, FR 419/409
  `CustomerAdvance`/`SupplierAdvance`, cross-chart parity guard,
  `accounting:backfill-chart-purposes` command, country-aware expense categories wired
  into provisioning (fixes F's Transport/624 omission). Read
  `.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/task-seeds-report.md`
  before touching any seeder. If that lane's gate forces changes, they happen THERE.
- **Item J** is owned by DPA Wave-3 task T24 (wiring `UninvoicedDeliveryNoteService`) —
  see `plan-wave3.md` §3E. Do not touch.
- **Historical-FR-invoice assessment in item A:** GREENFIELD — no live tenants; nothing
  to assess. Skip.

## In scope: C, G, H, I

**Base your branch on local `dev` (currently ~85 ahead of origin — includes the merged
DPA lanes and the S0/V7 enums). Branch name: `codex/accounting-gaps-cghi`.**

### C — `SalesDiscount` missing (TN + FR) + dead-letter recovery
Per register item C (async projection failure, NOT HTTP 500): backfill `SalesDiscount`
for TN (709-family PCN) and FR (709 PCG) via the purpose-first collision-aware pattern
(`2026_08_07_100000_backfill_purchase_stamp_duty_account.php`; note the SEEDS lane's new
`accounting:backfill-chart-purposes` command may already generalize this — extend it
rather than writing a parallel migration if it fits). THEN the recovery half: a
deterministic way to inventory dead-lettered account-charge projections and replay them
(`fiscal:retry-projections` / `RetryFiscalProjectionsCommand`) after the account exists,
with a test proving a dead-lettered event replays to a correct GL entry post-backfill.
Greenfield means no real dead letters exist — the test manufactures one.

### G — `is_stamp_duty` capability guard (tax API + UI)
Per register item G: reject `is_stamp_duty=true` (and rule on `DOCUMENT_TOTAL`→stamp
conversion) in `TaxConfigurationController` store/update for countries without timbre
capability. Authority: reuse `CountryTaxConfigurationRegistry` — do NOT invent a second
authority; if the country-defaults lane's `CountryAccountingCapabilities` registry has
landed by then, use it instead (check first). UI alignment in `TaxConfigFormModal`
(hide/disable + translated reason, en+fr). Tests: FR store+update negatives, TN positive.

### H — `stamp_duty_amount` must aggregate `is_stamp_duty=true` configs ONLY
Per register item H: fix `TaxCalculationService:273-299` to filter; credit-note
persistence unchanged in shape; decide generic `DOCUMENT_TOTAL` treatment = REFUSE for
now (a typed validation error at configuration time for non-stamp DOCUMENT_TOTAL taxes
in countries where no design exists — matches the owner's no-silent-behavior doctrine)
and record the "own named total" design as a follow-up ticket. End-to-end test: a
non-stamp DOCUMENT_TOTAL tax on a credit note never enters the stamp-purpose path.
⚠️ Fiscal-adjacent: sealed-bytes discipline — `stamp_duty_amount` feeds signed payloads;
verify what hashes over it before changing any persisted value, and pin byte-identity
for existing TN timbre flows.

### I — tolerance alert fail-closed
Per register item I: `recordTolerancePurposeMissingAlertSafely` must not swallow alert
persistence failure while the GL entry is also skipped. Prescribed shape: fail the
projection acknowledgment when the durable alert cannot be persisted (retry via the
existing projection retry machinery) — no new outbox infrastructure. Test: alert-write
failure ⇒ projection NOT acknowledged ⇒ retried.

## House rules (binding)
TDD red-first per item; tests BY PATH only (full PHPUnit suite forbidden — crashes the
machine); never `git stash` (shared across worktrees); rule 19 money discipline; strict
types; migrations/backfills idempotent + unattended-safe (origin/dev push auto-deploys
`tenants:migrate`); revert-replay every fix commit (prove the covering test goes red);
**a real PostgreSQL run before any green claim** (local PG on 5432; Docker 5433 is
currently broken); en+fr i18n for any user-facing string.

## Quality gates (Opus/reviewer-side, run by the orchestrator session after handback)
1. **fiscal-pos-reviewer** on C+H (projection replay correctness; sealed-bytes/timbre
   byte-identity; the H filter's blast radius on TN launch flows).
2. **tenancy-authz-reviewer** on G (capability guard is authz surface).
3. **treasury-reviewer** on I (projection-acknowledgment semantics; no partial writes).
4. Standard evidence contract: per-item red-first proof, revert-replay record, SQLite +
   PG counts, pint/phpstan clean on touched files. Gate verdicts follow the DPA loop
   (fix rounds ≤5, scoped re-reviews).

## Deliverable
Branch `codex/accounting-gaps-cghi` (NOT merged, NOT pushed), a report file
`docs/sessions/codex-accounting-gaps-cghi-report.md` with per-item: files, tests +
commands + output, decisions, concerns. The orchestrator session merges only after all
gates pass.
