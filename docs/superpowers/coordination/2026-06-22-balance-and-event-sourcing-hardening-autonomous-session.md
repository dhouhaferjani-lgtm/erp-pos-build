# Autonomous Hardening Session — Partner Balances, AR/AP Wiring & Event-Sourcing Coverage

> **Owner of execution: Codex** (meticulous mechanical work). **Adversarial review: Codex AND Opus, per item.**
> **Read this file FIRST.** It is the continuation anchor for an autonomous, self-driving work session.
> Created 2026-06-22 after the `partners.credit_balance`/`payable_balance` sign fix shipped to `dev` (`5e4fc0dab`).

---

## 0. Mission

Take the hardening backlog surfaced by the 2026-06-22 balance-conventions audits, **plus a broadened audit you run yourself**, and drive every confirmed item to "fully developed" — implemented TDD, adversarially reviewed by both Codex and Opus, remediated, verified, and committed — looping autonomously until the Definition of Done (§7) is met.

**Skepticism is required.** The audit findings below are point-in-time (2026-06-22) and the owner believes *some are inaccurate or already in progress*. **Before implementing ANY item, re-verify its claim against the current `dev` HEAD (file:line may have drifted; the issue may already be fixed).** If a finding is stale or wrong, mark it `DISCARDED — <reason with current file:line evidence>` and move on. Do not implement against a claim you have not re-confirmed.

---

## 1. Operating model (the autonomous loop)

For the work-list (Phase 0 output, ordered by priority), process **one item at a time**:

```
for each item:
  1. RE-VERIFY the claim against current dev HEAD. If stale → DISCARD with evidence, continue.
  2. TDD: write failing test(s) first (watch RED), minimal code to GREEN, refactor. (CLAUDE.md rule 2)
  3. Self-check gates: scoped PHPUnit (--filter), PHPStan L8 on changed app/ files, Pint.
  4. CODEX adversarial review of the diff → SAVE to docs/superpowers/reviews/2026-06-22-<item>-codex-review.md.
  5. OPUS adversarial review of the diff (independent; tries to break it).
  6. Remediate every BLOCKER/HIGH; judge MED/LOW on merit. Re-verify gates.
  7. Commit on the session branch (one logical commit per item; Co-Authored-By trailer).
  8. Record outcome in the Progress Log (§8). Continue.
```

Loop until all confirmed items are DONE or DISCARDED. Then run a **completeness pass** (§7) before declaring done.

**Review independence:** Codex and Opus must each receive the diff + the item's acceptance criteria and be told to *refute*, not bless. Convergent findings = high signal. Resolve disagreements by re-reading the code, not by averaging opinions.

**Review-runtime note (this session runs autonomously from inside the Codex app):** if an Opus reviewer is NOT reachable from the runtime, do not skip the second opinion — perform a **second, independent adversarial pass with fresh context and a different lens** (e.g. round 1 = correctness/sign/precision; round 2 = data-migration safety/idempotency/concurrency/event-immutability), each told to refute. Save both review files. Mark any item that did not get a true cross-model (Opus) review as `opus-review: PENDING` in the Progress Log so the owner can spot-check it later. Never let the absence of Opus silently collapse the dual-review gate into a single rubber-stamp.

---

## 2. Hard guardrails (NON-NEGOTIABLE — from CLAUDE.md + project memory)

