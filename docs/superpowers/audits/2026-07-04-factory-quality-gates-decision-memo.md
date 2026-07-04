# Factory Quality-Gates Decision Memo — 2026-07-04

> Phase 0.3 of the delivery-factory setup. Closes quality-gate holes so an
> autonomous agent fleet can trust "green means green". Two owner-gated decisions
> (CI trigger model + PHP version) are presented **decision-ready**: say "A" or
> "B" and "8.4" and the inline patches apply verbatim.

---

## ⚠️ Read first — worktree base mismatch

The handover said this worktree is "based on dev (8cb507faa)". It is **not**.

- This worktree's base commit is `5cf61a6fc` (a `.agents/` + product-discovery fork).
- `8cb507faa` (the real `dev` tip) is **NOT an ancestor** of this branch
  (`git merge-base --is-ancestor 8cb507faa HEAD` → false).
- Consequence: this worktree's `scripts/preflight.sh` and `.github/workflows/ci.yml`
  were **stale** relative to real `dev` — real `dev` has extra gates (TanStack key
  audit, fiscal-fixture parity, §14.3 chokepoint gate in preflight; a
  `chokepoint-gate` job in ci.yml).

**What I did about it:** I based the `preflight.sh` parameterization on the
**real dev@8cb507faa** version (read from the main worktree at
`/Users/houssamr/Projects/syneriva/apps/erp`), not the stale fork copy. The
resulting file's diff **against real dev** is exactly the `PREFLIGHT_SCOPE`
change and nothing else (verified with `diff`). So the `preflight.sh` deliverable
applies cleanly onto real `dev`.

**All CI / guard / test facts in this memo were read from the main worktree
(`factory/audit-agent-bench` @ `8cb507faa`)**, which is the real target the
factory cares about. The owner should land these deliverables onto real `dev`,
not onto this fork — or rebase this branch onto `8cb507faa` first.

---

## 1. Confirmed CI facts (with file:line cites)

Cites are into the **real dev** copy at `apps/erp/.github/workflows/ci.yml` and
`apps/erp/apps/api/Dockerfile`.

### Fact (a) — CI does NOT trigger on pushes to `dev`. **CONFIRMED.**

`.github/workflows/ci.yml:3-8`:

```yaml
on:
  push:
    branches: [main]              # post-merge confirmation only
  pull_request:
    branches: [main, dev]         # cheap checks on PR→dev; full pipeline on PR→main
  workflow_dispatch:              # manual full run from the Actions tab
```

- Push triggers fire on `main` only. There is no `push: branches: [dev]`.
- `dev` appears only under `pull_request`, i.e. CI runs on **PRs targeting `dev`**,
  never on a **direct push** to `dev`.
- Moreover, the heavy jobs are additionally gated OFF for PR→dev. Every heavy job
  carries the guard
  `if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && github.ref == 'refs/heads/main')`:
  - `backend-test` — `ci.yml:98`
  - `frontend-test` — `ci.yml:250`
  - `frontend-build` — `ci.yml:277`
  - `all-checks-pass` — `ci.yml:369`
- **Net effect:** a direct `git push origin dev` runs **zero** CI. A PR→dev runs
  only the lint/typecheck/lightweight jobs (backend-lint, backend-analyse,
  frontend-lint, frontend-typecheck, types-drift) — **no PHPUnit, no Vitest, no
  build.** The dev-iteration gate today is the **local** `scripts/preflight.sh`,
  by design.

### Fact (b) — CI pins PHP 8.3 while the Dockerfile ships PHP 8.4. **CONFIRMED.**

- CI: `.github/workflows/ci.yml:15` → `PHP_VERSION: '8.3'`, consumed by every
  `shivammathur/setup-php` step (`ci.yml:39, 72, 146, 325`).
- Dockerfile: `apps/api/Dockerfile:9` (`FROM php:8.4-cli-alpine AS composer-deps`)
  and `apps/api/Dockerfile:35` (`FROM php:8.4-fpm-alpine AS base`) — production
  images build and run on **PHP 8.4**.
- So static analysis, tests, and type-gen run under 8.3 in CI, but the artifact
  that actually ships runs under 8.4. Any 8.3↔8.4 behavioral delta (deprecations,
  type-coercion, new engine warnings) is invisible to CI.

---

## 2. Option A — trigger CI on pushes to `dev`

Add `dev` to the `push` trigger so every commit that lands on `dev` (including
direct pushes and post-merge fast-forwards) runs CI.

**Exact patch to `.github/workflows/ci.yml`:**

```diff
 on:
   push:
-    branches: [main]              # post-merge confirmation only
+    branches: [main, dev]         # post-merge confirmation on both trunks
   pull_request:
     branches: [main, dev]         # cheap checks on PR→dev; full pipeline on PR→main
   workflow_dispatch:              # manual full run from the Actions tab
```

