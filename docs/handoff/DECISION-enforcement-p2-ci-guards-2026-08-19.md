# Decision record — Enforcement Package 2 (CI runs the guards that already exist)

**Brief:** `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` (r21), §3 Package 2.
**Progress YAML:** `docs/handoff/progress/enforcement-p2.progress.yaml`
**Branch:** `codex/enforcement-p2-ci-guards` · **worktree:** `.worktrees/enforcement-p2`
**base_sha:** `c97e0d1ada0ff73c7beb12dca478fa106df23128`

This file is the durable home for every decision the brief left to the executor. It is written
incrementally (M0 → M3) and is a review target at each milestone's gate.

---

## M0 — Ownership snapshot (brief §3 2(a), gate-r1 H-2 / F-3)

The 2(a) branch decision is derived from THIS snapshot, taken at `base_sha`, and from nothing later.

### (1) UI Wave 0 — `docs/handoff/progress/ui-wave0.progress.yaml`

**Two copies exist and they DIVERGE. The authoritative copy is the one in the live UI Wave 0
worktree** (`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0/`), because that lane's
branch is unmerged; the main-checkout copy is the pre-dispatch state that landed on `dev`.

| Field | Main checkout (`apps/erp/docs/handoff/progress/ui-wave0.progress.yaml`) — STALE | `.worktrees/ui-wave0/docs/handoff/progress/ui-wave0.progress.yaml` — AUTHORITATIVE |
|---|---|---|
| `base_sha` | `d682b38ec9761a917b9716428091a482745795f6` | `d682b38ec9761a917b9716428091a482745795f6` |
| `branch` | `null` | `codex/ui-wave0-2026-08-11` |
| M0 / M0b / M1 / M2 / M3 | `pending` | `passed` |
| **M4 (owns T3(b), the manifest-drift CI job)** | `pending` | **`passed`**, `fix_rounds: 1`, `commit: 6747d9042c252883426f1ebc0f44c2d5c5e54d13`, `verdict: docs/handoff/reviews/ui-wave0/M4-round1.md`, `last_verdict: ACCEPT` |
| M5 | `pending` | `blocked_architecture` |
| M6 / M7 / M8 | `pending` | `pending` |
| wave `status` | `pending` | **`blocked_architecture`** |

**Divergence noted explicitly** (as the parent required): reading the main-checkout copy alone would
have shown M4 `pending` and driven a different (though same-outcome) branch of the decision rule.

### (2) `check-manifest-drift` / route-manifest-drift on `base_sha`

```
$ grep -n 'check-manifest-drift\|manifest-drift\|gen-route-manifest' .github/workflows/ci.yml
(no match — exit 1)
```

**ABSENT at base.** The job exists ONLY on the UI Wave 0 branch (`codex/ui-wave0-2026-08-11`, tip
`acf5dc6bd12d5ceee35596983d9c190253ab1a39`), where it is:

- job id `route-manifest-drift` (`ci.yml:1012`), running `bash scripts/factory/check-manifest-drift.sh` (`:1034`);
- already added to `all-checks-pass` `needs` (`:1130`) with the no-`if:`-never-skipped reasoning comment (`:1128-1129`).

### (3) OpenAPI lane CI-wiring state on `base_sha`

```
$ grep -rni 'openapi' .github/workflows/
(no match — exit 1)
$ ls .github/workflows/
ci.yml  react-doctor.yml  smoke-test.yml  sonarcloud.yml
```

**ZERO OpenAPI CI wiring at base.** That lane (`docs/handoff/HANDOVER-openapi-lane-orchestration-2026-08-07.md:32-36`)
owns CI drift/coverage wiring for its layer and has not landed any. Per the same ownership rule it is
**verify-only for P2**; the M3 merge-announcement checklist carries the explicit OpenAPI
rebase/merge-order line.

### (4) Derived 2(a) disposition — **VERIFY-ONLY + RECORD THE DEPENDENCY. Do NOT author the job.**

