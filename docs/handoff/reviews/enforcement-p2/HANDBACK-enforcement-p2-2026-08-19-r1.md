# HANDBACK — enforcement Package 2 (CI runs the guards that already exist)

**Status: M4 `status: review`, handed over for the PARENT-invoked final gate.**
Self-certification is not accepted; this document is evidence, not a verdict.

---

## 1. Header

```
$ git log -1 c97e0d1ada0ff73c7beb12dca478fa106df23128
commit c97e0d1ada0ff73c7beb12dca478fa106df23128
    enforcement-p2: parent pre-dispatch pins (quiet_window_ack, commit_series Phase 5.m.s,
    ratchet_trust_model_ack, control_manifest) — receipt at
    memory/dispatch-receipts/enforcement-p2.receipt.yaml
```

| | |
|---|---|
| **base SHA** | `c97e0d1ada0ff73c7beb12dca478fa106df23128` |
| **branch** | `codex/enforcement-p2-ci-guards` |
| **worktree** | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/enforcement-p2` |
| **final SHA (A)** | `f91bba9cb56d92fffa8c64fc39445bab416f4655` |
| **commits** | 55 |
| **phase series** | `Phase 5.<milestone>.<seq>` — the parent's `commit_series`, used as `Phase 5.0.x` (M0), `5.1.x` (M1), `5.2.x` (M2), `5.3.x` (M3), `5.4.x` (M4) |
| **pushed?** | **Never.** No `git stash` used. Full PHPUnit suite never run. |

Commit list: `git log --oneline c97e0d1ad..HEAD` (55 entries; the milestone-closing ones are
`5.0.1` M0, `5.1.19` M1 ACCEPT, `5.2.19` M2 ACCEPT, `5.3.14` M3 ACCEPT, `5.4.1` M4 evidence, `5.4.2` M4 status: review).

---

## 2. Per-milestone

### M0 — **DONE** (setup-only, no register)

Six preconditions PASS; ownership snapshot in `DECISION-…-2026-08-19.md` §M0, including the
**ui-wave0 YAML divergence** (main checkout stale vs the authoritative worktree copy) the parent asked
to be recorded both ways. `control_manifest` sha256 verified
`709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a`.

**2(a) disposition: VERIFY-ONLY + record the dependency.** Flagged prominently as *off the brief's
literal predicate table* (UI M4 `passed` on an unmerged branch matches none of the four bullets); ruled
on the rule's own ownership-not-presence principle and F-3.

### M1 — **DONE**, 7 rounds (`M1-round1..7`), ACCEPT at round 7

2(c) i18n completeness + 2(d) guard liveness. Round 3 attempt 1 was a tool error (`no stdin data`) —
stub preserved, re-invoked with `< /dev/null`. Rounds 1–6 found real defects in my own guard, each
closed; round 6 hit STOP condition A and the **parent ruled an authorized continuation** (recorded
verbatim in the YAML) for a 5-line fixture deletion. Round 7 ACCEPT, verified by the reviewer's own
mutation harness.

### M2 — **DONE**, 8 rounds (`M2-round1..8`), ACCEPT at round 8

2(b) Feature-lane manifest. Two parent rulings: the **F-2 interim ruling** (B-as-shipped adopted;
Security→PR→dev ruled YES and implemented with its own liveness proof; execution scope escalated) and
a **final authorized continuation** with a binding terminal condition after round 6's STOP-A. Round 8
ACCEPT under that condition, with residuals registered.

### M3 — **DONE**, 6 rounds (`M3-round1..6`), ACCEPT at round 6

2(a) verify-only record, the `all-checks-pass` PR→dev **proposal** (never a change), and the
merge-announcement checklist. One **close-before-merge obligation** on the parent (§4 below).

### M4 — **`status: review`**, handed over. Evidence in §5.

---

## 3. Census tables (review targets, not appendices)

### 3a. i18n provenance — why the merged `resources` object cannot be trusted

| locale | `own` | `en-aliased` | `english-spread` |
|---|---|---|---|
| en | 56 | 0 | 0 |
| fr | 56 | 0 | 0 |
| **ar** | **21** | **23** | **12** |

A scanner reading `resources` reports **zero** Arabic gaps across those 35 namespaces. The
authored-provenance scanner reports **4 594**.

### 3b. Baseline composition (seed `cb618c12c`, blob `da151bbc5…`)

| metric | value |
|---|---|
| namespaces | 56 |
| authored leaf keys | en 9 242 · fr 9 258 · ar 4 702 (**1 998 behind aliases**) |
| locale files | en 57 · fr 57 · **ar 34** |
| **baseline entries** | **2 917** — ar `aliased` 23, ar `missing` 2 848, ar `plural` 9, fr `plural` 29, en `plural` 8 |
| structural failures | 0 |

### 3c. Feature-lane census

| measure | files | distinct |
|---|---|---|
| all of `tests/Feature` (74 groups) | 1 329 | 1 316 |
| in groups no lane runs as a whole (**`debt_ceiling`**) | **1 114** | 1 102 |
| minus the 111 allowlisted names inside them | 1 003 | **991** ≈ the `ci.yml` census's 990 |

Allowlists: 124 distinct names (112 + 16 entries). Substring shadowing proven and killed
(`AnalyticsTest` also selected `ExpenseAnalyticsTest`); anchoring is **coverage-neutral** — 112→112 and
16→16, 0 dropped / 0 added, verified by `--list-tests` and under `php artisan test`.

### 3d. F-2 measurement (the owner's number)

6.24 s/class mean of two independent samples (40-class random 6.18, `Accounting` 6.29) on **SQLite**,
the fast path. Whole suite ≈ **138 min**; the 71 laneless groups ≈ **116 min**. Option A means
PostgreSQL and is slower.

---

## 4. Deviations and decisions the brief did not specify — all flagged

1. **Executor substitution** — Codex quota exhausted; run by a Claude agent under the same contract.
2. **`actionlint` ABSENT** on this machine — workflow validity proven by Python `yaml.safe_load`;
   recorded as explicitly INSUFFICIENT for the Actions job/event graph, per §5 layer 1.
3. **One production type change** (`statements/api.ts`) — required to make `pnpm test:tools` wireable
   green; shipped as `Pick<OffsetPaginationMeta, …>` after round 1 showed the bare type declared
   `from`/`to` the endpoint never emits.
4. **2(a) off-table predicate** → branch-2 outcome (verify-only), §M0.
5. **A third manifest disposition, `deferred`**, invented rather than labelling 990 runnable classes
   "genuinely cannot run" — §M2(44).
6. **i18n `aliased` granularity** (one entry per aliased namespace, not per key) departs from the
   reviewer's literal fix shape to resolve the full-parity-on-every-new-key problem — §M1(10).
7. **`max_fix_rounds` overrides recorded per-milestone, not top-level** (M1 → 6, M2 → 7), because the
   control manifest and dispatch receipt both project 5 and `adversarial-review-final.sh` field-checks
   top-level == manifest at M4.
8. **My own recurring failure mode, recorded** — §M3(92)/(96)/(102): repeatedly asserting completeness
   from a convenient subset (worktrees, `codex/*`, aggregate members) and letting summary prose drift
   from re-measured tables. Every instance was caught by the reviewer, not by me. The countermeasure in
   the artifacts is a **stated inclusion rule beside every enumeration**.

---

## 5. Verification

### 5a. Local acceptance (brief §3 block) — all re-run at A

```
LOCAL AUTHORITY SETUP
  seed_blob = da151bbc51edcd066d2153b51a1df8ae1ab5bd32   (== YAML mirror ✓)

node tools/audit-i18n-completeness.mjs                    EXIT=0
  56 namespaces, authored keys: en=9242, fr=9258, ar=4702 authored (1998 behind aliases);
  2917 known gap(s) held at the baseline
  English-aliased namespaces — ar: 23 ns / 1998 keys served in English
(env -u I18N_BASELINE_PROTECTED_BLOB …)                   EXIT=1   ← fail-closed, isolated subshell
pnpm test:tools            7 files / 145 tests            EXIT=0   ← exact CI command string
pnpm test:eslint-rules     6 suites                       EXIT=0
pnpm lint (env var UNSET)                                 EXIT=0   ← via the authority wrapper
./vendor/bin/pint --test                                  {"result":"pass"}
php tools/feature-lane-manifest-check.php                 EXIT=0
./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php   OK (46 tests)
./vendor/bin/phpunit tests/Feature/Security               OK (93 tests, 305 assertions)
phpstan --level=8 (both new PHP files)                    [OK] No errors
```

### 5b. Preflight — PHPUnit reported SKIPPED, never green-by-omission

`PREFLIGHT_SCOPE=paths` (default, laptop-safe) with `PREFLIGHT_TEST_PATHS`,
`PREFLIGHT_PHPSTAN_PATHS` and `PREFLIGHT_VITEST_PATHS` scoped to the touched paths. **Pint ✓,
PHPStan ✓, the scoped PHPUnit paths ✓, the scoped Vitest paths ✓.** The **full** PHPUnit suite was
NOT run (house rule) and the full web Vitest run OOM-killed this machine once, so both are reported
**SKIPPED**, not green. Preflight's final `types-drift` stage **fails on an inherited defect** — §6.

### 5c. Workflow-change evidence (§5, local only — the executor never pushes)

1. **YAML validity** — `yaml.safe_load` parses `ci.yml`, 16 jobs. `actionlint` unavailable; this layer
   is explicitly insufficient for the job/event graph.
2. **Command execution** — every new step's command run locally, above.
3. **Job-graph reasoning** — `frontend-lint`, `backend-architecture` and `security-regression` carry
   **no `if:`**, so they run on every event that starts the workflow (PR→`main`, PR→`dev`, push→`main`,
   `workflow_dispatch`); a direct push to `dev` starts nothing. `security-regression` added to
   `all-checks-pass` `needs` (13 members).

### 5d. Event-graph acceptance — what the S-14 dispatch must show executed

**`frontend-lint`** — *Fetch the pinned i18n baseline revision* · *Run i18n completeness gate
(tools/audit-i18n-completeness.mjs)* · *Run detector liveness suites (ESLint RuleTester + tools tamper
tests)*.
**`backend-architecture`** — *Check tests/Feature CI-lane manifest* · *Feature-lane checker liveness
test*.
**The `security-regression` JOB** — called out specifically: its environment is a strict **subset** of
`backend-test`'s (no postgres/redis services, `pdo_sqlite` only). Green locally, but a latent service
dependency would not surface on a box with a running stack, and this dispatch is its first real
execution.

### 5e. Scope proof

```
$ git diff --stat c97e0d1ad..HEAD | tail -1
 84 files changed, 12022 insertions(+), 42 deletions(-)
```

Control-file preflight: **empty** — no change to `scripts/adversarial-review*.sh`, the brief,
`SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, or `.claude/agents/*`. No `apps/pos`,
mobile or ML path. Only production source touched: `apps/web/src/features/treasury/statements/api.ts`
(disclosed deviation 3).

---

## 6. Operational owes — POINTERS ONLY (no independent status here)

- **`docs/handoff/LEDGER.md` §2 row S-14** — the owner-executed PRE-promotion `workflow_dispatch` on
  exactly the accepted SHA. No green run = promotion blocked.
- **`docs/handoff/LEDGER.md` §2 row C-3** — the `CopiesDocumentData.php:309-310` PHPStan pair.
- **⛔ FOUR inherited red CI gates at `base_sha`, PARENT-OWNED** (progress-YAML `blockers`, announcement
  §8.3). P2 changed **zero** PHP files; none of the four jobs has an `if:` guard, so all four run on the
  dispatch: `backend-analyse` (LEDGER C-3) · `backend-architecture` (deptrac 99→174, **unrecorded**) ·
  `frontend-lint`'s `lint:ratchet` step (`@autoerp/pos` 40→84, **unrecorded**) · `types-drift`
  (`MovementGlKind` missing from `generated.d.ts`, **unrecorded**, found at M4).
- **Close-before-merge (M3 round 6):** the ceilings must be **regenerated against the merged tree**,
  not copied — `dev` moved three times during review. Announcement §8 item 0 is regenerate-first.
- **Owner prerequisites before the merge lands:** repository variable
  `I18N_BASELINE_PROTECTED_BLOB` = `da151bbc51edcd066d2153b51a1df8ae1ab5bd32`, and the annotated tag
  `ci-pin/enforcement-p2-r1` at exactly A.
- **F-2 execution scope** — `owner_gates` status `escalated_to_owner`, owed at promotion.
- **Named residuals** — DECISION §(73): N-4, N-5, R8-1, R8-2, R8-3, each with the standing S-14
  mitigation. **N-4 is the one P3-M2 must read.**

---

## 7. Completion language

M0–M3 have ACCEPT registers; **M4 is `status: review` and is not self-certified**. The package is not
complete until the parent's final gate ACCEPTs, and the **program** is not complete — P1 and P3
dispatch independently and the parent tracks the whole.
