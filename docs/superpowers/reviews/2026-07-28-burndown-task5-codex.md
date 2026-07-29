# Codex adversarial review — burn-down Task 5 (smoke hardening)

Diff: 7d9e2fcff..83efaa89f · Reviewer: Codex CLI (session 019fac68-9d43-7e61-8089-58a15ffd6832) · 2026-07-29
(Transcribed by the orchestrator from the runtime result.)

## Round 1 Verdict: APPROVE-WITH-FIXES

- **[Important]** `smoke.ts:38,737` — `STATEMENT_DELTA` is now derived from the amount
  constants, but the CSV fixture still hardcodes `15.000/-37.125/98.500/-2.500/-1.250`;
  constants aren't the shared source of truth, so a future amount edit can fail the
  server-side delta check (`StatementCompletionService.php:156`). Interpolate the
  constants into the CSV rows.
- **[Minor]** `smoke.ts:383,991` — the "zero non-terminal statements" proof reads only
  the first 100 statements (API `per_page` cap, `BankStatementController.php:33,47`);
  paginate via the response metadata.

## Verified

- **VOID-UNREACHABLE CONFIRMED**: `StatementImportService::void` rejects allocations OR
  executions; unallocate deletes allocations only; no reversal route
  (`routes.php:293`); execution deletion rejected at model
  (`BankStatementMatchExecution.php:41`) AND database (migration :44) levels. The
  brief's void-based teardown was infeasible; reopen → re-complete is the correct
  degrade.
- Re-completion sound (reopen preserves lines/allocations; acknowledgment re-supplied);
  locale pin uses the real i18next key pre-navigation; delta composition numerically
  `72.625`; candidate loop falls through to provisioning without infinite loop.

---

# Round 2 outcome (fix 6f7e3c3f5, 2026-07-29): CLOSED (code)
CSV rows interpolate the amount constants under the signed_amount convention (runtime
check: five accepted rows' signed sum = derived STATEMENT_DELTA = '72.625'); primer/DUP
coupled to IGNORED_AMOUNT; zero-non-terminal proof paginates via meta.last_page in setup
and teardown. Standalone tsc + eslint clean. Executed evidence owed: orchestrator's live
DB-backed run before merge (incl. teardown reopen→re-complete leaving zero non-terminal
statements). Task 5 code-closed at 83efaa89f + 6f7e3c3f5.
