# HANDBACK — enforcement Package 2 (CI runs the guards that already exist)

**Status: M4 `status: review`, handed over for the PARENT-invoked final gate.**
Self-certification is not accepted; this document is evidence, not a verdict.

---

## 1. Header

```
$ git log -1 a4a8c2293dcf7556045f44d35cfa5f734be8022b
commit a4a8c2293dcf7556045f44d35cfa5f734be8022b
    web: merged-tree Vitest red burn-down — DN billing-status nullish tolerance,
    tenant-scoped modal invalidations, offset-pagination meta consolidation
    (dev tip at the 2026-08-21 stale-A re-base; the ORIGINAL dispatch base was
    c97e0d1ad — its pre-dispatch pin commit message is preserved in that commit)
```

| | |
|---|---|
| **base SHA** | `a4a8c2293dcf7556045f44d35cfa5f734be8022b` (stale-A re-base 2026-08-21; original c97e0d1ad) |
| **branch** | `codex/enforcement-p2-ci-guards` |
| **worktree** | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/enforcement-p2` |
| **final SHA (A″)** | `39e2d9488e0547e4128921bec9ccbdcbcd5821e6` (post-rebase + final-gate fix round 3; prior accepted 31dc37f40 superseded by the stale-A path) |
| **commits** | 65 (58 rebased + 7 reconciliation/fix: Phase 5.5.1–5.5.4, 5.6.1–5.6.3) |
| **phase series** | `Phase 5.<milestone>.<seq>` — the parent's `commit_series`, used as `Phase 5.0.x` (M0), `5.1.x` (M1), `5.2.x` (M2), `5.3.x` (M3), `5.4.x` (M4) |
| **pushed?** | **Never.** No `git stash` used. Full PHPUnit suite never run. |

Commit list: `git log --oneline a4a8c2293..HEAD` (65 entries; the milestone-closing ones are
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
| en | 55 | 0 | 0 |
| fr | 55 | 0 | 0 |
| **ar** | **21** | **22** | **12** |

*(55-namespace `ns` array at A — ui-wave0's owner-ruled `marketing` deletion removed one aliased
namespace at the 2026-08-21 stale-A rebase.)*

A scanner reading `resources` reports **zero** Arabic gaps across those 34 namespaces. The
authored-provenance scanner reports, at the **pinned seed `6a0c1cd72`** (the merged-tree seed
revision; re-derived at A″), naming both quantities because the ambiguity is what let a stale figure
survive two gates:

| basis | value | what it counts |
|---|---|---|
| **baseline entries** | **2 640** | what the baseline file holds for those 34 namespaces — 22 `aliased` + 2 613 `missing` + 5 `plural` |
| **key-level gaps** | **4 604** | 2 618 per-key findings + the **1 986** English keys standing behind the 22 whole-namespace `aliased` entries |

> **Supersedes "4 594"** — the key-level figure at the **first seed `96b7fd0e8`**, discarded and
> replaced by the seed revision `cb618c12c` at M1 fix round 1 when the `aliased` finding type changed
> what is counted. It reproduces at no revision in this candidate. Full re-derivation, with the
> first-seed figures retained and labelled superseded, in `DECISION-…-2026-08-19.md` §(2).

> *(A round-2-era "discrepancy for the parent to adjudicate" between 4 608 and 4 613 was retired at
> the 2026-08-21 seed revision: both were figures at the discarded seed `cb618c12c`; the shipped
> scanner at A yields exactly the 4 604 = 2 613 + 5 + 1 986 stated in the table above, and neither
> old figure reproduces at any revision in this candidate.)*

### 3b. Baseline composition — PINNED seed `6a0c1cd72`, blob `26a9ae168…` (baseline-entry basis; the pre-rebase seed `cb618c12c`/`da151bbc5…` measured 2 917 and is superseded)

| metric | value |
|---|---|
| namespaces | 55 |
| authored leaf keys | en 9 228 · fr 9 244 · ar 4 697 (**1 986 behind aliases**) |
| locale files | en 56 · fr 56 · **ar 34** |
| **baseline entries** | **2 924** — ar `aliased` 22, ar `missing` 2 851, ar `plural` 9, fr `plural` 34, en `plural` 8 |
| structural failures | 0 |

### 3c. Feature-lane census

| measure | files | distinct |
|---|---|---|
| all of `tests/Feature` (74 groups) | 1 348 | 1 335 |
| in groups no lane runs as a whole (**`debt_ceiling`**) | **1 131** | 1 119 |
| minus the 111 allowlisted names inside them | 1 020 | **1 008** (the `ci.yml`/checker-header census stamp of 990 is a base-time figure) |

Allowlists: 124 distinct names (112 + 16 entries). Substring shadowing proven and killed
(`AnalyticsTest` also selected `ExpenseAnalyticsTest`); anchoring is **coverage-neutral** — 112→112 and
16→16, 0 dropped / 0 added, verified by `--list-tests` and under `php artisan test`.

### 3d. F-2 measurement (the owner's number)

6.24 s/class mean of two independent samples (40-class random 6.18, `Accounting` 6.29) on **SQLite**,
the fast path. Whole suite ≈ **140 min** (1 348 files); the 71 laneless groups ≈ **118 min** (1 131). Option A means
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
  seed_blob = 26a9ae1688d80e0f450215326b19ccd1701c9a8f   (== YAML mirror ✓ — merged-tree seed 6a0c1cd72)

node tools/audit-i18n-completeness.mjs                    EXIT=0
  55 namespaces, authored keys: en=9228, fr=9244, ar=4697 authored (1986 behind aliases);
  2924 known gap(s) held at the baseline
  English-aliased namespaces — ar: 22 ns / 1986 keys served in English
(env -u I18N_BASELINE_PROTECTED_BLOB …)                   EXIT=1   ← fail-closed, isolated subshell
pnpm test:tools            8 files / 160 tests            EXIT=0   ← exact CI command string
pnpm test:eslint-rules     6 suites                       EXIT=0
pnpm lint (env var UNSET)                                 EXIT=0   ← via the authority wrapper
./vendor/bin/pint --test                                  {"result":"pass"}
php tools/feature-lane-manifest-check.php                 EXIT=0
./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php   OK (46 tests, 123 assertions)
./vendor/bin/phpunit tests/Feature/Security               OK — Tests: 93, Assertions: 305 (14 PHPUnit deprecations)
phpstan --level=8 (checker + liveness test, the two PHP files this package edits)   [OK] No errors
```

