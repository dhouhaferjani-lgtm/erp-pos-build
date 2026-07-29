# Task 5 report — Smoke hardening (5a locale pin, 5b derived STATEMENT_DELTA, 5c reopen-teardown + SMOKE reaper)

File: `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts`
Branch: `chore/treasury-burndown` (worktree `apps/erp.treasury-burndown`)

## 5a — Pin the UI locale (English by contract)

The web app resolves the active i18next language from `localStorage['autoerp-language']`
(detection order `querystring → localStorage → navigator`, `caches: ['localStorage']`;
see `apps/web/src/lib/i18n.ts:431-436`). All Playwright locators in this smoke match on
English strings ("Bank statements", "Import statement", "Confirm match", "Matched",
"Complete statement", …), so an ambient non-English runner/browser default would break
them.

Fix: `seedAuth()` now seeds `autoerp-language = 'en'` in the **same** `page.addInitScript`
that seeds auth/company, so it runs BEFORE the app bootstraps on every page load. Every
UI-driven step (3-6) calls `seedAuth()` before `page.goto`, so the locale is pinned for
the whole browser portion. Locators were NOT rewritten to test-ids (out of scope).

## 5b — Derive `STATEMENT_DELTA` from the row constants

`STATEMENT_DELTA` (was hardcoded `'72.625'`) is now derived with the file's existing
decimal-string millime helper `addMoney` (no floats), from the same row constants the CSV
builder and completion assertions use:

```
STATEMENT_DELTA = ADJUSTMENT_AMOUNT + CARD_NET − CHEQUE_AMOUNT − AGIO_AMOUNT − IGNORED_AMOUNT
               = 15.000 + 98.500 − 37.125 − 2.500 − 1.250
               = 72.625
```

Signed composition rationale (cross-checked against where the delta is consumed):
- `STATEMENT_DELTA` is `closing − opening` (`closingBalance = addMoney(opening, DELTA)`).
- It is the raw bank movement, so it **includes** the ignored informational debit
  (`−IGNORED_AMOUNT`). Step 6 confirms this: the live repository balance is
  `addMoney(closingBalance, IGNORED_AMOUNT)` (the ignored outflow is metadata-only and
  never hit the repo, so it is added back).
- Completion math is consistent: `expectedNonIgnoredDelta = DELTA − signedIgnoredTotal =
  72.625 − (−1.250) = 73.875 = 15.000 − 37.125 + 98.500 − 2.500` (the four matched lines).

Verified numerically with the file's exact helper logic → `72.625` (matches the prior
literal). `addMoney`/`toMillimes`/`fromMillimes` are hoisted `function` declarations, so
calling `addMoney` at the module-top const is valid. Negation reuses the file's existing
`` `-${AMOUNT}` `` pattern (already used at the primer-confirm and step-6 balance sites).

## 5c — Replace the dead reopen teardown + add a SMOKE reaper

### Backend reality (verified against code, cited)

- **Void preconditions** — `StatementImportService::void` (the "BankStatementVoidService"
  rules; `apps/api/app/Modules/Treasury/Application/Services/StatementImportService.php:242-268`):
  requires `status.canTransitionTo(Voided)` AND **zero allocations** AND **zero executions**;
  otherwise `DomainException("A statement with allocations or executions cannot be voided.")`.
- **Status transitions** — `BankStatementStatus`: `Reconciled → Reconciling` (reopen),
  `Reconciling → Voided` allowed. So after reopen, void is permitted *status-wise*.
- **`unallocate`** — `StatementMatchingService::unallocate` (lines 47-63): deletes
  `BankStatementLineAllocation` rows **only**; never touches `BankStatementMatchExecution`.
- **No execution-reversal path exists** — routes expose `POST /bank-statement-lines/{line}/actions`
  (execute) but **no** DELETE on `/actions`, **no** unmatch route (grep confirmed). The web
  workspace api (`apps/web/src/features/treasury/statements/api.ts`) has `unallocate`,
  `matchStatementLineAction`, `reopen` — nothing that reverses an execution.