- **NEVER run the full PHPUnit suite** (`php artisan test` no-filter, or `scripts/preflight.sh`) — it crashes the laptop. Always `--filter`/file path. Ask the owner before any full run.
- **db-per-tenant** (Stancl `PostgreSQLDatabaseManager`). Tenant-scoped tables live in `tenant_<uuid>` DBs; central holds tenants/auth. Tenant migrations go in `database/migrations/tenant/`. CHECK constraints are PG-only — SQLite tests won't catch them; verify on real PG.
- **Precision contract** (`docs/architecture/precision-contract.md`): never float on money/qty; `CurrencyScale::bcformat`; constructor-inject `CurrencyScaleResolverInterface` (never `app()`); no hardcoded bcmath scale (PHPStan `ForbidHardcodedBcmathScale` — annotate genuine literals `// precision-ok: <reason>`).
- **Events are immutable forever** — never rename/restructure/delete a used Event; version it (`FooV2`). This is an event-sourced fiscal system with hash chains; any new fiscal event must respect the canonical payload contract + `FiscalPayloadConstraintValidator` (money regex is non-negative).
- **Constructor injection only** (`private readonly`), no `app()`. Strict typing (no `mixed`/`any`). Enums for status/type (no magic strings).
- **Module boundaries**: cross-module only via `Shared/Contracts/`, Events, or a module's public Service. Never import another module's models.
- **Dev workflow**: branch/worktree off `dev`; merge into LOCAL dev first; promote to `origin/dev` as clean fast-forwards; **never force-push/reset shared dev** (enforced by `dev-push-guard` hook). Rebase onto `origin/dev` before each ff push (it moves often).
- **Scope discipline**: one item at a time; do not expand an item's blast radius without re-review. Note adjacent issues in the Progress Log instead of fixing them inline.
- **Frontend**: `t()` for all strings; design tokens for colors; types flow from backend DTOs (`php artisan typescript:transform`).

---

## 3. Branch / worktree strategy

- Create ONE session worktree off `dev`: `git worktree add ../erp.balance-hardening -b fix/balance-ar-event-hardening origin/dev`.
- Copy/symlink-then-`composer dump-autoload` `apps/api/vendor` into the worktree (a bare symlink resolves the autoloader back to the source checkout — copy + re-dump instead). Copy `apps/api/.env`.
- Commit per item. Push to `origin/dev` in **verified batches** as clean fast-forwards (rebase first). Keep the local `dev` worktree (`apps/erp.dev-consolidation`) in sync.

---

## 4. Phase 0 — Broaden the audit (run FIRST; produce the work-list)

Beyond the balance backlog, scan for **unwired flows, dead paths, and missing event-sourcing coverage**. Dispatch parallel read-only auditors; write each report to `docs/superpowers/audits/2026-06-22-balance-conventions-audit/` (alongside the existing four). Targets:

1. **AR/AP GL wiring (production path):** Does the *production* invoice/credit-note→GL writer tag `partner_id` on the receivable/payable line? Which writer is canonical (`AccountingService::createInvoiceGLEntries` vs the tests-only `GeneralLedgerService::createFromInvoice`)? Is `documents.balance_due` (PG trigger) the de-facto AR truth while the GL subledger is vestigial? **Owner flagged this may be inaccurate / in progress — VERIFY against current code.**
2. **Draft→Posted lifecycle:** Are `GeneralLedgerService` AR/AP/advance/clearing entries created `Draft` and never posted in production (only POS bridges post)? Is there an "accounting cycle" poster? `getPartnerBalance` only sums `posted`.
3. **Supplier→GL path:** Any production caller of `createSupplierInvoiceJournalEntry` / `createSupplierPaymentJournalEntry`? Is `payable_balance` ever populated outside tests?
4. **Missing event-sourcing events / projectors:** Enumerate domain state changes that SHOULD be fiscal/audit events but aren't (or have events with no projector, or projectors with no consumer). Cross-check `FiscalEventType` enum ↔ `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` ↔ registered projectors ↔ canonical reader. Flag any event landing without a projection, or any state mutation that bypasses the chain.
5. **Other unwired things:** controllers/services/routes with no callers; DTOs/events defined but never emitted; feature flags/modules half-gated; scale mismatches (`decimal(15,2)` vs `(15,4)` vs precision-contract `decimal(N,3)`); magic-string statuses.

**Output of Phase 0:** a single ordered `work-list.md` (in the audit folder) — each item with: ID, claim, current-HEAD file:line evidence, severity, acceptance criteria, test plan, scope boundary, and `status: TODO`.

---

## 5. Seed backlog (consolidated from the four 2026-06-22 audits — VERIFY each before acting)

Already shipped (do NOT redo): **partner `credit_balance`/`payable_balance` non-negative-magnitude fix** (`5e4fc0dab`). Convention is LOCKED (see `docs/superpowers/reviews/2026-06-20-partner-credit-balance-sign-codex-review.md`).

