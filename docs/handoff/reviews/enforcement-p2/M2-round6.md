## Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 6

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD` (HEAD `82ee2b6c3`). M0/M1 (through `eb431c3b2`) were gated in their own rounds; the M2 surface is `d215569a4..HEAD`. Round-6 delta = `990e3ae26` (fix round 5) + `82ee2b6c3` (bookkeeping), touching `apps/api/tools/feature-lane-manifest-check.php`, `apps/api/tests/{feature-lane-manifest.json,Architecture/FeatureLaneManifestCheckerTest.php}`, the decision doc, the progress YAML and the committed round-5 register. **`ci.yml` is byte-identical across `4c0b3789c..HEAD`** (`git diff --name-only` — no workflow path), so no test selection moved this round. Working tree verified clean before and after (`git status --porcelain` empty). Every experiment ran in `/tmp` sandboxes (checker copy + copied `ci.yml`/manifest + a name-mirrored empty-file test tree); **nothing in the repository was modified**, and the sandboxes were removed.

**Domain lenses.** `frontend-conventions`: **does not apply to M2** — the M2 range contains **zero** `apps/web` paths (`git diff --name-only eb431c3b2..HEAD | grep -c '^apps/web/'` → 0). `tenancy-authz`: **applies and is the substantive surface** — M2 moves `tests/Feature/Security` (module access control, marketplace kill-switch, rule-12 gating) out of the `if:`-gated `backend-test` into a new ungated `security-regression` job. Verified in code, not from prose: the job carries no `if:` (`ci.yml:355-357`), is in `all-checks-pass` `needs` (`:1249`), its serviceless setup is sufficient because `phpunit.xml:34-56` pins `APP_ENV=testing`, `APP_KEY`, sqlite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null` — and I ran the suite: **93 tests, 305 assertions, OK, 37 s**. Net a real coverage gain, no regression.

**Standing checks.** Rule 19: N/A — no money/quantity path in the delta. Migrations / named queues: none. Constructor injection: N/A — standalone CLI script + a bare `PHPUnit\Framework\TestCase`; `grep` for `app(`/`config(`/`env(` in both new PHP files → **no hits**. i18n en+fr: no user-facing strings (developer/CI output only). **Anchoring is coverage-neutral, re-derived independently:** for every entry in both allowlists I enumerated all 1708 test-class basenames containing that entry — the only substring-only extra across both lists is `AnalyticsTest → ExpenseAnalyticsTest`, and `ExpenseAnalyticsTest` is itself an allowlist entry. **0 dropped, 0 added**, matching round 5's `--list-tests` measurement by a different method. **Round-5 fixes re-verified non-vacuous by re-running the round-5 bypasses against HEAD**: `-c` on the Security lane → EXIT=1; `pnpm test:backend --filter=AnalyticsTest` → EXIT=1 `UNANCHORED`; delete the manifest `job` key + drop the job from the aggregate → EXIT=1; retire the Security lane → EXIT=1 `TOTAL COVERAGE DEBT GREW`. **Brief H-6 negative proof re-run by me on a mirrored tree, both halves:** new top-level group → `UNASSIGNED GROUP` EXIT=1; new class in an existing uncovered group (`Admin`) → `COVERAGE DEBT GREW` + `TOTAL … GREW` EXIT=1; new root-level file → same; new nested dir inside an uncovered group → same; new class in a covered group (`Treasury`) → EXIT=0 (correct). **Ceilings carry no slack** — all 71 equal current counts exactly and sum to `debt_ceiling: 1114`. Checker EXIT=0 on the real tree; liveness suite **35/35, 94 assertions, 6.1 s**. The checker step (`ci.yml:191`) runs *before* the currently-red deptrac step (`:204`), so the wave-level red ratchet does not mask it.

---

## New findings

### N-1 — P2 — CONFIRMED — the workflow's TRIGGER SET is never verified, so every `runs_on_pr_dev: true` claim is falsifiable by a one-token edit
`apps/api/tools/feature-lane-manifest-check.php:389-411` · `.github/workflows/ci.yml:7`

The checker's own comment (`:389-391`) states the contract: *"The manifest also claims which EVENTS the lane runs on. Verify that claim against the owning job's `if:` rather than trusting the prose."* It verifies the job guard, the step guard, `continue-on-error`, shell-soft forms and the transitive `needs` chain — but never the **root of the event graph**, `on:`. `$workflowYaml['on']` is read nowhere in the file.

**Bypass I ran (PASSED):** changed `on.pull_request.branches` from `[main, dev]` to `[main]` → `EXIT=0`, `lane manifest OK`, all three lanes still certified `runs_on_pr_dev: true`.