**Important caveat if you pick A:** adding the push trigger alone still runs only
the *light* jobs on `dev`, because the heavy-job `if:` guards key off
`github.ref == 'refs/heads/main'`. To make a `dev` push run the **full** pipeline
(PHPUnit + Vitest + build), you must ALSO widen each heavy guard. Concretely, in
each of `ci.yml:98, 250, 277, 369` change:

```diff
-    if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && github.ref == 'refs/heads/main')
+    if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && (github.ref == 'refs/heads/main' || github.ref == 'refs/heads/dev'))
```

**Trade-offs of A:** catches breakage *after* it lands on `dev` (post-hoc, not
preventive). Direct pushes to `dev` are how the team works today
(local-first, fast-forward batches — CLAUDE.md rule 21), so A fits the current
workflow with zero process change. But it turns `dev` red *after* the fact — an
agent fleet reading "is dev green?" would see red only once bad code is already
on the shared branch, and there is no natural review anchor for an audit agent.

---

## 3. Option B — PR-based merges into `dev` (RECOMMENDED)

Route all merges into `dev` through PRs instead of direct pushes. This is the
model the factory handover prefers, because a PR is a durable **review anchor**:
audit/review agents attach findings to a PR, CI status is a first-class signal on
the PR, and "green" is asserted *before* code reaches the shared branch
(preventive, not post-hoc).

**What changes in `ci.yml`:** potentially **nothing**. CI already runs on
`pull_request: branches: [dev]` (`ci.yml:7`). The only question is *how much*
runs on a PR→dev. Two sub-options:

- **B0 (no ci.yml change):** keep PR→dev light (lint/typecheck/types-drift only).
  Heavy tests still run on PR→main. Cheapest; dev stays fast.
- **B1 (recommended pairing):** make PR→dev run the heavy jobs too, so a merge
  into `dev` is fully gated. Widen the four heavy guards to include `base_ref == 'dev'`:

  ```diff
  -    if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || (github.event_name == 'push' && github.ref == 'refs/heads/main')
  +    if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main' || github.base_ref == 'dev' || (github.event_name == 'push' && github.ref == 'refs/heads/main')
  ```

  Apply at `ci.yml:98` (backend-test), `:250` (frontend-test), `:277`
  (frontend-build), `:369` (all-checks-pass).

**Process implications (the real substance of B):**

- **PRs give audit agents a review anchor.** The factory's review/audit agents
  can post inline comments and a verdict on a PR; a direct push has nowhere to
  anchor that.
- **CI status becomes a merge signal, not a post-mortem.** Green is asserted
  before the shared branch moves.
- **Binding requires branch protection.** A PR *convention* is advisory — anyone
  can still `git push origin <branch>:dev` (the exact fast-forward trick in
  CLAUDE.md rule 21). To make B *binding*, enable a GitHub **branch protection
  rule** on `dev`: "Require a pull request before merging" + "Require status
  checks to pass" (select the `all-checks-pass` / heavy jobs). Without protection,
  B is a norm the fleet can bypass.
- **Cost:** branch protection forbids direct pushes to `dev`, which collides with
  the current local-first fast-forward batching (rule 21). The team must adopt
  "open a PR from the feature branch" as the landing ritual. This is the
  deliberate trade the factory is buying: a little friction for a durable audit
  trail and preventive gating.

**Recommendation: adopt B, paired with B1 + branch protection on `dev`.** It is
the only option that makes "green means green" a *precondition* of landing on the
shared branch and gives the agent fleet a review anchor. If the owner wants a
zero-friction interim step, ship **A** now (it's a 1-line trigger + 4-line guard
widen) and migrate to **B** when branch protection is acceptable. A and B are not
mutually exclusive — A can run under B as the post-merge confirmation on `dev`.

---

## 4. PHP version decision — bump CI to 8.4 (RECOMMENDED)

CI must analyse/test on the **same** major.minor that ships (8.4, per
`Dockerfile:9,35`). Running gates on 8.3 while shipping 8.4 is a silent
correctness hole: an 8.4-only deprecation or type change passes CI and breaks in
production.

**Exact patch to `.github/workflows/ci.yml`:**

```diff
 env:
-  PHP_VERSION: '8.3'
+  PHP_VERSION: '8.4'
   NODE_VERSION: '20'
   PNPM_VERSION: '9'
```

That single env change propagates to all four `setup-php` steps (`ci.yml:39, 72,
146, 325`). No other edit is required. (Sanity check before merging: confirm
`apps/api/composer.json`'s `require.php` constraint admits 8.4 — the Dockerfile
already proves the app builds and runs on 8.4, so this is a formality.)

