# Dark-Factory Coordination — Design Spec (2026-07-05)

> Approved by owner 2026-07-05 (brainstorming session). Covers: VPS↔laptop
> coordination, YAML task board, repo cleanup, and the page-coverage (sweep)
> system. Supersedes the informal "handoff markdown + laptop memory" coordination.
> Companion: `docs/factory/WORKFLOW.md` (the process loop — unchanged by this
> spec except where noted), `docs/superpowers/audits/2026-07-05-unmerged-work-ledger.md`
> (cleanup input).

## Decisions (owner, 2026-07-05)

| Decision | Choice |
|---|---|
| VPS session model | **Scheduled autonomous sessions** (cron/systemd timer boots headless Claude) |
| VPS git authority | **Feature branches only** — never pushes `dev`; laptop orchestrator merges |
| Handoff surface | **GitHub PRs into `dev`** — settles owner-pending CI decision toward **Option B** (PR-based + branch protection) |
| Task board home | **Orphan branch `factory/board` in the erp repo** — worktree-checked-out on both hosts |
| Repo cleanup scope | All four tracks: root junk + .gitignore, handoff/superpowers reorg, branch/worktree pruning, memory corrections |
| Page-coverage scope | Route inventory (foundation) + sweep task generator + screenshot/design sweep (behavior smoke deferred) |
| erp-mobile rescue | **Owner pushes it himself** when in-flight work lands (explicitly NOT this factory's job) |

## 1. Task board — `factory/board` orphan branch

**Layout** (no code, ever):

```
tasks/T-0001-<slug>.yaml      # one file per task → near-zero merge conflicts
manifests/routes-web.yaml     # generated route inventory (apps/web)
manifests/routes-pos.yaml     # generated route inventory (apps/pos)
sweeps/<date>-<topic>.yaml    # per-route sweep checklists
BOARD.md                      # generated human-readable summary (never hand-edited)
```

Both hosts: `git worktree add ../erp.board factory/board` (laptop) / clone-side
equivalent (VPS). The branch is created with `git checkout --orphan factory/board`.

**Task schema** (validated by the board CLI; unknown keys rejected):

```yaml
id: T-0001                  # immutable, monotonic; slug in filename for humans
title: Fix auth 401 infinite loop on expired token
type: bug                   # bug | feature | chore | sweep | decision
track: vps                  # vps | laptop | mobile   (host eligibility)
status: ready               # backlog → ready → claimed → in-progress →
                            # ready-for-review → merged
                            # side states: blocked | changes-requested
priority: P1                # P0 (drop everything) … P3
claimed_by: {host: vps, session: <id>, at: 2026-07-05T14:00Z}   # null until claimed
branch: fix/auth-401-loop   # set at claim time
pr: 134                     # set when opened
spec: docs/superpowers/specs/…    # repo-relative links into dev
plan: docs/superpowers/plans/…
reviewers: [tenancy-authz-reviewer]   # domain audit agents that must gate (WORKFLOW.md Stage 4 map)
done_criteria:
  - "401 clears stored token + redirects to login"
  - "covering Vitest test"
depends_on: []              # task ids; a task is claimable only when deps are merged
blocked_on_owner:           # escalation slot; null unless status == blocked
  question: "…"
  options: ["…", "…"]
log:                        # append-only breadcrumbs; heartbeat for stall detection
  - {at: 2026-07-05T14:05Z, host: vps, note: "worktree created off 6b356be6d"}
```

**Board CLI** — `scripts/factory/board.sh` (thin, dependency-free bash + `yq` or a
small Node script; lives on `dev` so it ships with the code, operates on the
`../erp.board` worktree):

- `board list [--track vps --status ready]` — filtered table
- `board new` — scaffold a task file from template, assign next id
- `board claim <id>` — set claimed_by/branch/status, commit, **push**; a rejected
  push = lost race → pull --rebase, pick next task. **Git push atomicity IS the lock.**
- `board update <id> --status … --note …` — status flip + log append, commit, push
- `board sweep new "<description>" [--apps web,pos]` — see §4
- `board render` — regenerate BOARD.md

**Stall recovery:** a task in `claimed`/`in-progress` whose newest `log` entry is
older than 12h is presumed abandoned; any session may reset it to `ready`
(logging that it did). Sessions MUST append a log entry at least at claim,
mid-task milestones, and completion.

## 2. VPS autonomous loop

**One-time setup (finish before first scheduled run):** Codex CLI + auth;
`pnpm exec playwright install --with-deps chromium`; local stack per memory
`reference_local_db_per_tenant_demo_launch` (docker compose infra, token-auth
recipe, multi-queue worker); `gh` CLI authenticated; **dev-push-guard hook
installed on the VPS too**; board worktree.

**Schedule:** systemd timer (interval owner-tunable; start nightly window) runs a
wrapper that: pulls `dev` + `factory/board`, then boots headless Claude with the
boot prompt below. Wrapper enforces a wall-clock kill and skips the run if the
previous one is still alive.

**Boot prompt contract** (stored as `scripts/factory/vps-boot-prompt.md` on dev):
1. Read `docs/factory/WORKFLOW.md`; confirm host = VPS (full-suite allowed).
2. `board claim` highest-priority `track: vps, status: ready` task (deps merged).
   None available → exit cleanly.
3. Run the standard loop: isolated worktree **off the fetched `origin/dev` tip**
   (verify base — stale-fork gotcha), TDD, domain reviewer(s) per task file,
   `PREFLIGHT_SCOPE=full`.
4. Push the feature branch; `gh pr create --base dev` with task id, gate evidence,
   reviewer verdicts in the body; `board update --status ready-for-review`.
5. Blocked → `board update --status blocked` with a concrete `blocked_on_owner`
   question; exit (or claim the next task if budget remains).

**Hard rails:** one task per session (start conservative); never push `dev`;
never touch `apps/erp.procurement-v2` / `apps/erp.p2p-flow` (other session's);
token budget per run; all output lands as branch + PR + board updates — nothing
lives only in the session transcript.

## 3. Laptop orchestrator loop + CI (decision B implementation)

Laptop session boot: pull board → triage in order: (1) `blocked_on_owner` items
(surface to owner), (2) `ready-for-review` PRs — read reviewer verdicts, merge to
LOCAL dev, promote to `origin/dev` as clean fast-forward batches (discipline
unchanged), trigger the explicit Dokploy web deploy, staging-verify (bundle
fingerprint + smoke), flip tasks to `merged`, (3) convert new owner asks into
board tasks.

**CI changes (this spec authorizes):** enable branch protection on `dev`
(PRs required once the factory is proven — timing owner-controlled); widen heavy
CI jobs (PHPUnit-pgsql, Vitest, build) to PRs targeting `dev`; bump CI PHP to 8.4
per the standing owner-pending item if the owner confirms in review.

## 4. Page-coverage system

- **Route manifest generator** — `scripts/factory/gen-route-manifest.mjs` parses
  `apps/web/src/routes/index.tsx` (route registry) and the `apps/pos` router into
  `manifests/routes-*.yaml`: `{path, name, module_gate, permission, component}`.
  A preflight/CI **drift check** regenerates and diffs — a new page that isn't in
  the committed manifest fails the check, so the inventory can never silently rot.
- **Sweep generator** — `board.sh sweep new "<check/fix description>"` creates
  `sweeps/<date>-<slug>.yaml`: the description + one row per manifest route
  (`route / status: pending|done|n-a / notes`). "Fix X on every page" is now an
  enumerable artifact; the sweep is `done` only when zero rows are `pending`.
  Sweeps are ordinary board tasks (`type: sweep`) and can be split across sessions.
- **Screenshot/design sweep** — standalone playwright-core script (NOT the shared
  MCP browser): logs into the seeded demo tenant, walks every manifest route,
  captures full-page screenshots to a dated folder + generated `index.html`
  gallery; runs on the VPS; consecutive runs diffable for design regression review.

## 5. Repo cleanup — one-time pass + standing rules

Input: `docs/superpowers/audits/2026-07-05-unmerged-work-ledger.md`.

1. **Rescue** (laptop, first): commit + push `feat/owner-dashboard-demo`'s
   uncommitted live-sales feature; rebase onto current dev afterwards.
   (erp-mobile: owner handles personally — out of scope.)
2. **.gitignore**: root `*.png` screenshots (new home: `docs/sessions/screenshots/`,
   gitignored), `apps/api/storage/tenant*`, `.codex-*.md`, `anchor`, phpunit
   triage configs. Fix or revert the `pnpm-workspace.yaml` placeholder line.
3. **Docs reorg**: `docs/handoff/archive/` for completed handoffs; commit the
   untracked keepers (main worktree + merged worktrees' superpowers docs, deduped);
   standing rule 15 (session artifacts → `docs/sessions/`, gitignored) unchanged.
4. **Branch/worktree pruning** per ledger: bulk-delete the ~130 verified-merged
   refs (scripted, from the scout's lists); prune merged worktrees
   (`erp.pos-stock`, treasury quartet, `erp.procurement`, `erp.unified-imports`,
   stale `.claude/worktrees/*`) after salvaging their docs; keep live ones
   (`erp.p2p-flow`, `erp.procurement-v2`, `erp.accounting-gl`, `erp.banks`,
   `erp.board`). Owner-look items (platform-integration-bundle, focus-traps PR
   check, khalil-pos) become `type: decision` board tasks.
5. **Memory corrections** per ledger table (5 stale "not merged" entries, T11
   PR #132 removal, GL SHA update).

## Error handling

- **Claim races** → push rejection → pull --rebase → next task (never force-push board).
- **Session crash mid-task** → 12h log-staleness rule → task reset to `ready`;
  orphaned worktree/branch noted in the reset log entry for cleanup.
- **PR conflicts with moved dev** → VPS rebases its branch onto fetched dev tip
  before opening the PR; conflicts it can't resolve → `blocked` with details.
- **Board schema violations** → CLI refuses to write; sessions never hand-edit YAML.
- **VPS full-suite red from pre-existing failures** → reconcile against
  `docs/superpowers/audits/2026-07-04-preexisting-test-failures-backlog.md`
  before blaming the diff (WORKFLOW.md Stage 5 note).

## Testing

- Board CLI: bats/vitest tests for schema validation, id assignment, claim/update
  round-trip against a throwaway git remote (fixture repo).
- Manifest generator: snapshot test — known routes file in, expected YAML out;
  drift check exercised in preflight.
- Screenshot sweep: smoke-tested against 3 routes locally before first full VPS run.
- First autonomous VPS run is **supervised** (owner/orchestrator watching) before
  the timer goes unattended.

## Build order

- **Phase 1 (laptop, now):** board branch + schema + CLI + seed initial tasks;
  cleanup pass §5 incl. dashboard-demo rescue. Useful immediately, before any autonomy.
- **Phase 2:** route manifest generator + drift check + sweep generator +
  screenshot sweep script.
- **Phase 3:** VPS setup completion + boot prompt + wrapper/timer + first
  supervised run.
- **Phase 4:** branch protection + CI widening (decision B), then unattended schedule.

## Out of scope (tracked elsewhere)

- Loyalty completion (enrollment flow in boss app, cashier enroll permission,
  program seeding) — dedicated session; prompt in
  `docs/handoff/PROMPT-loyalty-completion-roadmap.md`.
- Otospex/automotive commonality analysis (procurement/RFQ reuse), catalog-search
  + techdoc-ingestion pipeline verification (parent syneriva repo) — owner-slated
  for tomorrow; will enter the board as `type: decision`/investigation tasks.
