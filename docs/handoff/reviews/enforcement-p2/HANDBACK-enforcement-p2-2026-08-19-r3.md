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
| **final SHA (A″)** | `31dc37f40fa364b3a056216963ec22f2a0bcb0cf` |
| **commits** | 58 |
| **phase series** | `Phase 5.<milestone>.<seq>` — the parent's `commit_series`, used as `Phase 5.0.x` (M0), `5.1.x` (M1), `5.2.x` (M2), `5.3.x` (M3), `5.4.x` (M4) |
| **pushed?** | **Never.** No `git stash` used. Full PHPUnit suite never run. |

Commit list: `git log --oneline c97e0d1ad..HEAD` (58 entries; the milestone-closing ones are
`5.0.1` M0, `5.1.19` M1 ACCEPT, `5.2.19` M2 ACCEPT, `5.3.14` M3 ACCEPT, `5.4.1` M4 evidence, `5.4.2` M4 status: review, `5.4.3` final-gate fix round 1, `5.4.4` M4 bookkeeping, `5.4.5` final-gate fix round 2).

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
authored-provenance scanner reports, at the **pinned seed `cb618c12c`** (re-derived at A″), naming both
quantities because the ambiguity is what let a stale figure survive two gates:

| basis | value | what it counts |
|---|---|---|
| **baseline entries** | **2 638** | what the baseline file holds for those 35 namespaces — 23 `aliased` + 2 610 `missing` + 5 `plural` |
| **key-level gaps** | **4 613** | 2 615 per-key findings + the **1 998** English keys standing behind the 23 whole-namespace `aliased` entries |

> **Supersedes "4 594"** — the key-level figure at the **first seed `96b7fd0e8`**, discarded and
> replaced by the seed revision `cb618c12c` at M1 fix round 1 when the `aliased` finding type changed
> what is counted. It reproduces at no revision in this candidate. Full re-derivation, with the
> first-seed figures retained and labelled superseded, in `DECISION-…-2026-08-19.md` §(2).
>
> *Discrepancy for the parent to adjudicate:* the round-2 register cites **4 608** for the key-level
> figure; I derive **4 613** from the shipped scanner (2 610 + 5 + 1 998). The decomposition is written
> out above so the arithmetic is checkable rather than asserted.

### 3b. Baseline composition — PINNED seed `cb618c12c`, blob `da151bbc5…` (baseline-entry basis)

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
   **Where their authority lives:** in the **parent rulings quoted verbatim beside the M1 and M2 rows**
   in `enforcement-p2.progress.yaml` — *not* in the `max_fix_rounds_override` fields themselves, and
   not in any assertion of mine. A candidate cannot authorise its own override, and those quotations
   were written by me into my own candidate, so they are a **record** of a parent decision, not
   evidence of one; the parent's own copy of each ruling is the authority. Both fields now carry that
   pointer inline. (Raised by the final-gate register as MINOR/awareness-only: R8-C-1 already sources
   the final gate's `max_fix_rounds` from the control manifest, so nothing in this gate depends on
   them.)
8. ⚠️ **`packages/shared/types/generated.d.ts` was changed and then REVERTED at the final gate.**
   Running preflight at M4 executes `php artisan typescript:transform`, which regenerated that file as
   a side effect, and my `git add -A` swept it into commit `48a7101bb` — while the **same commit**
   asserted in three places that P2 had touched neither a PHP enum nor the generated file. The
   round-1 final-gate register confirmed the content was a faithful catch-up regeneration of
   pre-existing base drift (benign), but brief §7 item 4 makes a silent deviation disqualifying
   regardless of benignity, and one of the three places was the announcement bound for ten lanes.

   **The parent ruled REVERT.** At A′ the file is byte-identical to its base blob
   (`0c652f34a3f58f959ff21564643e8b40019a1fd4`, verified by `git hash-object` and an empty
   `git diff base -- <path>`), and `git diff --name-only base..HEAD` no longer contains it (§5e).
   The three untouched-claims are therefore **true as written**, and the `types-drift` red stays
   **genuinely inherited and parent-owned** — the parent's red-gate reconciliation ledger carries it
   as the 3C generated-artifact overlap. `pnpm typecheck` passes against the reverted file.

   **The lesson, recorded plainly:** the scope proof is the control that exists to catch exactly this,
   and mine was a stale paste from two commits earlier — so the one artifact that would have surfaced
   it had been frozen before the change happened. §5e is now re-run at the tip with a HEAD-equality
   assertion inside it, which is the ordering the P1 lane learned at its round 3.