**Decision-ready summary:** say **"8.4"** → apply the one-line env patch above.

---

## 5. Guard-asset inventory (Task 4) — are the existing guards still wired?

Verified against the **main worktree** (`/Users/houssamr/Projects/syneriva/apps/erp`,
branch `factory/audit-agent-bench` @ `8cb507faa` = real `dev` tip). NOTE: none of
these guard assets exist in *this* isolated fork worktree (`5cf61a6fc`) — another
symptom of the base mismatch in the banner above.

| Guard | Status | Where / notes |
|---|---|---|
| **Deptrac module-boundary ratchet** | ✅ wired | Config `apps/api/deptrac.yaml`; baseline `apps/api/deptrac.baseline.json` (`generated_at: 2026-06-13`, `total: 61` allowed edges); ratchet runner `apps/api/tools/deptrac-ratchet.php`. Baseline is a *ceiling* — regenerate only downward. |
| **TanStack query-key audit** | ✅ wired | `apps/web/tools/audit-tanstack-keys.mjs`; invoked by preflight (`pnpm audit:keys`, `scripts/preflight.sh:82`) and per CLAUDE.md rule 14 also in web lint + CI. Enforces `tenantScopedKey([...])`. |
| **POS local-cache audit** | ✅ present | `apps/web/tools/audit-pos-local-cache.mjs` (sibling of the key audit). Present in the `apps/web/tools/` toolset (alongside `ui-audit-shots.mjs`). |
| **dev-push-guard hook** | ✅ wired | Script `.claude/hooks/git-dev-push-guard.sh` (executable, 4.5 KB); wired as a `PreToolUse`/`Bash` hook in `.claude/settings.json:9`. Blocks force-pushes to `dev` and pushes of a behind/diverged local `dev`. |
| **react-doctor workflow** | ⚠️ UNTRACKED | `.github/workflows/react-doctor.yml` exists in the main worktree but is **git-untracked** (`?? .github/workflows/react-doctor.yml`). It does **NOT** exist in this fork worktree at all. So today it is an **untracked-only** local file — it will not run in CI until it's committed. **Action item:** `git add` it on the branch that lands these gates, or it silently never triggers. |
| **smoke-test workflow** | ✅ tracked, manual | `.github/workflows/smoke-test.yml` — `workflow_dispatch` only (`smoke-test.yml:4`), Playwright against `STAGING_URL` (default `https://erp.otospex.dev`). Never automatic; must be triggered from the Actions tab. |
| **sonarcloud workflow** | ✅ tracked | `.github/workflows/sonarcloud.yml` (present alongside ci.yml / smoke-test.yml). |

### Staging web `autoDeploy=false` — flip it on? (one-paragraph pro/con)

Staging web currently has `autoDeploy=false`, so a merge to `dev` does **not**
redeploy staging automatically; a human must trigger the redeploy (the demo
"STAGING HELD, needs redeploy origin/dev" note in MEMORY is a direct symptom).
Flipping `autoDeploy=true` is a candidate fix. **Pros:** staging always reflects
the latest `dev`, so the manual-Playwright `smoke-test.yml` (and human demo
smoke) test *current* code instead of stale; removes a recurring "forgot to
redeploy" foot-gun; makes staging a live "is dev deployable?" signal for the
fleet. **Cons:** every `dev` push (which today lands with only *local* preflight,
no CI — see Fact (a)) would immediately mutate shared staging, so a bad batch
breaks staging for everyone until reverted; per-tenant `php artisan migrate` and
permission-seeder sync are **not** part of an auto-deploy (MEMORY notes staging
tenants lack `uom.view` → 403 and need the bonus-fields migration), so
auto-deploying the code without those steps can leave staging in a *worse*,
half-migrated state than a deliberate manual deploy. **Recommendation:** flip to
`autoDeploy=true` **only after** Option B + branch protection make `dev` a gated
(green-before-merge) branch, and only once migrate/seed are folded into the
deploy pipeline — otherwise you are auto-publishing ungated code plus a known
migration gap.

---

## Decision checklist (owner)

- [ ] **CI trigger model:** "A" (push→dev, +heavy-guard widen) or **"B"**
      (PR-based; recommended B1 + branch protection).
- [ ] **PHP version:** "8.4" → apply the one-line `PHP_VERSION` patch.
- [ ] **Commit `react-doctor.yml`** (currently untracked) if it should run in CI.
- [ ] **Land deliverables onto real `dev`@8cb507faa**, not this fork (or rebase
      this branch first).
- [ ] (Later) staging `autoDeploy` after `dev` is gated + migrate/seed in pipeline.
