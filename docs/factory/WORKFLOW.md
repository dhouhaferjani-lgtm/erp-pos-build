# Delivery-Factory Workflow

> The runbook every VPS/orchestrator session boots from. Codifies the delivery
> loop the team already runs informally. Operational, not aspirational — every
> reference below points at a file that exists on `dev`. Keep it that way: if you
> change the loop, change this doc in the same commit.
>
> **Scope:** how a change goes spec → plan → review → build → review → gate →
> merge → staging → docs. Domain rules live in `CLAUDE.md` (rules 1–21) and the
> `docs/conventions/` set; this doc is the *process* on top of them.

---

## Session boot (read in this order)

A fresh factory session reads, top to bottom, before touching anything:

1. **This doc** (`docs/factory/WORKFLOW.md`) — the loop + the host/capability rules.
2. **`docs/PRODUCT-BIBLE.md`** — the factory's map: vision, module status (§3),
   business flows (§4), tech-debt register (§8). "If it is not in that document,
   it is not decided."
3. **Memory** `~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/MEMORY.md`
   — the lean always-loaded index of in-flight work + critical rules; follow its
   links on demand.
4. **Active handoffs** under `docs/handoff/` — the specific task you're resuming.

Then confirm your host (laptop vs VPS — see the capability table) and your
worktree base (see Stage 3).

---

## Host capability table (laptop vs VPS)

