Tasks A, B, and C all close clean. Final state of the gate:

**Baseline honesty — verified honest.** Entry count 751 → 750, exactly one removal, zero additions:

```
-  "C3|src/features/treasury/BankReconciliationPage.tsx|<button type="button" onClick={onClose} ...|#1"
```

Commit `1f6f201eb` deleted `BankReconciliationPage.tsx` outright (771 lines, `deleted file mode`) with its route, hooks, API client, and tests — 25 files, 53 insertions / 3010 deletions. The file is gone at HEAD, not relocated or suppressed, and no baseline entries were added for the replacement `features/treasury/statements/` path. No new debt absorbed. The remediation was "delete the obsolete feature" rather than an atom migration, which is a legitimate but different fix path worth noting in the exit record.

**i18n — complete.** Every literal and templated key across all 14 call sites resolves in EN, FR, and AR.

**I retract MINOR finding 5.** I flagged `ManualMatchSearch.tsx:42` as risking a raw key path for unmapped `source_type` values, reasoning from the loose `string` typing at `api.ts:85`. The backend enum `MovementSourceType.php` emits exactly 9 values, and those 9 are precisely the set translated under `statements.workspace.sourceType.*`. The value set is closed and fully covered — my concern was wrong. Same for `reason_code`.

**One new MINOR replaces it.** `statements.workspace.create.noIncomeAccounts` is translated in all three locales (en:730, fr:730, ar:119) but never referenced. `CreateFromLineDialog.tsx:56` disables the income-account `Select` when `incomeAccounts.length === 0` and shows only the generic placeholder — the user sees a dead control with no explanation, while the string written for exactly that case sits unwired. Wire it, or delete it.

---

**GATE VERDICT: REJECT stands.** Unchanged blockers:

1. **MAJOR** — statements routes and FinanceHub card not module-gated (`routes/index.tsx:1829,1840`; `FinanceHubPage.tsx:76`), inconsistent with the sidebar's `module: 'Treasury'` grouping and CLAUDE.md rule 12.
2. **MAJOR** — `income_document` provenance chip defined (`StatementReconciliationChips.tsx:15`, `api.ts:276`) but never mounted; the smoke never asserts a chip on any detail page.
3. **MAJOR** — no guardrail output independently verified. This is now the **only** remaining unclosed verification item: `pnpm typecheck`, `pnpm lint`, and the targeted Vitest run were denied at the permission layer in my session and in two delegated agents. Every static claim I could check by reading has held up; the executable evidence has not been reproduced.

Plus five MINORs (hardcoded `'0.000'` scale defaults, `'TND'` fallback, completion dialog passing the wrong flag, smoke not asserting zero-remaining, the orphaned `noIncomeAccounts` key).

To close item 3, grant `pnpm` execution for a session and re-run the gate; the code-reading portion is done and need not be repeated.