### 5b. Preflight — PHPUnit reported SKIPPED, never green-by-omission

`PREFLIGHT_SCOPE=paths` (default, laptop-safe) with `PREFLIGHT_TEST_PATHS`,
`PREFLIGHT_PHPSTAN_PATHS` and `PREFLIGHT_VITEST_PATHS` scoped to the touched paths. **Pint ✓,
PHPStan ✓, the scoped PHPUnit paths ✓, the scoped Vitest paths ✓.** The **full** PHPUnit suite was
NOT run (house rule) and the full web Vitest run OOM-killed this machine once, so both are reported
**SKIPPED**, not green. Preflight's final `types-drift` stage **fails on an inherited defect** — §6.

### 5c. Workflow-change evidence (§5, local only — the executor never pushes)

1. **YAML validity** — `yaml.safe_load` parses `ci.yml`, 18 jobs (17 at the merged base — P1's `backend-dpa-guard` included — plus this package's `security-regression`). `actionlint` unavailable; this layer
   is explicitly insufficient for the job/event graph.
2. **Command execution** — every new step's command run locally, above.
3. **Job-graph reasoning** — `frontend-lint`, `backend-architecture` and `security-regression` carry
   **no `if:`**, so they run on every event that starts the workflow (PR→`main`, PR→`dev`, push→`main`,
   `workflow_dispatch`); a direct push to `dev` starts nothing. `security-regression` added to
   `all-checks-pass` `needs` (15 members at A: the merged list also carries `backend-dpa-guard` and `route-manifest-drift`, landed on dev by P1 and ui-wave0).

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
  claimed A″         = 39e2d9488e0547e4128921bec9ccbdcbcd5821e6
  git rev-parse HEAD = 39e2d9488e0547e4128921bec9ccbdcbcd5821e6
  MATCH ✓ — the proof is being run at the SHA it claims

