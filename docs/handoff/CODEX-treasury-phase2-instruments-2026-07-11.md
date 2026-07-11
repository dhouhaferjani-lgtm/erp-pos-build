# CODEX HANDOVER — Treasury Phase ②: Instrument Portfolio + Échéancier

> **Date:** 2026-07-11. **Runner:** Codex desktop (CLI-brokered Codex cannot write to `apps/erp.*` worktrees). **Post-run per gate:** the Claude session gate-reviews the worktree diff — do NOT merge to dev or push yourself, ever.
> **Plan (the source of truth — execute it task-by-task):** `docs/superpowers/plans/2026-07-11-treasury-phase2-instruments-echeancier.md` (**Rev 2**). **Spec (binding):** `docs/superpowers/specs/2026-07-10-treasury-phase2-instruments-echeancier-design.md` (**Rev 2** — §6 lock order, §7 posting tables, §20 reconciliation). Both adversarially reviewed and reconciled (reviews in `specs/reviews/` + `plans/reviews/` dated 2026-07-11).
> **Branch:** `feat/treasury-instruments`, worktree `../erp.treasury-instruments`.

---

## 0. Mission

Execute the plan's 28 tasks / 8 waves exactly as written: chèques/traites get GL at every lifecycle stage (PCG/PCE account-as-state), POS-collected paper enters the portfolio, remise en banque bordereau, impayé with routing + subledger reopening, échéancier + maturity alerts. **Cash (repository balance) moves ONLY at clearing / dishonor-after-clearing, through the Phase-① movement port, with a JE — no exceptions.** The DB trigger will reject any other balance write.

## 1. Setup — prerequisites, in order

1. **Base must contain the spec/plan/brief commits.** They live on local dev (`d654550c1..3c6b4b24a` + this brief); the owner promotes local dev → `origin/dev` as a clean fast-forward batch first (standing rule 21 discipline). Verify before starting: `git log origin/dev --oneline | head` shows `docs(plan): treasury Phase 2 plan Rev 2 ...`. If not promoted yet, STOP and report.
2. `git worktree add ../erp.treasury-instruments -b feat/treasury-instruments origin/dev`
3. Read, in this order: the plan header + Global Lock Order + Executor notes + Global Constraints; spec §6/§7/§8/§9; then only the task you are on. Fresh context per task is fine — each task's Files/Interfaces block is self-contained.

## 2. Ground rules (non-negotiable — violations block merge)

All of the plan's **Global Constraints** section, plus:

1. **THE GLOBAL LOCK ORDER** (plan header): instrument row(s) id-sorted → document row(s) id-sorted → GL company advisory (`postEntryNow`) → repository row (port). Never invert. Never post GL then lock a document.
2. TDD every task: failing test first (except the explicitly-marked **green-first regression pins** — plan Executor notes), red → green → commit. Tests BY PATH only — `./vendor/bin/phpunit <path>`, `pnpm vitest run <path>`. NEVER the full suite (crashes the machine).
3. `postEntryNow` only — never `DB::afterCommit` for any spine/instrument posting. Explicit currency in every projection/console context (rule 20); the lifecycle service is context-free.
4. No float on money: bcmath at `getScale($currency)`; amounts are decimal strings end-to-end; `<MoneyInput>` on FE.
5. Strict types, constructor injection (`private readonly`), enums for every status/type/direction, module boundaries via `Shared/Contracts` (rule 6).
6. Migrations re-runnable (`hasColumn`/`hasTable`, `DROP ... IF EXISTS` trigger form); tenant migrations only; `php artisan tenants:migrate` locally.
7. `php artisan typescript:transform` after DTO changes (worktree needs `CACHE_STORE=array` in `.env` — known silent no-op gotcha); never hand-edit `packages/shared/types/`.
8. i18n en+fr for every new string; design tokens exclusively in new FE directories (rule 18); tenant-scoped query keys (`audit-tanstack-keys.mjs` enforces).
9. Route middleware 4-tuple + `can:` gates (rule 12); new permissions must land in `RolesAndPermissionsSeeder` in the same task that adds the route.
10. Commit every green step (conventional commits). **Never merge to dev, never push dev, never force-push.** The branch stays in the worktree for Claude-side gate reviews.
11. If a plan instruction contradicts what you find in code, STOP that task and record the contradiction in the progress file — do not improvise around a money path. Line refs in the plan are re-grep-before-edit hints, not gospel; *semantic* contradictions are what stop work.

