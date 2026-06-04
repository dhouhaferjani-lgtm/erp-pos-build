# Codex Execution Brief — Branch Tax-ID P0 (Codex implements · Opus reviews headlessly · Playwright visual)

**You are Codex, running in your native app. Implement the Branch Tax-ID P0 plan end-to-end, with a headless Opus adversarial review at every task gate and Playwright visual verification, then emit a single summary for the human to bring back to the Opus orchestration session.**

Worktree root (cd here first, stay here): `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id`
Branch: `feat/branch-tax-id-spec` (already checked out; do all work here; do NOT push).

## 0. Read these first (absolute paths)
- PLAN (your task list, no-placeholder TDD): `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md`
- SPEC (authority): `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/specs/2026-06-04-branch-tax-id-design.md`
- Conventions: the repo `apps/erp/CLAUDE.md` (TDD; strict types, no `mixed`/`any`; constructor injection only, never `app()`; enums for status/type; routes middleware; `t()` for all frontend strings + i18n in 3 places; design tokens for Tailwind colors; run `./scripts/preflight.sh` before considering a task done).

## 1. Ground rules
- **TDD, one task at a time, in plan order** (Task 1 → 18). For each task: write the failing test, run it (confirm RED), implement minimal code, run it (confirm GREEN), then the task's own checks, then commit with the message in the plan.
- **Do not skip the plan's commands.** Backend: `cd apps/api && php artisan test <file>`, `./vendor/bin/pint`, `./vendor/bin/phpstan`. Frontend: `cd apps/web && pnpm test/lint/typecheck`. Device: `cd apps/pos && pnpm test`.
- **Never push. Never touch `main`/`dev`.** Commit on `feat/branch-tax-id-spec` only.
- **Phase gate:** finish ALL of Phase 1 (Tasks 1–14) + its preflight green before starting Phase 2 (Tasks 15–18). Phase 1 is fiscally inert; Phase 2 is value-only (no fiscal payload version bump — do NOT regenerate golden fixtures; the parity check must stay green).
- If the plan flags an executor choice (e.g. Task 10 render-vs-accessor seam, Task 17 `useTerminalStore` access idiom), pick the option that matches the file's existing pattern and note it in the summary.

## 2. The per-task loop (MANDATORY at every task)

For each Task N:
1. Implement per the plan (RED → GREEN → task checks → commit).
2. **Headless Opus adversarial review of the task diff** (command below). Opus writes a review file and prints a verdict.
3. Parse the verdict:
   - **APPROVE** → proceed to Task N+1.
   - **APPROVE-WITH-EDITS** → apply the edits, re-run tests, amend/extend the commit, then proceed.
   - **NEEDS-REWORK** → fix every BLOCKER/MAJOR, re-run tests, commit the fix, and **re-run the Opus review once**. If it is STILL NEEDS-REWORK after the second pass, **STOP this task**, record the sticking point in the summary, and continue to the next *independent* task if one exists; otherwise halt and write the summary.
4. Record the verdict + review-file path in the summary.

### 2a. Headless Opus review command (run from the worktree root)
Opus is the Claude Code CLI in print/headless mode. Use the most capable Opus model.

```bash
TASK=NN   # zero-padded task number, e.g. 01
RANGE="HEAD~1..HEAD"   # the commit(s) for this task; widen if the task made several commits
git diff "$RANGE" > "/tmp/branch-tax-id-task-${TASK}.diff"

claude -p \
  --model claude-opus-4-8 \
  --permission-mode acceptEdits \
  "You are an ADVERSARIAL code reviewer. Review ONLY the diff in /tmp/branch-tax-id-task-${TASK}.diff for Task ${TASK} of the plan at \
/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md, \
checked against the spec /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/specs/2026-06-04-branch-tax-id-design.md \
and apps/erp/CLAUDE.md conventions. Verify claims against the real code in the worktree (read files as needed). \
Be skeptical: hunt for TDD violations (test not actually asserting behavior), use of app()/mixed/any, missing i18n t() keys, hardcoded Tailwind colors, \
fiscal-payload schema/version drift, fallback-logic bugs (branch-vs-company), and anything that diverges from the plan/spec. \
Classify every finding BLOCKER/MAJOR/MINOR/NIT with file:line. End with exactly one line: 'VERDICT: APPROVE' or 'VERDICT: APPROVE-WITH-EDITS' or 'VERDICT: NEEDS-REWORK'. \
SAVE the full review to /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/reviews/2026-06-04-P0-task-${TASK}-opus-review.md and also print the VERDICT line to stdout."
```