| Capability | Laptop (owner's) | VPS / CI |
|---|---|---|
| Full PHPUnit suite | ❌ **FORBIDDEN** — exhausts memory, crashes the machine | ✅ `PREFLIGHT_SCOPE=full` |
| Scoped PHPUnit (`PREFLIGHT_TEST_PATHS='…'`) | ✅ default (`paths`) | ✅ |
| Tauri (IziPOS desktop) builds | ❌ heavy; do on VPS | ✅ |
| Playwright smoke | ⚠️ one shared MCP browser only (see rules) | ✅ headless |
| Owner-only decisions (see gates step) | ✅ owner present | ❌ escalate, don't guess |
| `git push origin dev` | via dev-push-guard hook | via dev-push-guard hook |

Rule of thumb: the laptop is for authoring + scoped verification; the VPS is for
full-suite runs, Tauri builds, and unattended smoke.

---

## Standing process rules (always in force)

- **Tests by path on the laptop.** Never run the full suite locally. Use
  `PREFLIGHT_TEST_PATHS='tests/Feature/Foo tests/Unit/Bar' ./scripts/preflight.sh`.
  Full-suite = VPS/CI only.
- **No `git add -A` in the main worktree.** Stage explicit paths. The main
  worktree carries untracked screenshots, storage dirs, and session scratch that
  must never be committed.
- **Work in an isolated `git worktree`, never in a shared `dev` worktree**
  (CLAUDE.md rule 21).
- **Playwright MCP is ONE shared browser.** Parallel agents must NOT drive the
  MCP browser concurrently — each parallel agent spins up its own standalone
  `playwright-core` instance. Reserve the MCP browser for a single foreground
  session.
- **Codex reviews go to a FILE, not inline** (`docs/handoff/CODEX-REPORT-*.md`);
  the detached runtime truncates inline output.
- **Never use Fable for research subagents** — sonnet/opus only. Fable is for
  narrow scripted work, not investigation or review.
- **Adversarial plan review BEFORE dispatching implementation** is a standing
  owner rule (Stage 2).

---

## The loop

Each stage: **purpose · who runs it · entry · exit · on failure.**

### Stage 1 — Spec + plan

- **Purpose:** turn an owner ask into a written spec and an ordered, TDD-shaped
  implementation plan.
- **Who:** orchestrator (may delegate drafting to a sonnet/opus subagent).
- **Entry:** an owner-scoped ask; PRODUCT-BIBLE consulted for where it fits.
- **Exit:** spec in `docs/superpowers/specs/`, plan in `docs/superpowers/plans/`
  (task-by-task, each task naming its failing test first).
- **On failure:** ambiguous scope → ask the owner; do not expand scope past what
  was requested (CLAUDE.md rule 4).

### Stage 2 — Adversarial plan review (BEFORE build)

- **Purpose:** catch spec/plan defects before any code is written — the cheapest
  place to fix them. Standing owner rule.
- **Who:** a Claude adversarial reviewer (opus) or Codex-to-file; orchestrator
  reads the verdict.
- **Entry:** spec + plan committed.
- **Exit:** review verdict READY (blockers resolved) recorded to a file.
- **On failure / if skipped:** if the plan was NOT adversarially reviewed before
  build, the **merge gate (Stage 6) must run it late and can BLOCK the merge**.
  Late review is a fallback, not the norm.

### Stage 3 — TDD implementation (isolated worktree)

- **Purpose:** implement the plan test-first in an isolated workspace.
- **Who:** a Claude subagent **or** Codex CLI, one task at a time.
- **Entry:** an approved plan; a `git worktree` off the intended `dev` tip.
- **Worktree base gotcha — VERIFY FIRST, EVERY TIME.** The harness sometimes
  bases a worktree on a **stale fork** (the 2026-07-04 incident: worktrees based
  on `5cf61a6fc`, a docs fork, not the real `dev` tip). Before working:
  `git log -1 --oneline` and `git rev-parse HEAD`; confirm it matches the
  intended `dev` tip. If not, re-base:
  `git fetch /Users/houssamr/Projects/syneriva/apps/erp dev && git checkout -b <branch> FETCH_HEAD`.
- **Codex cannot `git commit` in sandboxed worktrees → task-log protocol.** Codex
  writes a task log (`docs/handoff/CODEX-REPORT-*.md`) describing its diff; a
  Claude agent then applies + commits it with explicit paths.
- **Exit:** feature complete on its own branch, red→green→refactor per task,
  covering tests written; **no** `// TODO` / placeholder code (rule 1).
- **On failure:** debug in place; do not mark complete on a red test (rule 5).

### Stage 4 — Domain audit-agent review

- **Purpose:** an adversarial, code-grounded reviewer gates the change against its
  domain's invariants before it can merge.
- **Who:** the reviewer bench in `.claude/agents/` (all opus, tools Read/Grep/
  Glob/Bash). **Contract (identical for all):** they **cite `file:line` they
  actually read**, they say "cannot verify" rather than assert from memory, they
  **gate** with a verdict, and they **NEVER merge** — a human/orchestrator merges.
- **Map change domain → reviewer(s)** (a change can need SEVERAL; dispatch all
  that apply):

  | Change touches… | Dispatch |
  |---|---|
  | treasury / payments / expenses / cash drawers / GL / journal entries | `treasury-reviewer` |
  | fiscal hash-chain / POS / device events / projections | `fiscal-pos-reviewer` |
  | inventory / WAC costing / stock movements / batch-FEFO / opening stock | `inventory-costing-reviewer` |
  | multi-tenancy / permissions / module-gating / route middleware | `tenancy-authz-reviewer` |
  | unified imports / opening-balance import / price resolution / result workbook | `imports-reviewer` |

  Example: a POS refund that moves cash **and** restocks → `fiscal-pos-reviewer`
  **+** `treasury-reviewer` **+** `inventory-costing-reviewer`.
- **Entry:** feature branch built, self-tested green (scoped).
- **Exit:** every dispatched reviewer returns APPROVED (spec ✅ + quality
  APPROVED).
- **On failure:** any **CHANGES-REQUESTED** loops back to **Stage 3**. Fix, then
  re-dispatch the same reviewer(s).

### Stage 5 — Gates

- **Purpose:** mechanical proof of "green" before a human merge decision.
- **Who:** the implementing agent, then orchestrator confirms.
- **Run `./scripts/preflight.sh`** — scope per host:
  - Laptop: default `PREFLIGHT_SCOPE=paths` (+ `PREFLIGHT_TEST_PATHS='…'` for the
    covering tests). **A `paths` run with no paths SKIPS PHPUnit and is NOT a full
    green** — the script says so loudly.
  - VPS/CI: `PREFLIGHT_SCOPE=full` (entire PHPUnit suite; **crashes the laptop**).
  - Both modes unconditionally run: Pint, PHPStan L8, TypeScript type-gen +
    drift check, `pnpm typecheck`, ESLint, TanStack key audit (`pnpm audit:keys`),
    Vitest, fiscal-fixture parity, §14.3 chokepoint gate.
- **Guard scripts (must pass):**
  - **TanStack query-key audit** — `apps/web/tools/audit-tanstack-keys.mjs` (via
    `pnpm audit:keys`); enforces `tenantScopedKey([...])`.
  - **POS local-cache audit** — `apps/web/tools/audit-pos-local-cache.mjs`.
  - **Deptrac module-boundary ratchet** — config `apps/api/deptrac.yaml`, ceiling
    `apps/api/deptrac.baseline.json`, runner `apps/api/tools/deptrac-ratchet.php`.
    Baseline is a **ceiling** — regenerate only downward, never up.
  - **§14.3 chokepoint gate** — `apps/api/scripts/check-saleReceipt-chokepoints.sh`.
- **PHPStan L8 / Pint / ESLint / typecheck** — all green (part of preflight).
- **Entry:** Stage 4 APPROVED.
- **Exit:** preflight green at the host-appropriate scope + all guards pass.
- **On failure:** fix and re-run; a red gate is never waved through.

#### Two OWNER-PENDING decisions (until decided, the dev gate is LOCAL preflight)

Per `docs/superpowers/audits/2026-07-04-factory-quality-gates-decision-memo.md`,
two gate decisions are **owner-pending** — do NOT assume either is settled:

1. **CI trigger model — Option A vs Option B.** Today a direct `git push origin
   dev` runs **ZERO CI**; PR→dev runs only light jobs (no PHPUnit/Vitest/build).
   Option A = trigger CI on pushes to `dev` (+ widen heavy-job guards); Option B
   (recommended) = PR-based merges into `dev` + branch protection. **Until the
   owner picks, the dev-iteration gate is the LOCAL `scripts/preflight.sh`, by
   design** — "green" on `dev` is asserted locally, not by CI.
2. **PHP version — bump CI 8.3 → 8.4.** CI pins PHP 8.3 while the Dockerfile
   ships 8.4; an 8.4-only behavioral delta is invisible to CI. One-line
   `PHP_VERSION` env patch is staged and owner-pending.

Related pre-existing red spots (so "green = green"):
`docs/superpowers/audits/2026-07-04-preexisting-test-failures-backlog.md` lists
known failing tests on `dev` (REAL BUG vs TEST-DEBT). A naive full run is red for
reasons unrelated to your change — reconcile against this backlog before blaming
your diff. (Also: `.github/workflows/react-doctor.yml` is currently **untracked**
in the main worktree — it will not run in CI until committed.)

### Stage 6 — Orchestrator-gated merge to LOCAL dev → promote to origin/dev

- **Purpose:** land the change on the shared branch without diverging it.
- **Who:** orchestrator only.
- **Entry:** Stages 4 + 5 green. If Stage 2 was skipped, run the late adversarial
  plan review HERE and honor a BLOCK.
- **Merge discipline (CLAUDE.md rule 21):**
  - Merge into **LOCAL `dev` first**; promote to **`origin/dev` only as a clean
    fast-forward**, in verified batches.
  - Before every promotion: `git fetch origin dev`; if local `dev` is behind/
    diverged, `git merge origin/dev` (or rebase) FIRST, then push.
  - **Never force-push `dev`**; never `reset --hard`/`branch -f` the shared
    pointer onto a feature branch. Enforced by the **dev-push-guard** hook
    (`.claude/hooks/git-dev-push-guard.sh`, wired in `.claude/settings.json`).
  - **Commit and push are SEPARATE commands** (never chained) so the hook can
    inspect the push.
- **Staging web deploy is NOT automatic.** Staging web has `autoDeploy=false`, so
  **every promotion to `origin/dev` needs an explicit Dokploy web deploy**
  (application id `mY6P_PHb4pw-2LdG1Y7Ml`) — until the owner flips that decision.
- **Exit:** `origin/dev` fast-forwarded; staging web deploy triggered.
- **On failure:** hook blocks a diverged/force push → run the exact reconcile
  command it prints, then retry. Never work around the hook.

### Stage 7 — Staging verify

- **Purpose:** confirm the promoted code is actually live and works end-to-end.
- **Who:** orchestrator (VPS for unattended; laptop uses the one shared browser).
- **Staging behavior:** **API auto-deploys on push; web does NOT** (Stage 6).
  Per-tenant `php artisan migrate` and permission-seeder sync are **not** part of
  a deploy — run them explicitly when a migration/permission landed (recurring
  gap: staging tenants missing `uom.view` → 403; bonus-fields migration pending).
- **Verify web bundle freshness — do NOT trust "it deployed".** On 2026-07-04 the
  staging web bundle sat **78 commits stale, undetected**. Check BOTH:
  1. **Asset-hash** changed vs the previously-served bundle, AND
  2. **Feature fingerprint** — `grep` the served bundle for a string unique to the
     change you just shipped.
- **Playwright smoke** the critical path (log in, ring a sale, submit the changed
  form). One shared MCP browser; parallel = standalone `playwright-core`.
- **Entry:** Stage 6 done, web deploy triggered.
- **Exit:** fresh bundle confirmed (both checks) + smoke green.
- **On failure:** stale bundle → re-trigger the Dokploy web deploy and re-verify;
  smoke red → open a defect, loop back.

### Stage 8 — Docs close-out

- **Purpose:** keep the factory's map and the ERP-integration contract truthful.
- **Who:** orchestrator.
- **Do:**
  - **PRODUCT-BIBLE re-verify note** — update `docs/PRODUCT-BIBLE.md` (module
    status §3, flows §4, tech-debt §8, decisions log §9) with a dated note; this
    is the loop's LAST step.
  - **REALIGNMENT-LOG when API/DB shapes changed** — if you changed a **published
    API or canonical DB shape**, log it. **This log lives in the PARENT `syneriva`
    repo, NOT the erp repo:** absolute path
    `/Users/houssamr/Projects/syneriva/docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`
    (from the erp repo root `apps/erp/`, that is `../../docs/03-ERP-INTEGRATION/
    REALIGNMENT-LOG.md`). The rule is parent-repo `CLAUDE.md` rule 9 — "don't
    assume the ERP team will notice the commit."
  - **Memory / handoff updates** — move finished lines from MEMORY.md Active Work
    to a domain index / ARCHIVE.md; close or update the `docs/handoff/` doc.
- **Entry:** Stage 7 green.
- **Exit:** Bible note written; REALIGNMENT-LOG appended if applicable; memory +
  handoffs current.
- **On failure:** never skip — an undocumented shape change is how the ERP team
  ships against a stale contract.

---

## One-line summary

spec → **adversarial plan review** → TDD in an isolated (base-verified!) worktree
→ **domain reviewer(s) gate** → preflight + guards (scope per host) → orchestrator
fast-forward merge to `origin/dev` + explicit Dokploy web deploy → staging verify
(bundle-freshness + smoke) → PRODUCT-BIBLE + REALIGNMENT-LOG + memory.
