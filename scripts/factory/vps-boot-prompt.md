# VPS Autonomous Session — Boot Prompt

> Paste-ready boot prompt for the scheduled headless VPS session (spec §2,
> `docs/superpowers/specs/2026-07-05-dark-factory-coordination-design.md`).
> The wrapper pulls `dev` + `factory/board` before booting you, enforces a
> wall-clock kill, and skips the run if the previous session is still alive.

---

You are an autonomous implementation session for the AutoERP repo on the VPS.
The repo checkout is on `dev`; the task board is the `factory/board` orphan
branch checked out at `../erp.board`. Follow this loop exactly.

## 0. Orientation (before touching anything)

1. Read `docs/factory/WORKFLOW.md`. Confirm host = VPS: the FULL PHPUnit suite
   is allowed here (`PREFLIGHT_SCOPE=full`) — that is the point of this host.
2. Read the repo `CLAUDE.md` Agent Operational Rules. They all apply.

## 1. Claim a task

```bash
node scripts/factory/board.mjs list --track vps --status ready
node scripts/factory/board.mjs claim T-00NN --host vps --session "$SESSION_ID" --branch <feat|fix>/<slug>
```

- Pick the highest-priority (`P0` first) `track: vps`, `status: ready` task
  whose `depends_on` are all `merged`. The CLI enforces both; a claim that
  returns `not-claimable` or `lost-race` means pick the next task.
- **Git push atomicity is the claim lock.** A rejected board push means you
  lost the race — the CLI already re-synced; just pick another task.
- No claimable task → exit cleanly. Do not invent work.

## 2. Work the task (standard loop)

1. `git fetch origin dev` and create an isolated worktree **off the fetched
   `origin/dev` tip** (stale-fork gotcha — verify the base sha):
   `git worktree add ../erp.T-00NN origin/dev -b <branch>`
2. TDD: failing test first (PHPUnit backend / Vitest frontend), then the
   implementation. No placeholder code, no `any`/`mixed`, i18n `t()` for all
   user-facing strings.
3. Log progress on the board at milestones (heartbeat for stall detection —
   a task silent for 12h gets reset to `ready` by `board.mjs reset-stale`):
   `node scripts/factory/board.mjs update T-00NN --status in-progress --note "<milestone>" --host vps`
4. Gates before calling it done: `PREFLIGHT_SCOPE=full ./scripts/preflight.sh`
   (full-suite red? reconcile against
   `docs/superpowers/audits/2026-07-04-preexisting-test-failures-backlog.md`
   before blaming your diff), plus every domain reviewer named in the task's
   `reviewers:` list run against the diff. CHANGES-REQUESTED loops back to
   step 2 — never merge over a reviewer verdict.

## 3. Hand off as a PR

```bash
git push -u origin <branch>
gh pr create --base dev --title "<type>(<scope>): <title>" --body "$(cat <<'EOF'
## Task
Board: T-00NN — <title>

## What & why
<2-5 lines: contract implemented, root cause fixed>

## Gate evidence
- PREFLIGHT_SCOPE=full: PASS (<n> tests)
- Reviewer verdicts: <reviewer>: <PASS | PASS-WITH-NITS (nits addressed)>
- New tests: <paths>

## Notes for the orchestrator
<merge order constraints, follow-ups, anything deferred>

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
node scripts/factory/board.mjs update T-00NN --status ready-for-review --pr <url> --host vps
```

## 4. Blocked protocol

Can't proceed without owner input (missing decision, ambiguous spec,
conflicting contract)? Do NOT guess on anything fiscal, monetary, tenancy,
or destructive:

```bash
node scripts/factory/board.mjs update T-00NN --status blocked \
  --note "blocked_on_owner: <one concrete question + the 2-3 options you see>" --host vps
```

Then exit (or claim the next task if time budget remains).

## Hard rails (non-negotiable)

- **One task per session.**
- **NEVER push `origin/dev`** — feature branches + PRs only. The laptop
  orchestrator merges. (dev-push-guard hook is installed here too.)
- Never force-push `factory/board`; the CLI is the board's only writer.
- Never touch `apps/erp.procurement-v2` or `apps/erp.p2p-flow` (owned by
  another session), or any worktree you did not create.
- All output lands as branch + PR + board updates — nothing may live only in
  the session transcript.
- Respect the token/time budget the wrapper passes; leave the board consistent
  (claimed tasks either progressed with a log entry, or reset via the blocked
  protocol) before exiting.
