# Treasury UI Gaps Progress

## Gate 1 — Wave A repository balance adjustment

- Final candidate: `tug-gate-1` (`2f8f8f541`), based on `origin/dev` `1223dcc37` after rebasing onto the 2026-07-12 TanStack invalidation sweep.
- Review: `docs/handoff/gate-reviews-tug/GATE-1-rc2.md`
- Verdict: **APPROVE** (no BLOCKER/HIGH/MEDIUM findings).
- Earlier review: `docs/handoff/gate-reviews-tug/GATE-1-rc1.md`; its unhandled-rejection LOW was fixed test-first and its stale invalidation guidance was superseded by the current dev audit.

Verification evidence:

- `pnpm typecheck`: passed.
- `pnpm lint`: passed with 0 errors; TanStack audit 0 and design-system audit 753 acknowledged / 0 new.
- Treasury feature tests: 33 files, 239 passed.
- Expense regression tests: 6 files, 50 passed, 3 pre-existing todos.
- React Doctor against `origin/dev`: 98/100, no changed-file issues.

Live demo verification (`owner@pharmabio.tn`, real tenant DB, worktree Vite `:5175`, API `:8010`):

- `treasury.adjust` action visible on Main Cash Register.
- `1.2345` TND remained blocked in the dialog and sent no mutation.
- `1.000` TND count-variance adjustment succeeded; dialog closed; success toast appeared; balance updated from `624.750` to `625.750` without reload.
- Movements tab immediately showed the new `adjustment` row with `+1.000 TND`, `625.750 TND` balance-after, and journal entry `019f54b8-f987-7251-b639-50198f5224a4` linked to its finance detail route.
- Permission-hidden and flat-string 422 cases are covered by focused automated tests; the seeded owner tenant has the tolerance accounts, so the missing-account 422 cannot be triggered in that live tenant without destructive seed changes.

## Gate 2 — Wave B expense settlement

- Final candidate: `tug-gate-2`; code RC `tug-gate-2-rc1` (`a3ff4f345`).
- Review: `docs/handoff/gate-reviews-tug/GATE-2-rc1.md`
- Verdict: **APPROVE** (no BLOCKER/HIGH/MEDIUM findings).
- The review's LOW note claimed the draft-status branch lacked a row, but the committed `ExpenseDetailPage.test.tsx` eligibility matrix explicitly includes `['draft status', fixtureDraftExpense, () => true]`; no code change was needed.
- A fresh fetch before Gate 2 found four newer `origin/dev` commits limited to stock-transfer/POS work, with no overlap in this track's treasury/expense files. The protocol review therefore retained the approved Gate 1 ancestry and used `tug-gate-1..HEAD` for the Wave B range.

Verification evidence:

- `pnpm typecheck`: passed.
- `pnpm lint`: passed with exit 0; TanStack audit 0, design-system audit 753 acknowledged / 0 new, and custom ESLint rule tests passed.
- Expense feature and permission tests: 9 files, 80 passed, 3 pre-existing todos.
- Treasury regression tests: 33 files, 239 passed.
- Focused Wave B tests: 4 files, 40 passed.
- `git diff --check`: passed.
- React Doctor against `origin/dev`: 98/100, no changed-file issues.

Live demo verification (`owner@pharmabio.tn` and `cashier@pharmabio.tn`, real tenant DB, worktree Vite `:5175`, API `:8010`):

- On posted, unpaid, non-linked-cost expense `EXP-2026-000004`, the owner saw the Pay action and a dialog containing repository, optional method, defaulted payment date, and the read-only `342.500 TND` total with no amount input.
- Settlement from Main Cash Register using Espèces on `2026-07-12` succeeded; the dialog closed; the success toast appeared; the Pay action disappeared; and Paid badge, repository, method, and payment date updated without navigation or reload.
- Revisiting Main Cash Register showed the balance reduced from `625.750` to `283.250 TND`, confirming the cross-feature repository invalidation and backend cash movement.
- The now-paid `EXP-2026-000004` had no Pay action. A cashier viewing posted, unpaid `EXP-2026-000001` also had no Pay action, confirming the live `is_paid` and permission gates.
- The flat-string already-paid 422 is covered by a focused mutation test that asserts the server message is surfaced verbatim before the generic extractor.