> ⚠️ **FLAGGED DECISION — the observed state is off the brief's literal predicate table.** The brief's
> four branches key on `{UI M4 status} × {job present on base}`:
> 1. `M4 passed / wave complete` **AND** job present on base → verify-only (+ fix aggregate membership).
> 2. `M4 pending/in_progress` (wave still owns it) → verify-only + record the dependency; do NOT author.
> 3. task relinquished, **or** wave complete WITHOUT the job on base → implement per T3(b).
> 4. ambiguous → `blocked_owner`.
>
> Observed: **M4 `passed`, wave `blocked_architecture` (NOT complete), job absent from base** (it lives
> on the unmerged UI branch). No branch matches literally: (1) fails on presence, (3) fails because the
> wave is neither complete nor relinquished.
>
> **Ruling taken: branch (2)'s outcome.** The rule's operative principle is stated in the brief and in
> F-3 as *ownership, not presence* — "Never both lanes authoring the same CI job". Ownership here is
> **not ambiguous in the slightest**: UI Wave 0 owns T3(b), has already authored it, and has passed an
> adversarial gate on it (M4 ACCEPT at `6747d9042`). The only thing that is off-table is the *status
> label* — `passed-on-an-unmerged-branch` rather than `pending`/`in_progress` — which does not change
> who owns the artifact. `blocked_owner` (branch 4) is reserved for **ownership** ambiguity; invoking it
> for a label mismatch whose substantive answer is fully determined would stall the package on a
> non-question. Authoring the job here would produce exactly the duplicate F-3 forbids, and would
> conflict textually with `ci.yml:1012-1034` + `:1130` on the UI branch.
>
> **Recorded dependency + obligation (goes into the M3 checklist):** P2 and UI Wave 0 BOTH edit
> `.github/workflows/ci.yml`, and both touch the `all-checks-pass` `needs` list. Whichever lands
> second must rebase and re-verify aggregate `needs` reconciliation — the landed `route-manifest-drift`
> entry must survive P2's edits, and P2's new/edited jobs must survive UI Wave 0's. Same rule as the
> OpenAPI line.

**Nothing verifiable-at-base for the drift job** (it is absent), so the "no `if:` + aggregate `needs`
membership" verification is recorded against the UI branch's own evidence above rather than re-run
here; it becomes P2's obligation only if the UI wave relinquishes the task.

### (5) `all-checks-pass` membership of `frontend-lint` at base (brief 2(c) del. 3, H-9)

```
ci.yml:1108  needs: [backend-lint, backend-analyse, backend-architecture, backend-test,
                     backend-test-pgsql, treasury-spine-pgsql, frontend-lint, frontend-typecheck,
                     frontend-test, pos-test, frontend-build, types-drift]
```

`frontend-lint` **is present** → no wiring work needed for it (the brief's conditional "if absent,
wiring it in is in scope" does not fire).

**Citation drift re-derived at `base_sha`** (the brief warned its line numbers would move):

| Brief's citation | At `c97e0d1ad` |
|---|---|
| `frontend-lint` job at `ci.yml:853` | **`:857`** |
| `run: pnpm audit:keys` `:876` | **`:880`** |
| `run: pnpm audit:design-system` `:879` | **`:883`** |
| `run: pnpm audit:quantity` `:882` | **`:886`** |
| `run: pnpm lint:ratchet` `:890` | **`:894`** |
| `all-checks-pass` `needs` `:1104` | **`:1108`** (job header `:1094`) |

### (6) True event graph re-verified at base (gate-r2 R2-C-2)

```
ci.yml:2-8
on:
  push:
    branches: [main]              # post-merge confirmation only
  pull_request:
    branches: [main, dev]         # cheap checks on PR→dev; full pipeline on PR→main
  workflow_dispatch:              # manual full run from the Actions tab
```

A direct push to `dev` never starts the workflow. Every "runs on every lane" claim below means "on
every event that starts the workflow".

### (7) Other M0 preconditions

| # | Precondition | State at base | Verdict |
|---|---|---|---|
| 1 | `quiet_window_ack` non-null (preliminary scope notice only) | filled by the parent, 2026-08-19 | PASS |
| 2 | `commit_series` non-null | `Phase 5.<milestone>.<seq>` | PASS |
| 3 | ownership snapshot recorded | this section | PASS |
| 4 | fresh worktree/branch off `base_sha` | `.worktrees/enforcement-p2`, `codex/enforcement-p2-ci-guards`, `git log -1` = `c97e0d1ad` | PASS |
| 5 | `ratchet_trust_model_ack` non-null | filled by the parent (4 write principals enumerated + non-authorship assertion) | PASS |
| 6 | `control_manifest` non-null AND manifest present at base with matching `sha256` | `shasum -a 256 docs/handoff/enforcement-control-manifest.yaml` → `709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a`, equal to the pin | PASS |

**M0 = PASS.** No `blocked_precondition`.

### (8) Executor substitution, recorded

The brief names a Codex desktop session as executor. Codex quota is exhausted; the parent dispatched
this package to a Claude implementation agent under the **same contract** (no push, no stash, no full
PHPUnit suite, harness-gated milestones, parent-invoked final gate). Recorded here as a deviation from
the brief's `Executor:` line — it changes who types, not any rule.

### (9) Local tool availability (brief §5 item 4 layer 1)

| Tool | State |
|---|---|
| `claude` CLI (bridge) | present, `2.1.235` |
| PyYAML | present |
| **`actionlint`** | **ABSENT on this machine** — recorded explicitly as the brief requires. Workflow YAML validity is proven by a Python `yaml.safe_load` parse instead; this layer is INSUFFICIENT for the Actions job/event graph either way. |
