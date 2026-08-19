# M4 Adversarial Review — Round 1
**Scope:** `M4 = T3(a) + T3(b)` per `docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:226-249`
**Range reviewed:** `d682b38ec..HEAD`; M4's own commits are `00366d151` (T3a), `6747d9042` (T3b), `3fc0c52bc` (evidence)
**Lenses:** frontend-conventions, general

---

## Acceptance criteria — independently verified

| Criterion (brief `:226-249`) | Result |
|---|---|
| T3(a) commits manifests **and nothing else** | ✅ `00366d151` touches only `scripts/factory/manifests/routes-web.yaml`; `routes-pos.yaml` byte-unchanged across the whole range |
| **Only** manifest commit on the branch | ✅ `git log d682b38ec..HEAD -- scripts/factory/manifests/` returns exactly one commit |
| Ran after T2 **and** all four deletions | ✅ `00366d151` sits after `198f198e7` (M3 accept); the four owner-ruled routes (`/finance`, `/marketing`, `/pos/shifts`, `/settings/chart-of-accounts`) are the only removals in the diff |
| `check-manifest-drift.sh` exits 0 | ✅ **I ran it: `EXIT=0`** |
| `/sales/invoices/:id → InvoiceDetailPage` (proof T2 landed first) | ✅ `scripts/factory/manifests/routes-web.yaml:762-765` |
| Regen is faithful, not hand-edited | ✅ drift 0 is itself the proof (script regenerates + `diff -ru`) |
| New rows match source | ✅ spot-checked `/admin/support-access` (`routes/index.tsx:428-434`), `/inventory/stock-adjustments{,/new,/:id}` (`:1371-1400`), `/finance/lane-separation` (`:1989-1998`) — permissions and components match |
| T3(b) job, **no `if:`** | ✅ `python3 yaml.safe_load` → job keys are exactly `['name','runs-on','steps']` |
| Lean, no DB/Redis/`.env` | ✅ `ci.yml:1012-1032`: checkout → pnpm → node → install → drift check (5 steps) |
| Deps resolvable | ✅ `js-yaml` is a root devDependency (`package.json:24`); `typescript ~5.9.3` in `apps/web/package.json:41`, reached by the generator's `createRequire` (`gen-route-manifest.mjs:25`) |
| Added to `all-checks-pass.needs` + reasoning comment | ✅ `ci.yml:1128-1130`; comment states the never-skipped argument, matching existing style |
| Generator tests still green | ✅ `node --test scripts/factory/gen-route-manifest.test.mjs` → 10/10 |

**Standing checks:** Rule 19 (money/quantity) — **not applicable**, M4 touches only a generated YAML manifest, a CI job, and docs; no runtime code. Tenant scoping / constructor injection / migrations / Horizon queues — **not applicable**. i18n en+fr — **not applicable**, no user-facing strings. Red-first evidence — the brief itself states CI jobs cannot be unit-tested (`:246`) and substitutes exit-code evidence; that substitution is honoured.

---

## Findings register

**1. P2 — CONFIRMED — `docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:238` vs `:288-289` — M4's "only manifest commit" rule is unsatisfiable once M5 lands; the M8 merge gate will fail with no authorised remedy.**
T3(a) is declared *"the **only** manifest commit on the branch"*, while §4 item 2 binds the **final** branch state to `check-manifest-drift.sh` exit 0. T4 (M5) is specified to replace `moduleKey="partners"` with `permission="partners.view"` at `apps/web/src/routes/index.tsx:2747`. `<Route path="crm">` (`:2742`) carries no guard, so the generator will re-derive that row as `module_gate: null / permission: partners.view`, against the committed `routes-web.yaml:130-133` (`module_gate: partners / permission: null`). That is a guaranteed two-field drift.
*Failure scenario:* M5 lands → `check-manifest-drift.sh` exits 1 → M8's §4 item 2 gate fails → the only fix is a second manifest commit, which the brief forbids.
*Not an M4 defect* — M4's own criteria are all met, and the executor correctly regenerated at exactly the point §2 specifies. This needs a **parent/owner ruling before M5 closes** (brief STOP condition C: architecture contradiction). Note the two `parts_catalog` edits (`routes/index.tsx:1428`, `:1440`) are drift-**safe**: `<ModuleGuard module="PlatformIntegration">` wins under rule A4, so `routes-web.yaml:486-493` is unaffected. T15 (`Sidebar.tsx:280`) is also drift-safe. `/crm/companies` is the single at-risk row.

