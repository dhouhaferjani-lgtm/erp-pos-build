# CODEX HANDOVER — Tauri POS precision sweep (CONTINUE S5→S7)

> Parallel lane to the web demo-fix round. This is the Tauri desktop POS (`apps/pos`) — a
> DIFFERENT app from the web round (no shared files), so it runs concurrently with
> `CODEX-demo-visual-round2-chunk1.md`. **But within this sweep the tasks are SEQUENTIAL**
> (they share `paymentStore.ts` / `cartStore.ts` / `holdStore.ts`) — one implementer, one task
> at a time, review between. Do NOT parallelize the tasks below.

## ✅ Start at S6 — S1–S5 are done and committed
S1–S4 and **S5 (D0-5 holdStore) are committed** on `fix/pos-precision-port` (S5 = `21f1241e0`,
orchestrator-verified: discriminating round-trip test proven red against parent code, all 7
holdStore tests green, `tsc --noEmit` clean, no consumer breakage). The worktree
`apps/erp.pos-audit` is clean. **Codex starts at Task S6** and owns S6→S7 as the sole implementer.

> Coordination note: a repo-global stash exists — `stash@{0}: WIP on dev: … payment tolerance v2 —
> Phase 2 (A1 POS short-pay) (#41)` — belonging to a DIFFERENT session/branch. Do NOT pop or drop
> it; it is unrelated to this sweep. (Stashes are shared across worktrees, so it will show in
> `git stash list` here — leave it be.)

## Environment
- **Worktree/branch:** `/Users/houssamr/Projects/syneriva/apps/erp.pos-audit` on
  `fix/pos-precision-port` (tip `10068123f`; S1–S4 / D0-2..D0-4 already committed). Work here only,
  do not push.
- App: `apps/pos` — React 19 / TS strict / Zustand; Tauri 2. Tests: `cd apps/pos && pnpm vitest run
  <path>` (BY PATH, never the whole suite), then `pnpm exec tsc --noEmit`.

## The plan (authoritative — follow it task by task)
Read **`docs/superpowers/plans/2026-07-01-pos-desktop-precision-sweep.md`** in the worktree. It has
the full per-task file:line map, fix recipe, and discriminating-test spec. Execute the remaining
tasks **in order**:
- **S5 — D0-5 holdStore SQLite round-trip** (if not already committed per the precondition)
- **S6 — P1 boundaries** (cartStore display getters, refundDraftStore sum, TodaySalesPanel dashboard sums)
- **S7 — Lock:** port the `no-parsefloat-on-money` ESLint guard from `apps/web` into `apps/pos`,
  run it, slice preflight (tsc + each task's vitest path + lint on touched files), and update the
  audit README §5 with the fix commit SHAs.

## Global rules (from the plan — restated)
- **Never compute money/qty in float.** No native `+ - * / < >`, no `parseFloat`/`Number(`/
  `Math.max/min`/`.toFixed()` producing a persisted money value. Use `apps/pos/src/lib/decimal.ts`
  (`bcadd/bcsub/bcmul/bcdiv/bcsum/bccomp/bcformat`, big.js). Money/qty flow as decimal STRINGS;
  gates use `bccomp(a,b) <op> 0`.
- Percent/discount is a rate → `bcdiv(x,'100',scale)`, not currency-scaled.
- Currency scale from the file's existing `getCurrencyDecimals(currency)` — never hardcode
  (TND=3, EUR=2). A `(float)→String()` boundary is allowed ONLY at a pure display leaf on an
  already-bc-computed value, marked with a comment; never mid-fiscal-calc.
- **TDD:** discriminating failing test first (one that float-drifts, e.g. `100.10 − 99.80 →
  '0.30'` not `'0.30000000000000027'`), then fix. Tests by path only. Commit per task, Conventional
  Commits, co-author trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- Scope discipline: touch only the task's sites; note adjacent drift, don't fix it. **Do NOT push.**

## Opus self-review gate — MANDATORY after each task, before the next
The `claude` CLI is installed. After a task is green:
```bash
git diff > /tmp/pos-task.diff
claude -p --model claude-opus-4-8 "You are an adversarial reviewer for a Tauri POS that AUTHORS
fiscal receipts (device is the fiscal source of truth — drifted money gets .toFixed()'d, PERSISTED,
and HASHED). Review this diff for POS precision task <S#>. Verify: (1) zero float math on money/qty
remains in the touched paths — all via lib/decimal.ts bcmath strings + bccomp gates; (2) currency
scale comes from getCurrencyDecimals, not hardcoded; (3) the new test genuinely FAILS on the old
float code (it must exercise a value that float-drifts); (4) SQLite/hash round-trip is string-
identity; (5) no scope creep. Cite file:line. Verdict: SHIP or DO-NOT-SHIP + blocking items. Diff:
$(cat /tmp/pos-task.diff)"
```
Address every DO-NOT-SHIP item and re-review until SHIP before the next task. Higher fiscal stakes
than the web sweep — the D0-1 float bug SILENTLY BLOCKED checkout on an exact-cash tender.

## Final report (to orchestrator)
Per task: root cause (1 line), files changed, exact test commands + pass output, Opus verdict
(must be SHIP), commit SHA. Then per the plan: after S5–S7 + reviews clean, the whole branch
(`dev..HEAD` = audit + D0-1 + full sweep S1–S7) goes to a final Opus whole-branch review, then the
orchestrator merges the bundle to `dev` as a clean fast-forward. Do NOT push or merge yourself.

---

### Not in this handover (orchestrator/owner tasks, for awareness)
- **Refund/void restock** is a BACKEND fix (`apps/api`) already done on `fix/pos-refund-void-restock`
  (`bf394748d`), not on origin/dev — that's a review+merge task, not part of this sweep.
- **Fresh Tauri build → staging** (POS API URL is baked at build time) is operational, done after
  the sweep merges.
