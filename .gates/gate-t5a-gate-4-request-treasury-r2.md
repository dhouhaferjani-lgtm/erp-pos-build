# Gate t5a-gate-4 — treasury follow-up after REJECT

You are the same adversarial **treasury-reviewer** for `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`, branch `feat/treasury-phase5`, current HEAD.

The first review is recorded at `.gates/gate-t5a-gate-4-verdict-treasury.md`. It REJECTED on three Important findings and recorded four Minor findings. The correction is commit `e99d78ffd` after Task 10 commit `67fc81173`.

Review the correction and its interaction with the original Wave 4 implementation:

```bash
git diff 67fc81173..HEAD -- \
  apps/api/app/Modules/Expense/Application/Services/ExpenseService.php \
  apps/api/app/Modules/Expense/Presentation/Requests/PayExpenseRequest.php \
  apps/api/tests/Feature/Expense/ExpensePayByInstrumentTest.php \
  apps/web/src/components/molecules/pickers/BankPicker.tsx \
  apps/web/src/components/molecules/pickers/BankPicker.test.tsx \
  apps/web/src/features/expenses/components/PayExpenseDialog.tsx \
  apps/web/src/features/expenses/components/PayExpenseDialog.test.tsx
```

Verify with `file:line` evidence:

1. First and replacement instruments get distinct, deterministic expense-cycle row keys while the base `expense:{id}:settlement` remains the financial settlement anchor. The metadata row lock must serialize cycle selection/linking so two concurrent requests cannot share a cycle or double-post.
2. A cancelled instrument remains retained/auditable, the Expense listener unlinks it, and a replacement cheque can be issued successfully with exactly one new issue event/JE and no unique-index error. An active or cleared linked status must still block every second settlement; the new allowlist must not open a future-transition hole.
3. An effet without `instrument.maturity_date` is rejected in the FormRequest before any mutation, and the service has defense-in-depth. Cheque maturity remains optional.
4. The dead post-`receive()` replay query is gone. Confirm removing it does not remove the actual idempotency-before-GL protection: metadata lock + active-link guard + unique per-cycle instrument row created before GL + atomic outer transaction.
5. Issue JE tests now pin exactly two lines, and the replacement/missing-date tests are real database tests with no weakened assertions.
6. The bank fallback affordance is unavailable in the Expense dialog, so no writable bank name is discarded; directory bank selection still sends `bank_id` and the shared picker keeps fallback support for callers that persist `bank_name`.
7. Note whether the original listener-after-commit recovery concern is correctly non-blocking for this gate and should be documented in the deploy checklist, or whether you find a concrete new blocker.

Fresh evidence, by path only:

- SQLite `ExpensePayByInstrumentTest.php`: 9 passed / 71 assertions.
- PostgreSQL same path: 9 passed / 71 assertions.
- PHPStan L8 on service/request/test: no errors. Pint: pass.
- Focused FE Vitest including the real BankPicker: 16 passed across four files. Typecheck: pass. Scoped ESLint: 0 errors.
- Full web lint after correction: exit 0, 0 errors, query-key audit 0 new, design-system audit 0 new, custom rule tests pass.

Never run the full PHPUnit suite. Do not change files. First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`. Findings ordered by severity with `file:line`. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one line stating what remains before the ⑤a exit review.