## 3. Execution order + HARD-STOP GATES

Execute waves in plan order. **Four hard stops — commit, leave the worktree, notify the owner, wait for the gate verdict before continuing:**

| Gate | After | Scope reviewed | Claude-side reviewer tier |
|---|---|---|---|
| **GATE 1** | Wave B (Tasks 1–10) | schema + lifecycle service + GL postings | **Fable 5** (money path — crucial) |
| **GATE 2** | Wave D (Tasks 11–15) | HTTP surface + payment cutover + refund guards | **Fable 5** (money path — crucial) |
| **GATE 3** | Wave E (Tasks 16–18) | POS bridges (fiscal perimeter) | **Fable 5** (fiscal + money — crucial) |
| **GATE 4** | Wave H (Tasks 19–28) | échéancier, reconcile #4, FE, E2E | **Opus** wave review + **one Fable 5 whole-branch final pass** |

(Reviewer tiering is the owner's standing rule: Opus by default, Fable 5 only for crucial financial-spine/fiscal reviews — Gates 1–3 and the final whole-branch pass are exactly that. In-wave spot checks, if requested, run on Opus.)

**Wave G (FE, Tasks 23–25) precondition:** the design-system unification sweep (`feat/design-system-unification`) must be MERGED to dev first. Before starting Wave G: `git fetch origin dev && git rebase origin/dev`, resolve nothing silently (report conflicts), then follow the **post-sweep** conventions — PageHeader adoption pattern and design tokens as they exist on dev at that moment, not as they looked when this brief was written. Verified 2026-07-11: the sweep currently touches NONE of Wave G's named files, but its remaining PageHeader pass may — re-check `git log origin/dev -- apps/web/src/features/treasury` before editing each file. Waves A–F are backend-only and have zero overlap with the sweep — start immediately, no waiting.

## 4. Verification commands (run at every gate, paste output into the progress file)

```bash
cd apps/api
./vendor/bin/phpunit tests/Feature/Treasury   # by directory, this one is safe-sized; add tests/Feature/Accounting at Gates 2+
./vendor/bin/phpstan                           # level 8, zero NEW errors
./vendor/bin/pint --dirty

cd ../web    # Gates 2 (Task 15) and 4 only
pnpm typecheck && pnpm lint
pnpm vitest run src/features/treasury src/features/finance
```

Plus per-gate: Gate 1 — the Task 8/9 in-test `treasury:reconcile` runs are green; Gate 2 — the byte-identical Phase-① pins pass; Gate 3 — bridge tests green with `CompanyContext` cleared + the F9 `pos_receipt_payments`-untouched pins; Gate 4 — the full Task-27 Playwright A→Z report with screenshots in `docs/sessions/treasury-phase2-e2e/`.

## 5. Interlocks (do NOT touch)

- **Bank directory** (`feat/bank-reference-verification`): the `banks` table + `BankPicker` belong to that track. Phase ② ships `payment_instruments.bank_id` as a **plain indexed uuid, no FK** (plan Task 2). Adopt `BankPicker` in Wave G only if that track has merged; otherwise free-text + leave the one-line interlock note in the progress file.
- **Treasury UI gaps** (`feat/treasury-ui-gaps`): owns `RepositoryDetailPage`/`ExpenseDetailPage` — no file overlap; keep it that way.
- Fiscal perimeter: never write `fiscal_events`, canonical bytes, or `pos_receipt_payments`; the bridges READ the canonical payload only.
- `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`: the new chart-of-accounts entries (portfolio accounts) get one log line in Task 1's commit.

## 6. Progress tracking

`docs/handoff/treasury-phase2-progress.md` in the worktree — update after every task: files touched, test counts, verification output, deviations-with-justification, contradictions found (rule 2.11). This file travels with the branch and is the first thing each gate review reads.