$ git diff --stat a4a8c2293..HEAD | tail -1
 84 files changed, 12154 insertions(+), 50 deletions(-)

$ git diff --name-only a4a8c2293..HEAD | wc -l
 84

$ git diff --name-only a4a8c2293..HEAD | grep 'generated.d.ts'
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
phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php     OK (46 tests, 123 assertions)
node tools/audit-i18n-completeness.mjs                            EXIT=0  (2924 gaps held)
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
- **Close-before-merge (M3 round 6):** DISCHARGED IN-CANDIDATE at the 2026-08-21 stale-A rebase —
  the ceilings were regenerated against the merged tree (Phase 5.5.1; checker exit 0 at A). If `dev`
  moves again before the merge, regenerate again — never copy.
- **Owner prerequisites before the merge lands:** repository variable
  `I18N_BASELINE_PROTECTED_BLOB` = `26a9ae1688d80e0f450215326b19ccd1701c9a8f` (the merged-tree seed
  `6a0c1cd72`; the pre-rebase `da151bbc5…` is superseded — setting it fail-closes the dispatch as
  mirror drift), and the annotated tag `ci-pin/enforcement-p2-r1` at exactly A.
- **F-2 execution scope** — `owner_gates` status `escalated_to_owner`, owed at promotion.
- **Named residuals** — DECISION §(73): N-4, N-5, R8-1, R8-2, R8-3, each with the standing S-14
  mitigation. **N-4 is the one P3-M2 must read.**

---

## 7. Completion language

M0–M3 have ACCEPT registers; **M4 is `status: review` and is not self-certified**. The package is not
complete until the parent's final gate ACCEPTs, and the **program** is not complete — P1 and P3
dispatch independently and the parent tracks the whole.


---

## STALE-A RE-BASE ADDENDUM (2026-08-21, parent-as-executor under the owner's recorded delegation)

The accepted A″ 31dc37f40 went stale when P1 + the merged-lane reconciliation landed on dev
(new base a4a8c2293). Per the S-14 re-gate protocol the series was rebased and seven
reconciliation/fix commits added (5.5.1–5.5.4 below + final-gate fixes 5.6.1 doc
reconciliation, 5.6.2 level-8 guard, 5.6.3 round-4 doc replacement) — NO new guard scope:

- **Phase 5.5.1** — merged-tree Feature-lane ceilings (CountryDefaults 28 / Document 74 /
  Fiscal 79 / Inventory 106, debt 1131 — regenerated from the checker's own census, not copied);
  checker drops FULL-LINE comments before the --filter scan (the dn-consolidation lane's doc
  comment "Paths, not --filter, …" tripped UNPARSEABLE fail-closed); the relabel liveness test
  now derives the debt count from a pre-run instead of the pasted 1114; the ticketed es-A0 F-1
  fold-in: the two event ratchets now run as a backend-architecture step (no `if:`), gating
  PR→dev.
- **Phase 5.5.2/5.5.3** — i18n baseline SEED REVISION on the merged tree (ui-wave0's owner-ruled
  marketing deletion shrank the pinned surface → gate failed closed by design; regenerated,
  2924 entries) + mirror re-pin (seed 6a0c1cd72, blob 26a9ae168) + base_sha re-pin.
- **Phase 5.5.4** — control-manifest sha256 re-pin (manifest moved by owner-ruled p1 raises).

Rebase conflict resolutions: statements/api.ts meta → this package's accepted Pick<> content;
ci.yml aggregate `needs` → dev's list (incl. backend-dpa-guard, route-manifest-drift) + this
package's security-regression insertion; generated.d.ts → dev's regenerated file (net-zero here,
matching the accepted revert ruling).

Whole-package evidence RERUN GREEN on the rebased tree (exact CI command strings):
`php tools/feature-lane-manifest-check.php` exit 0 · FeatureLaneManifestCheckerTest OK (46/123, re-run after the 5.6.2 level-8 guard) ·
`pnpm lint` exit 0 (incl. audit:i18n:local authority green on the revised seed) ·
`pnpm test:tools` 160/160 · `pnpm test:eslint-rules` all pass ·
OrphanedEventRatchetTest+ProjectorEmissionRatchetTest OK (8/9) by path.
