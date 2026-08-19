# Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 2

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD`. M0/M1 (through `eb431c3b2`) were gated separately; the round-2 delta is `6620e40e8` (M2 fix round 1) + `87d00b3e5` (F-2 parent ruling), on top of the round-1 M2 commits. Working tree clean; nothing was modified — every experiment ran against temp-dir mirrors, now removed.

**Domain lenses.** `frontend-conventions`: **does not apply** — the round-2 delta contains zero `apps/web` change. `tenancy-authz`: **applies, and is the substantive change of this round** — `tests/Feature/Security` (17 classes: `ModuleAccessControlTest`, the seven per-module `*ModuleAccessControlTest`, the four marketplace kill-switch classes, `RateLimitEnforcementTest`, `FirstTenantProductionSecurityTest`, the privileged-audit pair, `SecurityHeadersTest`) moves from the `if:`-gated `backend-test` job onto a new ungated `security-regression` job, i.e. onto the PR→dev merge gate. Verified as a strict coverage increase, verified green, and verified pinned by a liveness test (see "verified clean" 1–4).

**Standing checks.** Rule 19: N/A — no money/quantity path touched. Migrations / named queues: none. Constructor injection: N/A (standalone CLI script + a `PHPUnit\Framework\TestCase`; no container, no `app()`). i18n: no user-facing strings. Red-first: each F-2…F-5 fix ships the round-1 bypass as an executable test case (`FeatureLaneManifestCheckerTest`, 9 cases, **9/9 green locally, 1.3 s**), and I re-ran each bypass myself against the fixed script — all now fail closed.

---

## Round-1 findings — disposition

**F-1 (P1) — CLOSED.** The executor withdrew the reasoning and set M2 `blocked_owner` (`6620e40e8`); the parent then ruled (`87d00b3e5`), and the ruling does **not** take the money decision: A vs B-full vs B-partial is `status: escalated_to_owner` with `owed_at_promotion` carrying the four costed options (`enforcement-p2.progress.yaml:141-168`). Only the zero-cost landed state is adopted as interim, plus the Security sub-decision, which is a safety gap rather than a cost policy. Same mechanism the parent used for M1 STOP-A (`:200-213`), which this wave has already accepted. I do not reopen it.

**F-2, F-3, F-4, F-5 — CLOSED, each re-verified by me, not accepted from the report:**
- F-2: all five argument forms now scanned and fail closed — `--filter "A|B"` → `EXIT=1`, `--filter=A` → `EXIT=1`, unresolvable → hard `UNPARSEABLE` error (`feature-lane-manifest-check.php:303-326`).
- F-3: commenting out `run: ./vendor/bin/phpunit tests/Feature/Treasury` now yields `LANE … is a FICTION`, `EXIT=1` (`:237-253`, YAML-resolved).
- F-4: **both halves of the brief's negative proof re-derived independently** on a real copied tree — planting `tests/Feature/Admin/ZzzPlantedSilentTest.php` (existing uncovered group) → `COVERAGE DEBT GREW: group "Admin" now holds 10 class(es), ceiling is 9`, `EXIT=1`; planting a brand-new directory → `UNASSIGNED GROUP`, `EXIT=1` (`:195-217`).
- F-5: liveness test exists, runs in the **same** job as the detector (`ci.yml:193-201`), and is in preflight (`scripts/preflight.sh:189-195`).

F-6/F-7/F-9 fixed; F-8/F-10 routed/recorded as stated.

---

## New findings

### N-1 — P2 — CONFIRMED — a **step-level** `if:` defeats the exact invariant the parent ruling just installed
`apps/api/tools/feature-lane-manifest-check.php:276-287` · `.github/workflows/ci.yml:419-421`

`runsOnPrDev` is derived from the **job's** `if:` only. **Bypass I ran (PASSED — bypass succeeded):** left `security-regression` ungated and added `if: github.base_ref == 'main'` to the *step* `Security regression suite (module gating + kill-switches)` → checker `EXIT=0`, "lane manifest OK", `runs_on_pr_dev: true` still certified. GitHub skips that step on PR→dev exactly as a job-level guard would. The ruling's own liveness case (`FeatureLaneManifestCheckerTest.php:206-223`) tests only the job-level form, and `test_the_security_suite_is_wired_to_a_job_with_no_if_guard` (`:225-247`) asserts the step's `run:` string but never that the step has no `if:`.

**Failure scenario:** a future PR quiets a red Security job by adding two words to the step instead of the job; the 17 module-gating/kill-switch classes silently leave the PR→dev gate again, the manifest keeps asserting they are on it, and both liveness cases stay green. **Fix:** treat a step-level `if:` as gating (fold it into `runsOnPrDev`, or reject any `if:` on a step whose `run` is a lane selector), and add the step-level plant as a 10th case.

### N-2 — P2 — CONFIRMED — the `--filter` scanner hard-fails on `pnpm --filter`, the repo's own documented workspace command
`apps/api/tools/feature-lane-manifest-check.php:304-344`

The F-2 fix generalised from "PHPUnit filters" to "every `--filter` token in every `run:` script". This is a pnpm workspace: `pnpm --filter @autoerp/web …` is the standard form, prescribed in `AGENTS.md:7,13` and used by `.claude/agents/frontend-conventions-reviewer.md:31`. **Test I ran (FAILED CI — false positive):** adding a step `run: pnpm --filter @autoerp/web build` →
`✗ UNANCHORED --filter in ci.yml (starts: @autoerp/web…)` + `✗ DEAD --filter entry "@autoerp/web"`, `EXIT=1`.

**Failure scenario:** the first CI step that builds or tests a workspace package the normal way hard-fails `backend-architecture` — a job with **no `if:` guard**, so this blocks *every* PR including PR→dev — with an error telling the author to rewrite their pnpm selector as `/\\(A|B)::/`. Nothing in the message or the docblock says the check only means PHPUnit. **Fix:** only scan `--filter` occurrences in a script that invokes `phpunit` / `artisan test` (still fail closed *within* those), or exclude `pnpm`/`turbo` invocations explicitly.

### N-3 — P2 — CONFIRMED — a `lane` disposition is never checked to actually cover the group; the whole 1114-class debt is erasable by a one-word edit
`apps/api/tools/feature-lane-manifest-check.php:184-186`, `:200-217`

Invariant A is documented as "a real CI lane" (`:28-30`), but a group's `lane` value is validated only for *existence in `lanes`* — never that the named lane's selector covers that group's directory. Lane-dispositioned groups are also exempt from the non-growth ceiling (`:200`, correct only because all three lanes are whole-directory selectors today).

**Bypass I ran (PASSED — bypass succeeded):** rewrote all 71 `deferred` groups to `"lane": "treasury-spine-pgsql/feature-treasury"` → `EXIT=0`, `every group has a disposition; every declared lane is present in ci.yml`, and the `⚠ COVERAGE DEBT` block **disappeared entirely**. This is the F-3 failure class one level down: the lane is real, it just doesn't run the group. **Failure scenario:** the honest census this milestone exists to produce is convertible into a clean all-clear with a search-and-replace, and the guard's own output certifies it. **Fix (cheap):** for whole-directory selectors, require the selector path to end in the group name; for any other lane shape, require the group to carry a ceiling too.

### N-4 — P3 — CONFIRMED — `needs:` on a gated job skips `security-regression` while the manifest still certifies PR→dev
`apps/api/tools/feature-lane-manifest-check.php:276-277`

**Bypass I ran (PASSED):** `needs: [backend-test]` on `security-regression` → `EXIT=0`. GitHub skips a job whose dependency was skipped, so the suite would not run on PR→dev despite `runs_on_pr_dev: true`. Lower severity than N-1 because it is a less natural edit, but it is the same hole through a second door.

### N-5 — P3 — CONFIRMED — the scanner sees only inline `run:` text in `ci.yml`
`apps/api/tools/feature-lane-manifest-check.php:106-123`, `:304`

A `--filter` inside a shell script invoked from a step (`run: bash scripts/run-extra-tests.sh` → `EXIT=0`, verified), inside a `uses:`/`with: args:`, or in `smoke-test.yml` / `sonarcloud.yml` / `react-doctor.yml` is invisible. No test-running `--filter` lives outside `ci.yml` today, so this is a note, not a live hole.

### N-6 — P3 — PLAUSIBLE — `runs_on_pr_dev` is a substring heuristic
`apps/api/tools/feature-lane-manifest-check.php:277` — `str_contains($jobIf, "base_ref == 'dev'")`. An `if:` such as `github.event_name == 'push' && github.base_ref == 'dev'` contains the substring but can never be true on PR→dev (`base_ref` is empty on push), so the checker would certify a lane that never runs there. Not reachable with today's three `if:` expressions (verified: `treasury-spine-pgsql` at `ci.yml:845` genuinely includes PR→dev).

### N-7 — P3 — CONFIRMED — two stale statements inside the guard's own documentation
`apps/api/tests/feature-lane-manifest.json:1` — still reads *"`classes` is documentation only; the checker never trusts it"*, which the F-4 ceiling made false. `apps/api/tools/feature-lane-manifest-check.php:12` — still reads *"`tests/Feature/Security` (backend-test)"*, which the parent ruling made false. **Failure scenario:** a maintainer follows the manifest header, edits or drops `classes`, and meets a hard failure the file told them could not exist. This package's own standard (round-1 F-6, fixed) is that a false statement in a guard's output is unacceptable; the same applies to its manifest header.

### N-8 — P3 — CONFIRMED — "+~43 s" understates what the F-2 gate is actually measuring
`docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1257`, `:1272` · `enforcement-p2.progress.yaml:152` · `ci.yml:357-424`

The figure the parent ruled on counts the phpunit invocation only. The implementation is a **whole new job**: checkout + setup-php + composer cache/install + env setup, on every PR→dev, PR→main, push→main and dispatch — realistically 2–3 min of billed runner time, not 43 s. My own runs of the suite alone were 57 s and 35 s on a fast laptop, so even the suite figure is optimistic for a CI runner. The ruling stands on its merits (a live safety gap), but F-2 *is* the CI-minutes gate and this is the one number in the package that should not be understated.

### N-9 — P3 — CONFIRMED — the escalated owner decision is carried by prose only
`scripts/adversarial-review-final.sh:161-162` checks only that the final milestone is `status: review` with `fix_rounds <= max`; nothing reads `owner_gates`. `escalated_to_owner` / `owed_at_promotion` are new values no script consumes. M3's milestone title (`enforcement-p2.progress.yaml:234`) enumerates the required announcement lines (OpenAPI reconciliation, the M1 `frontend-lint` contract change) and names **neither** the F-2 execution-scope owner line **nor** the second CI-contract change this round introduces (`security-regression` on PR→dev). Both then depend on the executor remembering at M3. Parent action, not an executor fix: add both to M3's record.

### N-10 — P3 — CONFIRMED — the M2 record points at the pre-fix tip
`enforcement-p2.progress.yaml:228-232`: `commit: 4ee7ca1c5`, `updated: 4ee7ca1c5`, while round 2 reviews `87d00b3e5`. Consistent with M1's mid-round lag (the accepting commit backfills it), so bookkeeping only — but the accepted-tip fields must name `87d00b3e5` (or its successor) before M4's receipt-driven gate.

---

## Bypasses and hypotheses that FAILED (no defect)

1. **No Redis service in the new job.** `.env.example` ships `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION`/`BROADCAST_CONNECTION=redis` (`apps/api/.env.example:92-102`) and the job provisions no redis, unlike `backend-test`. I ran the suite with Redis made unreachable (`REDIS_HOST=127.0.0.1 REDIS_PORT=1`): **93 tests, 305 assertions, all green**. `phpunit.xml` pins array/array/sync/null and wins over the `.env.testing` copy. No defect.
2. **Coverage lost by deleting the step from `backend-test`.** No — the new job has no `if:` and the workflow triggers on PR→{main,dev}, push→main, dispatch, so it is a strict superset of the old step's events.
3. **H-9 (aggregate membership).** `security-regression` is in `all-checks-pass` `needs` (`ci.yml:1242`), asserted by `FeatureLaneManifestCheckerTest:233`. Real.
4. **Job-level re-gate genuinely fires.** Planting `if: github.base_ref == 'main'` on the job → `LANE "security-regression" claims runs_on_pr_dev=true but job … says false`, `EXIT=1`. The ruling's named liveness proof is non-vacuous (its gap is N-1, not vacuity).
5. **Did the fix round quietly touch the two anchored allowlists?** No — `ci.yml:726` and `:823` are byte-identical across `4ee7ca1c5..HEAD`; the round's only `ci.yml` hunks are the liveness step, the step removal, the new job, and the `needs` line.
6. **Identical `run:` strings colliding in `stepJob` (`:118`, last-wins).** Reachable, but every lane declares `job`, so a collision surfaces as a loud `claims job "X" but its selector lives in job "Y"` failure, not a silent pass.
7. **Scope creep.** No control file touched (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `.claude/agents/*`), no production application code, no migration, no queue.

---

**Disposition.** F-1 is properly closed — the executor withdrew the override, the parent ruled without taking the owner's money decision, and the Security move is real, green, wired into the aggregate and pinned by a test. F-2…F-5 are genuinely fixed; I reproduced every one of round 1's bypasses against the new script and all now fail closed. What holds the gate is three new P2s of the same family the fix round was closing: **N-1** re-opens the very PR→dev guarantee this round installed, through a step-level `if:` the new liveness case does not test; **N-2** turns a documented `pnpm --filter` command into a hard failure of an ungated, all-PR job; **N-3** lets a one-word manifest edit erase the entire 1114-class debt the milestone exists to make visible. Fix rounds after this: 2 of 5.

VERDICT: CHANGES-REQUIRED