**Failure scenario:** one token removes **the entire CI workflow** from PR→`dev` — including the parent-ruled `security-regression` job whose whole justification was closing the rule-12 blind spot on the day-to-day merge gate — while the manifest, the checker and all 35 liveness cases keep asserting PR→dev coverage. This is the headline verified assertion of the milestone, and it is the one input to it that is unchecked. **Fix (~4 lines):** whenever any lane declares `runs_on_pr_dev: true`, assert `dev ∈ $workflowYaml['on']['pull_request']['branches']`. I confirmed Symfony's parser yields the **string** key `"on"` (not YAML-1.1 boolean `true`), so the lookup is direct.

### N-2 — P2 — CONFIRMED — the checker's own host job and steps are exempt from all five gating analyses the checker applies to lanes
`apps/api/tools/feature-lane-manifest-check.php:496-513` (membership only) vs `:404-473` (the five analyses, lanes only) · `.github/workflows/ci.yml:143-202`

`$mustBeInAggregate` pins `backend-architecture` into the `all-checks-pass` `needs` list and nothing else. Every anti-disabling property the checker enforces for `security-regression` is unenforced for the job that carries the checker itself.

**Four bypasses I ran, each `EXIT=0` / `lane manifest OK`:**
1. `if: github.base_ref == 'main'` on the `backend-architecture` **job**;
2. `continue-on-error: true` on the **job**;
3. `needs: [backend-test]` on the **job** (`backend-test` is `if:`-gated off PR→dev — the exact transitive shape case `test_it_fires_on_a_transitively_gated_needs_chain` proves the checker *can* detect for a lane);
4. `continue-on-error: true` on the **`Check tests/Feature CI-lane manifest` step**. (Also: a step-level `if:`, and outright deletion of both steps — both `EXIT=0`; nothing asserts the steps exist in `ci.yml`.)

**Failure scenario, grounded in this wave's own recorded state — not hypothetical:** the YAML `blockers` block records that `backend-architecture`'s deptrac ratchet is **RED at `base_sha`** (99 → 174 violations, including a BLOCKER category, "UNRECORDED anywhere"). The foreseeable remediation an unrelated lane reaches for is to gate or soften exactly that job. Doing so silently removes the 2(b) manifest checker **and its liveness suite** from PR→dev, and every check in this package still reports OK. Asymmetric in the wrong direction: 43 seconds of security tests are protected against five disabling doors; the package's entire backend guard surface is protected against none. **Fix (~10 lines, reusing machinery already in the file):** run the existing job-`if` / `jobSoft` / `stepIf` / `stepSoft` / `needs`-BFS analysis over `backend-architecture` and the two checker steps, resolved from the workflow, and fail if any is gated or softened.

### N-3 — P2 — CONFIRMED — the "family closed EMPIRICALLY" claim is false: the new liveness case tests the manifest selector, not ci.yml's run line
`apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php:604` (docblock) · `:620-636` (the code) · `docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md` §(63) item 2

The docblock says *"Every declared lane's **run line** is executed with `--list-tests` appended"*, and §(63) says this *"defeats `-c`, `--group`, `--list-tests`, a narrower path and the whole empty-selection family **by observation rather than by maintaining a flag list**"* — presented as the better of the two H-1 fixes and as satisfying brief R2-H-7.

What the code executes is `$lane['selector']` — the **manifest's declared string** (`:621`, `:625`). The case never reads `ci.yml`; nothing appended to a lane's `run:` line can change its outcome. Appending `-c evil.xml` to the workflow leaves `selector` untouched, so this case stays green; only the lexical neutral-flag allowlist catches it.

**Failure scenario:** the empty-selection family is closed **lexically only** — by the same hand-maintained flag list whose maintenance produced the round-4 `-c` defect. A future round that blesses one more "neutral" token (or any emptying flag not yet imagined) has no empirical backstop, contrary to the acceptance evidence M4 will carry into the handback. **Fix:** resolve each lane's actual run line from the parsed workflow — the checker already does this (`:300-317`) — and execute *that* with `--list-tests`, asserting a nonzero count.

### N-4 — P3 — CONFIRMED (from code) — the anchoring/uniqueness lint is scoped to `ci.yml` `run:` blocks only
`apps/api/tools/feature-lane-manifest-check.php:47`, `:528-614`

Only `.github/workflows/ci.yml` is read. Relocating either allowlist into a shell script (`run: ./ci/run-pgsql-lane.sh`) or a second workflow file removes it from the scan entirely, and the brief's *"CI-side lint asserting every allowlist entry matches exactly one test class"* stops applying — silently, since neither list backs a certified lane. Contained today (no such script exists; `composer test` and `pnpm`-forwarded forms are correctly scanned, re-verified), hence P3. Worth one line in the M3 checklist so P3-M2 does not wire its country-chart entry into a list that can leave the lint's field of view.

