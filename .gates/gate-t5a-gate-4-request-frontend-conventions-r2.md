# Gate t5a-gate-4 — frontend conventions follow-up after REJECT

You are the same adversarial **frontend-conventions-reviewer** for `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`, branch `feat/treasury-phase5`, current HEAD.

The first review is recorded at `.gates/gate-t5a-gate-4-verdict-frontend-conventions.md`. It REJECTED on three MAJOR findings and noted three MINOR findings. The correction is commit `e99d78ffd` after Task 10 commit `67fc81173`.

Review:

```bash
git diff 67fc81173..HEAD -- \
  apps/web/src/components/molecules/pickers/BankPicker.tsx \
  apps/web/src/components/molecules/pickers/BankPicker.test.tsx \
  apps/web/src/features/expenses/components/PayExpenseDialog.tsx \
  apps/web/src/features/expenses/components/PayExpenseDialog.test.tsx \
  apps/api/app/Modules/Expense/Presentation/Requests/PayExpenseRequest.php \
  apps/api/app/Modules/Expense/Application/Services/ExpenseService.php \
  apps/api/tests/Feature/Expense/ExpensePayByInstrumentTest.php
```

Verify with `file:line` evidence:

1. Effet → cheque clears retained RHF maturity state and the payload builder independently forces cheque maturity to null. Confirm the new regression test actually enters an effet date, switches kind, and inspects the submitted nested contract.
2. Cash mode excludes all payment methods whose `instrument_kind` is non-null; instrument mode continues to filter by selected kind and clears stale selections on mode/kind switches.
3. The shared BankPicker now has a backward-compatible `allowFallback` switch. The Expense dialog disables fallback because its backend contract has no `bank_name`; callers that persist fallback names retain the default behavior. Confirm the dialog test uses the real BankPicker with only the data hook mocked, selects a real directory option, and proves `bank.notListed` is absent.
4. The bare unreachable instrument-kind required rule is removed without weakening reachable validation; effet maturity still has translated inline UI validation and now backend validation too.
5. Confirm no new token, accessibility, tenant-key, i18n, string-money, or baseline debt was introduced by the fix.

Fresh evidence:

- Focused Vitest after correction: 16/16 over pay dialog, real BankPicker, pay invalidations, and maturity grouping.
- Full lint after correction: exit 0, 0 errors, 0 new query-key/design-system violations, custom rule tests pass.
- Typecheck: pass. Changed-file ESLint: 0 errors.
- React Doctor branch-relative count decreased from 154 to 152; remaining critical diagnostics are unrelated branch/repository findings, not this correction.
- Backend SQLite/PostgreSQL correction path: 9/71 on both; PHPStan/Pint clean.

Use the default Vitest pool; never `--singleFork`. Do not change files. First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`. Findings ordered BLOCKER/MAJOR/MINOR with `file:line`. End with `VERDICT: APPROVE` or `VERDICT: REJECT` and one line stating what remains before the ⑤a exit review.