9. **My own recurring failure mode, recorded** — §M3(92)/(96)/(102): repeatedly asserting completeness
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

### 5e. Scope proof — RE-RUN AT A″ (not a paste from an earlier commit)

The round-1 register caught this section pasted from an M3-era commit, which is exactly how it hid the
undeclared path. Re-run at the final tip, with the HEAD-equality assertion **inside** the proof:

```
HEAD-EQUALITY CHECK
  claimed A″         = 31dc37f40fa364b3a056216963ec22f2a0bcb0cf
  git rev-parse HEAD = 31dc37f40fa364b3a056216963ec22f2a0bcb0cf
  MATCH ✓ — the proof is being run at the SHA it claims

$ git diff --stat c97e0d1ad..HEAD | tail -1
 84 files changed, 12085 insertions(+), 44 deletions(-)

$ git diff --name-only c97e0d1ad..HEAD | wc -l
 84

$ git diff --name-only c97e0d1ad..HEAD | grep 'generated.d.ts'
 (no output — ABSENT ✓)
```

**Allowlist applied to all 84 paths — none outside it:**

| n | bucket |
|---|---|
| 1 | `.github/workflows/ci.yml` — the CI wiring deliverable |
| 1 | `apps/api/tools/feature-lane-manifest-check.php` — 2(b) checker |
| 1 | `apps/api/tests/Architecture/…` — 2(b) checker liveness suite |
| 1 | `apps/api/tests/feature-lane-manifest.json` — 2(b) manifest |
| 46 | `apps/web/tools/…` — 2(c)/2(d) audit, fixtures, tests, baseline |
| 3 | `apps/web/eslint-rules/…` — 2(d) RuleTesters |
| 1 | `apps/web/package.json` — `audit:i18n`, `test:tools`, lint chain |
| **1** | **`apps/web/src/features/treasury/statements/api.ts` — DISCLOSED DEVIATION 3** |
| 1 | `scripts/preflight.sh` — local parity |
| 1 | `scripts/i18n-baseline-authority.sh` — local authority wrapper |
| 2 | `docs/conventions/…` — 2(d) convention doc + index |
| 25 | `docs/handoff/…` — decision record, announcement, progress YAML, registers |
| **84** | **total — OUTSIDE THE ALLOWLIST: NONE ✓** |

- **Control-file preflight:** empty — no `scripts/adversarial-review*.sh`, brief, `SELF-REVIEW-HARNESS.md`,
  `enforcement-control-manifest.yaml`, `.claude/agents/*`, or `*control-manifest*` surrogate. ✓
- **Forbidden trees:** no `apps/pos/`, `apps/mobile/`, `apps/erp-ml/`, `apps/platform*`. ✓
- **Production source touched:** exactly one file, `apps/web/src/features/treasury/statements/api.ts`
  (deviation 3). ✓

### 5f. Gates re-verified at A″

```
./vendor/bin/pint --test                                          {"result":"pass"}
php tools/feature-lane-manifest-check.php                         EXIT=0
phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php     OK (46 tests, 121 assertions)
node tools/audit-i18n-completeness.mjs                            EXIT=0  (2917 gaps held)
pnpm typecheck                                                    EXIT=0  ← against the REVERTED generated.d.ts
```

`pnpm typecheck` is called out because the revert restored `generated.d.ts` to base: the web tree
still compiles against it, so the revert introduces no type breakage.

---

## 6. Operational owes — POINTERS ONLY (no independent status here)

- **`docs/handoff/LEDGER.md` §2 row S-14** — the owner-executed PRE-promotion `workflow_dispatch` on
  exactly the accepted SHA. No green run = promotion blocked.
- **`docs/handoff/LEDGER.md` §2 row C-3** — the `CopiesDocumentData.php:309-310` PHPStan pair.
- **⛔ FOUR inherited red CI gates at `base_sha`, PARENT-OWNED** (progress-YAML `blockers`, announcement
  §8.3). P2 changed **zero** PHP files; none of the four jobs has an `if:` guard, so all four run on the
  dispatch: `backend-analyse` (LEDGER C-3) · `backend-architecture` (deptrac 99→174, **unrecorded**) ·
  `frontend-lint`'s `lint:ratchet` step (`@autoerp/pos` 40→84, **unrecorded**) · `types-drift`
  (`MovementGlKind` missing from `generated.d.ts`, found at M4 — **P2 deliberately does NOT regenerate
  it** after the final-gate revert, so this red stays attributable to its true source and sits on the
  parent's reconciliation ledger as the 3C generated-artifact overlap).
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