### N-5 — P3 — CONFIRMED (from code) — the `-c` ban covers a *substituted* config; the *default* `phpunit.xml` can still gut a certified lane, undetected
`apps/api/phpunit.xml:24-28` · checker: no reference to `phpunit.xml` anywhere

`phpunit.xml` already carries a global `<groups><exclude><group>sweep-progress</group></exclude></groups>`. Adding one more excluded group is a normal-looking edit that removes annotated tests from `./vendor/bin/phpunit tests/Feature/Security` while the manifest keeps certifying 17 classes. Total emptying would be caught by `test_every_lane_actually_selects_tests` (which happens to run the same string today); **partial** exclusion would not be caught by anything. Milder than H-1 (partial, and the exclusion is visible in a tracked file), hence P3 — but it is the same "a config redefines what the lane runs" mechanism, through the door the `-c` ban does not cover.

### N-6 — P3 — CONFIRMED — residual transcript/comment drift in shipped files
`docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1144` still reads *"all 1 707 classes"* (the shipped checker prints **1708**; the §(46) transcript at `:1103` was correctly regenerated, this prose line was not). `.github/workflows/ci.yml:196` still says *"**Seven** cases drive COPIES of the tree in a temp dir"* — the suite now has **35**. Round 5's H-5 is therefore closed only in the transcript half, and the second instance is inside the shipped workflow file.

---

## Bypasses and hypotheses that FAILED (no defect)

1. **Duplicate a lane's `run:` string into a second, gated job** → `EXIT=1`, *"resolves to 2 steps; the lane must be unambiguous"*. Fails closed.
2. **Prefix a lane's run line** (`echo x && ./vendor/bin/phpunit tests/Feature/Security`) → `EXIT=1`, `LANE … is a FICTION` (the `str_starts_with` resolution refuses it).
3. **Indirect the allowlist through an env/GitHub variable** (`--filter="$FILTER"`, `--filter=${{ vars.F }}`) → `EXIT=1`, `UNANCHORED` + `DEAD --filter entry`. Fails closed both ways.
4. **`if:` guards that only *look* like PR→dev** — `base_ref != 'dev'`, `base_ref == 'dev2'`, an unrelated expression → all correctly resolve to "not PR→dev" and produce the `claims runs_on_pr_dev` mismatch.
5. **All four round-5 bypasses re-run against HEAD** (`-c`, pnpm-forwarded filter, deleted manifest `job` key, retired lane) → `EXIT=1` each, with the right message. Every round-5 fix is live and non-vacuous.
6. **Swap-in trick**: delete one class from an uncovered group, add another → `EXIT=0`. Correct by design (ceiling semantics; the group's coverage state is unchanged), not a defect.
7. **Does anchoring drop coverage?** No — independently re-derived by basename containment across all 1708 classes: the single substring-only extra is itself an allowlist entry.
8. **Are the ceilings padded?** No — all 71 equal current counts exactly; laneless total 1114 == `debt_ceiling`.
9. **Is the new `security-regression` job actually runnable serviceless?** Yes — 93 tests green locally under exactly the pinned `phpunit.xml` env; no PG, no Redis needed.
10. **Scope creep.** None. Zero `apps/web` paths, zero production application code, no migration, no queue, no control file (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`) in `--name-only` over the whole range. `scripts/preflight.sh` (+8) mirrors the two new CI steps for local parity — in scope, and the convention it cites requires it.

---

**Disposition.** The deliverable itself is in good shape and I could not break it from the outside: the manifest is exhaustive and slack-free, both halves of the brief's H-6 negative proof fire, anchoring is provably coverage-neutral by a second method, every round-5 fix is live, and the tenancy-authz substance (the Security suite onto PR→dev) is a verified net gain that actually passes. What remains is one coherent family, and it is the family this package exists to end: **the guard does not apply to itself the analysis it applies to everything else.** Three unchecked doors — the workflow trigger set (N-1), the checker's own host job and steps (N-2, four demonstrated variants), and a liveness case that reads the manifest instead of the workflow while its docblock and the decision doc say otherwise (N-3) — each leaves the shipped guard reporting `lane manifest OK` while the thing it certifies has stopped happening. N-2's failure scenario is not speculative: this wave's own YAML records `backend-architecture` as RED at base, and gating or softening that job is the obvious remediation someone reaches for. All three fixes reuse machinery already present in the file (`ifs`/`jobSoft`/`stepIf`/`stepSoft`/`needs`-BFS at `:404-473`, run-line resolution at `:300-317`) and total roughly 20 lines. N-3 additionally matters because it is a **false claim in the acceptance evidence** that M4 would carry into the handback.

Process note, not my ruling to make: `fix_rounds: 5` of `max_fix_rounds: 5` with no M2 override, so this verdict puts the milestone at STOP condition A (`blocked_review`) pending a parent decision.

VERDICT: CHANGES-REQUIRED
