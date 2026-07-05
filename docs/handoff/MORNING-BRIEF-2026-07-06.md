# Morning Brief — 2026-07-06 (night-shift results)

> Everything below happened overnight per your "get as much done as you can"
> directive. `origin/dev` = `1c79a31bf` (was `6b356be6d`). All promotions were
> clean fast-forwards; nothing was force-pushed; PR #197 awaits YOUR merge call.

## Decisions you need to make (in order of urgency)

1. **Merge PR #197** — B2 auth-401 loop fix. TDD green, typecheck/lint clean,
   tenancy-authz-reviewer APPROVED (verdict on the PR). First PR through the new
   factory flow. One noted trade-off: hard redirect drops the `state.from`
   return-path (deliberate, for loop-breaking robustness).
2. **Review the dark-factory spec** you approved conversationally:
   `docs/superpowers/specs/2026-07-05-dark-factory-coordination-design.md`
   (one implementation deviation documented in §1: route manifests live in the
   code repo for drift-check atomicity, not on the board branch).
3. **Board decision tasks T-0006..T-0009** (`node scripts/factory/board.mjs list --status blocked`):
   remote-branch bulk-prune approval (~130 verified-merged refs), platform-integration-bundle
   salvage, focus-traps PR check, khalil-pos ask.
4. **erp-mobile push** — still yours: 3 branches (main/design-system/feat-mobile-expense-logging)
   exist ONLY on your laptop. `git push origin main design-system feat/mobile-expense-logging`.
5. Standing gates from OWNER-BRIEF-2026-07-04 remain open (PHP 8.4 CI pin,
   autoDeploy flip, GL A1, procurement OQ3/stability call, margin FE UX).

## What shipped tonight (all on origin/dev)

- **Factory board live**: orphan branch `factory/board` (worktree `../erp.board`),
  13 seeded tasks, CLI `scripts/factory/board.mjs`
  (`list|new|claim|update|render|reset-stale|sweep`), 24 node:test tests. Claim
  lock = git push atomicity with fetch+reset recovery (adversarial-review-hardened:
  no rebase anywhere, poisoned YAML can't brick the board, 12h stall reset).
- **Page-coverage system**: `gen-route-manifest.mjs` → `scripts/factory/manifests/`
  (web: 246 routes, module/permission gates captured incl. `moduleKey`; POS: 9
  routes + 9 phase screens); preflight now FAILS on manifest drift; `board.mjs
  sweep new` fans any "fix X everywhere" into a per-route checklist;
  `screenshot-sweep.mjs` smoke-verified against the live local stack.
- **B2 fixed** (PR #197, see above). **B1 (POS add-customer) NOT touched** —
  needs the Tauri app + your presence (board T-0001, P0 laptop).
- **Repo cleanup**: root screenshots/junk gone + gitignored; 40 stranded docs
  committed, 32 archived to `docs/handoff/archive/`, 81 salvaged from dead
  worktrees; 15 worktrees + ~20 merged local branches pruned. Remaining branches
  are all deliberate keeps.
- **Rescue**: dashboard live-sales feature committed + pushed
  (`feat/owner-dashboard-demo` @ `414db3771`); VPS task T-0003 will rebase it.
- **Docs**: spec + plan (+A1-A12 amendments) + `docs/factory/LAUNCH-PLAN-2026-07.md`
  (3-track split toward "Bill the Standard" onboarding — CONFIRM that name's
  spelling) + `scripts/factory/vps-boot-prompt.md` (paste-ready) +
  `docs/handoff/PROMPT-loyalty-completion-roadmap.md` (your requested loyalty session).
- **Loyalty investigation (I1)**: enrollment exists (web admin full UI; POS
  silent auto-enroll needs phone + ACTIVE program). Launch blockers: no seeded
  program for the parapharmacy tenant, cashiers lack enroll rights (your
  decision: grant by default — in the prompt), phone mandatory, double-earn race
  (board T-0005), pay-with-points absent from Tauri POS. Details in the prompt file.
- **Memory corrected** (5 stale "not merged" claims; T11 PR#132 archived).

## VPS next steps (when you're ready)

Install Codex CLI + `pnpm exec playwright install --with-deps chromium` + gh auth
+ dev-push-guard hook + `git worktree add ../erp.board factory/board`; then run a
FIRST SUPERVISED session with `scripts/factory/vps-boot-prompt.md`. Board task
T-0013 tracks the install verification. Timer/schedule comes after the
supervised run passes (spec §2).

## Gotchas discovered tonight (also in memory)

- pnpm: repo pins `pnpm@9.14.2` but node_modules was built from a pnpm-11 store —
  `pnpm add -Dw` fails with store mismatch; workaround documented in
  memory `project_dark_factory_board.md`.
- The one red web test (`CompanyProvider.tenantScope.test.tsx` isPrimary fixture)
  is PRE-EXISTING on origin/dev — proven by stash/re-run. Not from tonight's work.