Notes:
- If `--permission-mode acceptEdits` is not permitted in your environment, use `--dangerously-skip-permissions` (headless, this worktree only) OR drop the "SAVE to file" instruction and redirect stdout to the review file yourself: append ` > docs/superpowers/reviews/2026-06-04-P0-task-${TASK}-opus-review.md`.
- Commit each review file alongside (or right after) the task: `git add docs/superpowers/reviews/2026-06-04-P0-task-*-opus-review.md && git commit -m "review(branch-tax-id): Opus adversarial review task ${TASK}"`.
- If `claude` is not on PATH, stop and tell the human (they must enable the headless Opus reviewer); do not fabricate reviews.

## 3. Visual testing with Playwright (your own browser)

After **Phase 1** is green, before opening the Phase-1 PR, do real visual verification with Playwright (use your browser tooling):

1. **Boot the stack.** Start services per the repo (`docker compose up -d` for postgres/redis if needed), then `cd apps/api && php artisan serve --port=8000` and `cd apps/web && pnpm dev`. Seed a tenant/company (use an existing seeder, e.g. `DemoTenantSeeder`, or create one via the signup flow) with an **FR** company.
2. **Location settings form** (`apps/web` → Settings → Locations):
   - Create a `shop` location for the FR company with **no tax ID** → expect the form to show the branch-tax-ID **required** error (server 422 surfaced). Screenshot.
   - Create a `shop` with a **valid SIRET** (`73282932000074`) → expect success, and the tax ID visible on the saved location. Screenshot.
   - Create a `warehouse` with no tax ID → expect success (not required). Screenshot.
3. **Receipt rendering:** generate/preview a receipt for a sale at a branch whose `tax_id` overrides the company → confirm the **branch** tax id prints (not the company's). Screenshot. (If the receipt is only a PDF, render it and assert the branch value via the Task 10 test; note in summary.)
4. **POS device note:** the POS is Tauri; if a web-served build of `apps/pos` is runnable, drive it with Playwright to confirm a sale authored at the branch carries the branch `seller.tax_number`. If not feasible headlessly, rely on the Task 17/18 unit + parity tests and say so in the summary. Do NOT skip silently.
5. Save screenshots under `docs/superpowers/reviews/screenshots/2026-06-04-P0/` and reference them in the summary.

## 4. Final deliverable — the summary (the human brings this back to the Opus orchestration session for a SECOND check)

When all tasks (or all reachable tasks) are done, write:
`/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/docs/superpowers/reviews/2026-06-04-P0-codex-execution-summary.md`

It MUST contain:
- **Per-task table:** task #, name, status (DONE / BLOCKED), commit SHA(s), Opus verdict, review-file path, # rounds.
- **Any task that ended NEEDS-REWORK / BLOCKED**, with the exact sticking point and the last Opus findings.
- **Preflight results:** paste the final `apps/api` (pint/phpstan/test) and `apps/web` (lint/typecheck/test) and `apps/pos` (test) + fiscal fixture parity (`apps/pos/scripts/check-fiscal-fixture-parity.sh`) output (pass/fail + counts).
- **Playwright results:** what was exercised, pass/fail, screenshot paths, and explicitly what could NOT be tested visually (e.g. Tauri POS) and why.
- **Deviations from the plan** and any executor choices made (Task 10/17 seams, etc.).
- **Fiscal-safety attestation:** confirm NO golden fixtures changed and NO payload schema/version bump (Phase 2 is value-only).
- **Open questions for the Opus second check.**
- Final line: `READY FOR OPUS SECOND CHECK` (or `BLOCKED — see above`).

Commit the summary: `git commit -m "docs(branch-tax-id): Codex P0 execution summary"`.

## 5. Hard constraints recap
- No `push`, no `main`/`dev`, no fiscal golden-fixture regen, no payload version bump.
- Every task gets a real Opus headless review; never fabricate a review or a verdict.
- Strict typing, constructor injection, `t()` + i18n, design tokens, preflight green.
- If you get stuck or a thread/tool fails, record it in the summary rather than guessing — the human + Opus will resolve it on the second check.
