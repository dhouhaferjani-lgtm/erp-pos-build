# CODEX HANDOVER — Treasury Phase ②: Instrument Portfolio + Échéancier

> **Date:** 2026-07-11 (autonomous-gates revision, owner order). **Runner:** Codex desktop (CLI-brokered Codex cannot write to `apps/erp.*` worktrees). **Execution is AUTONOMOUS END-TO-END:** gate reviews run inside this workflow via `claude -p` (§3) — no human wait at gates. Do NOT merge to dev or push yourself, ever; when Gate 4 closes, leave the worktree and report — the Claude session runs the final orchestrated whole-branch review and owns the merge.
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

## 3. Execution order + AUTONOMOUS AUDIT GATES (run them yourself via `claude -p`)

Execute waves in plan order. Four gates. At each gate you do NOT wait for a human — you run the adversarial review yourself with the Claude CLI, fix what it finds, and re-run until clean:

| Gate | After | Scope reviewed |
|---|---|---|
| **GATE 1** | Wave B (Tasks 1–10) | schema + lifecycle service + GL postings |
| **GATE 2** | Wave D (Tasks 11–15) | HTTP surface + payment cutover + refund guards |
| **GATE 3** | Wave E (Tasks 16–18) | POS bridges (fiscal perimeter) |
| **GATE 4** | Wave H (Tasks 19–28) | échéancier, reconcile #4, FE, E2E |

**Gate protocol (identical every gate):**

1. Commit everything, run the §4 verification commands, then tag: `git tag phase2-gate-<N>-rc<attempt>`.
2. Run the review from the worktree root (**Opus is the standard reviewer**):

```bash
claude -p --model claude-opus-4-8 \
  "ADVERSARIAL GATE REVIEW, Treasury Phase 2, GATE <N> (scope: waves per docs/handoff/CODEX-treasury-phase2-instruments-2026-07-11.md §3).
   Review ONLY the diff: git diff <previous-gate-tag-or-origin/dev>..HEAD.
   Binding references: docs/superpowers/specs/2026-07-10-treasury-phase2-instruments-echeancier-design.md (Rev 2 — §6 lock order, §7 posting tables, §20) and docs/superpowers/plans/2026-07-11-treasury-phase2-instruments-echeancier.md (Rev 2 — task Interfaces are contracts).
   Verify against code with file:line citations; hunt: lock-order inversions, movements without JEs or outside the port, afterCommit GL posting, float on money, missing idempotency, spec/plan deviations not recorded in the progress file, test gaps vs the plan's pinned assertions.
   Write the full review to docs/handoff/gate-reviews/GATE-<N>-rc<attempt>.md with a final verdict line 'VERDICT: APPROVE' or 'VERDICT: CHANGES-REQUIRED' plus numbered findings with severity."
```

3. **Escalation rule (owner's tiering):** re-run the SAME prompt with `--model claude-fable-5` ONLY when the Opus review (a) returns CHANGES-REQUIRED with any BLOCKER/HIGH finding on a money path (GL posting shapes, port `record()`/`transfer()` semantics, allocation/balance_due mutation, fiscal projections), or (b) says it is uncertain about a money-path behavior, or (c) you deviated from the plan on a money path (progress-file deviation entry exists for this wave). Fable 5 is for crucial financial/inventory-spine verification only — everything else stays on Opus.
4. CHANGES-REQUIRED → fix every finding (TDD — regression test first for each real defect), commit, bump `rc<attempt>`, re-run step 2. Repeat until `VERDICT: APPROVE`. A finding you believe is WRONG: rebut it in the progress file with code evidence and include the rebuttal in the next review prompt — never silently ignore.
5. APPROVE → `git tag phase2-gate-<N>`, log the verdict + review file path in the progress file, continue to the next wave.

**The only two STOP conditions** (report and halt instead of proceeding): a gate fails 3 consecutive rc attempts on the same BLOCKER (design-level contradiction — a human decision is needed), or the Wave-G precondition below is unmet when you reach it.

**Wave G (FE, Tasks 23–25) precondition:** the design-system unification sweep (`feat/design-system-unification`) must be MERGED to dev first. At Wave G start: `git fetch origin dev`; if the sweep is not on origin/dev, STOP and report (do not build FE against pre-sweep conventions). If it is: `git rebase origin/dev` (report conflicts, never resolve money-path conflicts silently), then follow the **post-sweep** conventions — PageHeader pattern and design tokens as they exist on dev at that moment. Verified 2026-07-11: the sweep currently touches NONE of Wave G's named files, but its remaining PageHeader pass may — re-check `git log origin/dev -- apps/web/src/features/treasury` before editing each file. Waves A–F are backend-only and have zero overlap — start immediately, no waiting.

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

`docs/handoff/treasury-phase2-progress.md` in the worktree — update after every task: files touched, test counts, verification output, deviations-with-justification, contradictions found (rule 2.11). Gate review files live in `docs/handoff/gate-reviews/` and are committed with the branch. This file travels with the branch and is the first thing each gate review reads.

## 7. End state

When Gate 4 is APPROVED: final progress-file entry (all gates, review files, rc counts, open notes), leave the worktree intact, report done. **The Claude session then runs the final whole-branch review** (orchestrated Opus/Sonnet multi-agent discovery + verification, Fable-tier orchestrator) **and owns the merge decision** — your gates do not replace it.