| ID | Claim (verify first) | Severity |
|----|----------------------|----------|
| H-1 | Production AR/credit-note GL lines omit `partner_id` → B2B receivable subledger structurally 0 | HIGH |
| H-2 | AR/AP/advance/clearing entries created `Draft`, never posted in production | HIGH |
| H-3 | No production supplier-invoice/payment→GL caller → `payable_balance` never populated | HIGH |
| H-4 | No balanced-entry assertion in `postEntry` (sums debit/credit but never compares) | HIGH |
| M-1 | `refreshPartnerBalance` runs outside the GL transaction (except POS charge) → silent stale cache on failure; drive it off a `JournalEntryPosted` event | MED |
| M-2 | DB CHECK constraints (`credit_balance >= 0`, `payable_balance >= 0`) + domain guard (PG-only; verify on real PG) | MED |
| M-3 | Scheduled per-tenant/company/purpose `reconcileSubledger` alert job (also detects H-1 via `entries_without_partner`) | MED |
| M-4 | Money-column scale mismatch: `payments.amount`/`payment_allocations.amount`/`documents.*` are `decimal(15,2)`, balances `decimal(15,4)`; reconcile to precision-contract `decimal(N,3)` + inject `CurrencyScaleResolverInterface` | MED |
| M-5 | `getNetBalanceAttribute` ignores `payable_balance` for `Both`-type partners; web `getNetBalance` routes `both` through the customer branch | MED |
| M-6 | Per-currency cached balances before multi-currency partners ship (cache is a currency-agnostic scalar that would sum across currencies) | MED |
| M-7 | Uncapped advance application in `clearCustomerAdvanceToReceivable` (no check vs actual advance balance) | MED |
| L-1 | Type-level `MoneyMagnitude` (non-negative) vs signed `LedgerAmount` to make sign confusion unrepresentable | LOW |
| L-2 | Replace `'posted'` magic string with `JournalEntryStatus` enum | LOW |
| L-3 | `getPartnerStatement` running balance is debit-normal for all purposes (wrong sign for liability statements) | LOW |
| L-4 | Centralize the duplicated `net = receivable − credit` formula (≥6 call-sites) | LOW |
| L-5 | Docs: advance-vs-store-credit single-liability decision; gift-card/breakage policy; 4dp-storage vs currency-scale | LOW |

Full detail + file:line in: `docs/superpowers/audits/2026-06-22-balance-conventions-audit/0{1,2,3,4}-*.md`.

---

## 6. Per-item acceptance template

Each item is DONE only when: claim re-verified; failing test written first and now green; PHPStan L8 clean on changed `app/` files; Pint clean; **both** Codex and Opus reviews obtained and all BLOCKER/HIGH resolved; committed; Progress Log updated. Schema/constraint items additionally require a real-PG verification note (SQLite won't catch CHECK/constraint failures).

---

## 7. Definition of Done (whole session)

- Every Phase-0 + seed-backlog item is `DONE` or `DISCARDED (with evidence)`.
- A final **completeness critic** pass: "what's still unwired, what claim is unverified, what event lands without a projector, what modality wasn't scanned?" — anything it finds becomes a new item; loop again.
- No silent caps: if anything was deferred (too large, needs owner decision), it is listed explicitly in the handoff with rationale — never dropped silently.
- A closing summary committed to this file's Progress Log: items done, discarded, deferred (with why), and any new owner decisions required.

---

## 8. Progress Log (append-only — the autonomous session writes here)

- 2026-06-22 — Handover created. Seed backlog from 4 audits. Partner balance sign fix already shipped (`5e4fc0dab`). Awaiting Phase 0.

---

## 9. References

- Fix commit: `5e4fc0dab` (`fix(accounting): store partner credit/payable balances as non-negative magnitudes`).
- Plan review: `docs/superpowers/reviews/2026-06-20-partner-credit-balance-sign-codex-review.md`.
- Impl review: `docs/superpowers/reviews/2026-06-22-partner-credit-balance-sign-impl-codex-review.md`.
- Audits: `docs/superpowers/audits/2026-06-22-balance-conventions-audit/01..04`.
- Memory: `project_partner_balance_sign_convention` (locked convention + rollout notes).
