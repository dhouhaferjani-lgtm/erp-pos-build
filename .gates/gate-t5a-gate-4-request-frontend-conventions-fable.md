# Gate t5a-gate-4 — frontend conventions Fable escalation after two REJECTs

This is the mandatory deadlock escalation for `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`, branch `feat/treasury-phase5`, current HEAD. The Opus frontend lane rejected twice:

1. R1 `.gates/gate-t5a-gate-4-verdict-frontend-conventions.md` found phantom cheque maturity, discarded fallback bank text, and cheque/effet methods exposed in cash mode.
2. R2 `.gates/gate-t5a-gate-4-verdict-frontend-conventions-r2.md` confirmed all R1 findings resolved but found the cash predicate also hid seeded `instrument_kind: 'other'` methods such as FR Direct Debit.

R1 fixes are commit `e99d78ffd`; the R2 fix is commit `3b45df13e`. Act as the adversarial **frontend-conventions-reviewer**, but adjudicate this escalation directly as Fable. Verify actual code and cite `file:line`; do not trust the request. BLOCKER or MAJOR means REJECT. Do not merge or change files.

Read both prior verdicts and review the full corrected frontend surface:

```bash
git diff 67fc81173..HEAD -- \
  apps/web/src/components/molecules/pickers/BankPicker.tsx \
  apps/web/src/components/molecules/pickers/BankPicker.test.tsx \
  apps/web/src/features/expenses/components/PayExpenseDialog.tsx \
  apps/web/src/features/expenses/components/PayExpenseDialog.test.tsx \
  apps/web/src/features/expenses/hooks/useExpenses.ts \
  apps/web/src/features/expenses/hooks/usePayExpense.test.tsx \
  apps/web/src/features/expenses/types/index.ts \
  apps/web/src/features/treasury/InstrumentListPage.tsx \
  apps/web/src/features/treasury/__tests__/InstrumentListPage.filters.test.tsx \
  apps/web/src/features/treasury/hooks/usePaymentMethods.ts \
  apps/api/app/Modules/Expense/Presentation/Requests/PayExpenseRequest.php \
  apps/api/app/Modules/Expense/Application/Services/ExpenseService.php \
  apps/api/tests/Feature/Expense/ExpensePayByInstrumentTest.php
```

Verify all of the following:

1. Cash mode shows non-paper methods (`instrument_kind === null || instrument_kind === 'other'`) while excluding cheque/effet. This mirrors backend `PaymentInstrumentController` treatment of `Other`; the test fixture must prove Direct Debit present in cash and absent in instrument mode.
2. Paper mode shows only the selected cheque/effet kind and clears stale method/repository state on mode/kind switches.
3. Effet → cheque cannot retain or submit maturity: state is cleared, payload is guarded, the regression test drives the full switch, and the backend requires maturity only for effet.
4. Expense BankPicker cannot offer a free-text fallback the API cannot persist, but the shared picker defaults fallback support on for existing callers. The dialog test renders the real picker with only `useBanks` mocked.
5. The cash request remains the exact legacy contract; instrument requests remain nested strings/nulls without numeric coercion. No tenant-key, token, i18n, AR/RTL, accessibility, or audit-baseline regression remains.
6. Direction-grouped échéancier counts/totals remain display-only, direction-specific, tenant-scoped, and covered.

Fresh evidence after the final R2 fix:

- Focused Vitest: 16/16 over pay dialog, real BankPicker, pay invalidation, and maturity grouping.
- Scoped ESLint: 0 errors. Typecheck: pass.
- Full `pnpm --filter @autoerp/web lint`: exit 0, 0 errors, query-key audit 0 new/stale, design-system audit 0 new/stale, custom rule tests pass.
- Backend correction path remains SQLite 9/71 and PostgreSQL 9/71; PHPStan/Pint clean.

Use default Vitest pool; never `--singleFork`. First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`. Findings ordered BLOCKER/MAJOR/MINOR with `file:line`. End with `VERDICT: APPROVE` or `VERDICT: REJECT` and one line stating what remains before the ⑤a exit review.
