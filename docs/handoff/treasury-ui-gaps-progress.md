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