- **Two lines carry irreversible executions**: the Tier 4 card settlement
  (`acquirer_fee` + card-batch executions — asserted in step 4) and the agio line
  (`resolved_by_creation`, from `executeAndAllocate` — asserted in step 6's match_status set).

**Conclusion: void is UNREACHABLE for this statement.** Even after `unallocate`-all, the two
execution rows remain, so `void()` would 422, and no sanctioned API unwinds them.
Unallocating first would be *actively harmful*: it would strand the statement in
`reconciling` with unmatched lines that can be neither voided (executions remain) nor
completed (`"Every statement line must be terminal"`) — the exact parking bug 5c fixes.

### Implemented teardown (degrade path, honoring both explicit 5c constraints)

The brief has an internal tension: *keep the reopen ASSERTION* AND the degrade clause's
*leave reconciled-complete WITHOUT reopening*. Resolved by satisfying both:

1. **Reopen** — assert `200` and that the checkpoint is released
   (`last_reconciled_at === null`). This is the admin-capability assertion step 7 exists to
   make (kept).
2. **Re-complete** (`POST /bank-statements/{id}/complete { acknowledge_ignored_total: true }`).
   Reopen preserves every allocation/execution/ignore, so the same balance-integrity proof
   that passed in step 6 passes again; the ignored line's acknowledgment is authorized by
   owner's `bank-statements.reopen` permission (asserted step 1). Returns the statement to a
   **terminal `reconciled`** state instead of leaving it parked in `reconciling`
   (interpreting "WITHOUT reopening" as "not LEFT reopened").
3. **Assert** the repository carries **zero active non-terminal statements** (the required
   end-state invariant).

Deliberate deviation from the brief's literal "unallocate-all → void": omitted, because void
is unreachable and unallocating would strand the statement. Documented in an in-file comment
block on step 7.

### SMOKE reaper (setup-time, best-effort, non-destructive)

Added to the step-1 candidate loop, immediately before selection (after the existing
checkpoint-skip and open-statement-skip): if `candidate.code` starts with `SMOKE-` and its
balance is zero (`toMillimes(balance) === 0n`) — and, proven by the preceding check, it has
zero active non-terminal statements — the loop **skips it silently** (`continue`). No API
deletion (no destructive cleanup in a smoke). Keeps selection deterministic: the smoke never
reuses an ambiguous self-provisioned leftover and instead falls through to provision a fresh,
known-clean `SMOKE-<ts>` fixture.

### Honest limitation (documented, by design)

Because executions are irreversible, a *truly reusable* end state (repository with
`last_reconciled_at === null`) is impossible: void is blocked, and leaving it reopened is
the parking bug. The degrade leaves the repository **checkpointed** (terminal `reconciled`),
so the setup's `last_reconciled_at !== null` skip (step 1) will still provision a fresh
`SMOKE-*` on the next run — the existing, intended self-healing behavior. What this task
*fixes* is the "parked in `reconciling` forever" dirty state; what it cannot fix (absent an
execution-unwind API) is true fixture reuse.

## What the live DB-backed run must confirm (orchestrator)

Run via `playwright.smoke.config.ts` against the live db-per-tenant stack (API `:8010`,
web `:5173`, `owner@pharmabio.tn` / `password`). Confirm:

1. **5a**: the app renders in **English** for all UI steps (locators resolve) even if the
   host/browser locale is non-English — i.e. the language pin, not luck, is driving it.
2. **5b**: steps 3-6 pass with the derived `STATEMENT_DELTA` (`closingBalance` correct;
   step-6 balance/checkpoint assertions green). No behavioral change vs the old literal.
3. **5c step 7**: reopen returns `200` and releases the checkpoint (`last_reconciled_at`
   null); re-complete returns `200` with `status: reconciled`; final statements query shows
   **no** non-terminal statement on the repository.
4. **5c reaper**: on a subsequent same-tenant run where a prior `SMOKE-*` repo exists with
   zero balance, the setup skips it silently and provisions a fresh one (no crash, no reuse
   of the leftover).

## Verification performed (no live stack in this worktree)

- `pnpm typecheck` (project) → clean. NOTE: the project `tsconfig.json` includes only `src`,
  and eslint's flat config ignores `e2e/**`, so **neither** actually type-checks/lints this
  smoke file (Playwright transpiles it at runtime).
- **Standalone `tsc --noEmit --skipLibCheck --strict` on the file** → clean (the meaningful
  type-check for the changed file).
- `pnpm exec eslint e2e/smoke/treasury-phase5b-reconciliation.smoke.ts` → 0 errors (file is
  in an eslint ignore pattern; `--no-ignore` errors on `parserOptions.project` because e2e
  is outside every tsconfig project — e2e is intentionally out of lint scope).
- **Runtime numeric check** of the derived `STATEMENT_DELTA` using the file's exact
  `addMoney`/`toMillimes`/`fromMillimes` logic → `'72.625'` (equals the prior literal).