**2. P3 — CONFIRMED — `.github/workflows/ci.yml:3-8` — the drift guard never runs on the actual `dev` promotion lane.**
The job-level requirement ("no `if:`") is met, but the workflow's `on:` is `push: [main]` / `pull_request: [main, dev]` / `workflow_dispatch`. Per CLAUDE.md rule 21 this repo promotes to `origin/dev` by direct fast-forward **push**, not by PR — and no other workflow covers push-to-dev (`sonarcloud.yml` watches `main, develop`; `react-doctor.yml` is PR-only; `smoke-test.yml` is dispatch-only).
*Failure scenario:* a session merges a route change into local `dev` and fast-forwards `origin/dev` without a PR; the manifest drifts and no CI run exists to catch it until the next PR→main.
Outside T3(b)'s literal acceptance text (which bound the *job-level* `if:`), so recorded as a note, not a blocker.

**3. P3 — PLAUSIBLE (deps CONFIRMED, timing not measured) — `.github/workflows/ci.yml:1027-1029` — the install step is broader than the generator needs, against the brief's "seconds-scale / don't over-provision" target (`:245`).**
`pnpm install --frozen-lockfile` at the workspace root installs every package including `apps/pos`, and `pnpm-workspace.yaml:4-6` sets `allowBuilds: better-sqlite3, esbuild` — native compiles the drift check never touches. The generator needs only root `js-yaml` plus `apps/web`'s `typescript`. `--filter @autoerp/web... --ignore-scripts` would cut the cold-cache cost. Warm `setup-node cache: 'pnpm'` mitigates this, so impact is real but bounded.

**4. P3 — CONFIRMED — `docs/handoff/progress/ui-wave0.progress.yaml:80` — the two-commit milestone records only one SHA.**
`commit: 6747d9042c…` (T3b); T3(a) `00366d151` appears only in handback prose. The brief authorises M4 as two commits (`:143`); a resume from the YAML alone loses the T3(a) anchor. Cosmetic/traceability.

---

## Bypasses attempted that FAILED

- **Independent re-execution of the required negative drift check** (brief `:247`): blocked by the read-only mandate — it requires temporarily editing `apps/web/src/routes/index.tsx`. `git stash` / `git worktree add` were also rejected as repo-mutating (and stash is repo-global per house rule). **Substituted a non-mutating equivalent:** regenerated into a temp dir, appended a synthetic `/__probe` row to the temp copy, and confirmed `diff -ru scripts/factory/manifests $TMP` → **exit 1**. Combined with `check-manifest-drift.sh:18-22` (`if ! diff -ru …; then … exit 1; fi` under `set -euo pipefail`, where the `if` condition is exempt from `set -e`), the failure path and its printed fix line are confirmed by construction. The handback's pasted exit-1 output is consistent with this.
- **`actionlint`** on the new job: not installed locally. Substituted `python3 yaml.safe_load`, which confirmed the job parses, has no `if:` key, has 5 steps, and is present in `all-checks-pass.needs`.

---

M4's own scope — the single faithful manifest regeneration at the correct point in the dependency chain, and an always-run lean CI drift guard wired into the aggregate gate — is delivered and independently verified. Finding 1 is a forward contradiction in the brief that must be ruled on before M5 closes; it does not impeach M4's deliverables.

VERDICT: ACCEPT
