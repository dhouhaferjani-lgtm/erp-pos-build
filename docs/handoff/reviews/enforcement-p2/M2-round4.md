## Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 4

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD`. M0/M1 (through `eb431c3b2`) and the M2 round-1/2/3 states were gated in their own rounds; the round-4 delta is `358c2b8a9` (M2 fix round 3) + `4c0b3789c` (bookkeeping), touching `apps/api/tools/feature-lane-manifest-check.php`, `apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php`, `apps/api/tests/feature-lane-manifest.json`, `.github/workflows/ci.yml` (**comment-only** — verified: `git diff 60626fff4..HEAD -- .github/workflows/ci.yml` contains no non-comment `+`/`-` line), the decision doc and the progress YAML. Working tree verified clean (`git status --porcelain` empty). **Nothing in the repo was modified** — every experiment ran in `/tmp/sbx`, `/tmp/sbx2` mirrors (checker copy + symlinked `vendor`/`tests` + a copied `ci.yml`/manifest), now discardable.

**Domain lenses.** `frontend-conventions`: **does not apply** — the round-4 delta contains zero `apps/web` change (`git diff 60626fff4..HEAD --name-only | grep apps/web` → empty; the only FE file in the whole range, `features/treasury/statements/api.ts`, is M1 and was gated there). `tenancy-authz`: **applies and is the substantive surface** — the guarantee under test is that `tests/Feature/Security` (17 classes: per-module access control, marketplace kill-switches, rate-limit enforcement, the rule-12 module-gating surface) provably gates PR→dev. `ci.yml` is comment-identical across `60626fff4..HEAD`, so no test selection moved this round; the two anchored PG allowlists (`ci.yml:733`, `:830` — the tenancy/authz classes `TenantDatabaseIsolationTest`, `TenancyResolverFailClosedTest`, `SupportAccessPostgresEndToEndTest`, `ReceiptLocationScopeAuthorizationTest`, …) are unchanged. Two of my confirmed bypasses land directly on that PR→dev guarantee.

**Standing checks.** Rule 19: N/A — no money/quantity path in the delta. Migrations / named queues: none. Constructor injection: N/A (standalone CLI script + a bare `PHPUnit\Framework\TestCase`; no container, no `app()`). i18n en+fr: no user-facing strings. **Red-first: VERIFIED, not accepted from the report** — I extracted the pre-fix checker (`git show 60626fff4:…`) into a second sandbox and replayed the new cases' mutations: R-3 narrowing → `EXIT=0` pre-fix, R-1 `continue-on-error` → `EXIT=0` pre-fix / `EXIT=1` shipped. Non-vacuous. Suite: **20/20, 59 assertions, 3.1 s** (`./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php`).

**Round-3 findings — disposition.** R-1, R-2, R-3, R-5, R-6, R-7 fixed *at the level tested* and each re-verified by me (controls in the failed-bypass list). R-4 **only half-corrected** — see G-4. **R-8 and R-9: SILENTLY DROPPED AGAIN** — see G-4, G-5, G-6.

---

## New findings

### G-1 — P2 — CONFIRMED — a whole-directory lane can still be cut to one class: the R-3 fix denylists four *flags*, and the lane resolves by `str_starts_with`
`apps/api/tools/feature-lane-manifest-check.php:288-293` (resolution) · `:322-332` (narrowing denylist)

The R-3 fix rejects `--filter`/`--group`/`--exclude-group`/`--testsuite` on a lane's run line, but the lane is still resolved by `str_starts_with(trim($run), $selector)` — so **anything appended that is not one of those four tokens is invisible**, including a narrower *path*.

**Bypasses I ran (all PASSED — bypass succeeded):**
- `run: ./vendor/bin/phpunit tests/Feature/Security/ModuleAccessControlTest.php` → `EXIT=0`, `lane manifest OK`, `runs_on_pr_dev: true` still certified. The rule-12 lane runs **1 of 17** classes.
- `run: ./vendor/bin/phpunit tests/Feature/Treasury/AcquirerFeeServiceTest.php` → `EXIT=0`. **1 of 119**.
- `run: ./vendor/bin/phpunit tests/Feature/Security --list-tests` → `EXIT=0`. The step executes, exits 0, and runs **zero** tests.

**Failure scenario:** R-3 verbatim, one door sideways — *"the lane was real; it just did not run the group."* A PR quiets a slow or red directory lane by appending a path (or `--list-tests`, `--dry-run`, `--stop-on-failure` + a path), the manifest keeps certifying 215 classes of whole-directory coverage across Security/Treasury/Accounting, the debt figure keeps excluding them, and all 20 liveness cases stay green. **Fix (closes the family instead of the door):** stop denylisting tokens. Require the resolved run line to equal the selector plus only an explicit allowlist of neutral flags (`-c`, `--configuration`, `--colors`, …), and fail closed on anything else.

### G-2 — P2 — CONFIRMED — shell-level soft-fail defeats the same guarantee the R-1 `continue-on-error` fix just installed
`apps/api/tools/feature-lane-manifest-check.php:366-371`

`$soft` reads YAML `continue-on-error` on the step and the job. It does not read the command.

**Bypasses I ran (both PASSED — bypass succeeded):**
- `run: ./vendor/bin/phpunit tests/Feature/Security || true` → `EXIT=0`, `runs_on_pr_dev: true` certified.
- `run: ./vendor/bin/phpunit tests/Feature/Security; exit 0` → `EXIT=0`, same.

**Failure scenario:** identical to R-1's, which the parent's 2026-08-19 ruling and this round's fix exist to prevent — the Security suite goes red on an unrelated PR, someone appends two characters, the step still runs and still shows green, and the 17 module-gating/kill-switch classes stop gating PR→dev while the manifest asserts they do. This edit is *more* natural than `continue-on-error:` (it is what people type). **Fix:** same allowlist as G-1 — a lane run line that contains `||`, `;`, `|| true`, `set +e` or a trailing `exit 0` is not a gate.

### G-3 — P2 — CONFIRMED — the whole 1114-class COVERAGE DEBT is still erasable by a one-word search-and-replace, now through the disposition vocabulary
`apps/api/tools/feature-lane-manifest-check.php:238-241` (only `deferred` increments) · `:558-575` (report) · `apps/api/tests/feature-lane-manifest.json:5` (`excluded` = *"Genuinely cannot run in CI"*)

The N-3 fix closed the `lane` door (a lane disposition must name a whole-directory selector ending in the group). The `excluded` door is open: `$deferredGroups`/`$deferredClasses` count `deferred` only, and `excluded` requires exactly the same two fields these groups already carry (`reason` + integer `classes`).

**Bypass I ran (PASSED — bypass succeeded):** rewrote all 71 `"deferred": true` group entries to `"excluded": true`, reasons untouched → `EXIT=0`, `tests/Feature lane manifest OK`, and the **entire `⚠ COVERAGE DEBT` block disappeared**. The 71 reason strings still say *"No CI lane runs this directory… Turning the remainder on is an owner CI-budget decision"*, i.e. the record now asserts "genuinely cannot run in CI" about 1114 classes that demonstrably can — a false statement inside the guard's own manifest, which is the sin round-1 F-6 and round-3 N-7 were raised and fixed on.

**Failure scenario:** N-3 verbatim: the honest census that is this milestone's headline deliverable, and the number the escalated F-2 owner decision is priced from, is convertible to a clean all-clear with one search-and-replace, and the guard certifies it. Mitigation that keeps this at P2 rather than P1: the per-group ceilings still fire, so coverage cannot silently *grow* after the relabel. **Fix:** report debt over `deferred` **and** `excluded` (separately labelled), or require `excluded` to carry a machine-checkable justification kind that `deferred` cannot satisfy.

### G-4 — P2 — CONFIRMED — the round-3 response under-reports its own register **again**, in the very section that corrects R-4
`docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1373` · `:1376-1396` (§56)

The response opens *"CHANGES-REQUIRED — 0 P1, **4 P2**, **3 P3**"*. The round-3 register carries **4 P2 and 5 P3** (`grep -c "^### R-[0-9]* — P3 —" M2-round3.md` → 5). **R-8 and R-9 appear nowhere in the delta** except inside the committed register file itself — not fixed, not recorded, not routed. §56 states the principle exactly right (*"a record that makes a false statement about its own completeness, which the M4 whole-package gate and P3's `p2_landed_sha` precondition would then certify"*) and then reproduces the defect two paragraphs later, with the same off-by-two shape (round 2: reported 3 P3, actual 7).

**Failure scenario:** M4 and the P3-M0 `p2_landed_sha` precondition certify a package whose decision doc understates its own outstanding findings for the second consecutive round; the two dropped items (G-5, G-6 below) are exactly the kind that then never get done. **Fix:** correct the tally to 4 P2 / 5 P3 and disposition R-8 and R-9 explicitly (fix, route, or accept with a reason).

### G-5 — P3 — CONFIRMED — R-8 is still live: `if: always()` on a lane step hard-fails every PR with a factually false message
`apps/api/tools/feature-lane-manifest-check.php:361-364`, `:397-399`

**Test I ran (FAILED CI — false positive):** `if: always()` on the Security step → `EXIT=1`, *"the lane STEP carries `if: always()`, **which skips it**"*. `always()` skips nothing. Because the checker runs in `backend-architecture` — a job with **no `if:` guard** — this blocks every PR, including PR→dev, with a false statement in a guard's own output. Unchanged from round 3, and undispositioned (G-4). Not reachable today (no lane step carries an `if:`), hence P3.

### G-6 — P3 — CONFIRMED — R-9 is still live: aggregate membership is asserted for one lane only, and only by the test
`apps/api/tools/feature-lane-manifest-check.php` (no `all-checks-pass` logic) · `FeatureLaneManifestCheckerTest.php:233`

Only `security-regression`'s presence in `all-checks-pass` `needs` (`ci.yml:1249`) is asserted, and only from the PHPUnit case — neither `backend-architecture` (the job carrying the checker itself) nor `treasury-spine-pgsql` is pinned anywhere. Brief H-9 makes aggregate membership a package-wide obligation. Unchanged from round 3, and undispositioned.

### G-7 — P3 — CONFIRMED — the segment heuristic reintroduces the N-2 false-positive class for prefixed package-manager invocations
`apps/api/tools/feature-lane-manifest-check.php:448-451`

`$isPackageManager` requires the segment to *start* with `pnpm|npm|yarn|turbo` (optionally path-prefixed). **Test I ran (FAILED CI — false positive):** a step `run: env CI=1 pnpm --filter @autoerp/web build` → `EXIT=1`, *"UNANCHORED --filter in ci.yml (starts: @autoerp/web…)"* + *"DEAD --filter entry"*, blocking the ungated `backend-architecture` job on every PR. Same for `npx pnpm …`, `corepack pnpm …`, `sudo -E pnpm …`. Narrower than N-2 (the plain documented form is correctly skipped — verified `EXIT=0`), so P3 — but it is the same "textual heuristic standing in for which binary owns this flag" root cause, third occurrence.

---

## Bypasses and hypotheses that FAILED (no defect)

1. **`--filter` appended to a whole-directory lane** → `EXIT=1`, *"is a whole-directory lane but its run line carries --filter"*. R-3's fix holds for the tokens it names.
2. **`composer test -- --filter=AnalyticsTest|ZzzNotARealClass`** (CLAUDE.md's own command) → `EXIT=1`, UNANCHORED + DEAD. R-2 closed.
3. **Plain `pnpm --filter @autoerp/web build`** → `EXIT=0`, no false positive. N-2/R-6 closed in the documented form.
4. **`continue-on-error: true`** on the step and on the job → `EXIT=1` with the correct message. R-1 closed (and red-first re-derived: `EXIT=0` against the pre-fix checker).
5. **Transitive `needs` chain** and **same-job decoy step** → covered by cases 15/18 of the suite, both green; R-5/R-7 closed.
6. **Are the two real allowlists actually being read?** Non-vacuous: renaming one entry to `ZzzNoSuchClassTest` in `ci.yml:733` → `EXIT=1`, `DEAD --filter entry`. Invariants C/D bite on the live 112-entry list, and the `\`-continuation multi-line form is parsed correctly by the new segment split.
7. **Are invariant A and the ceiling vacuous?** No: deleting the `Api` group entry → `UNASSIGNED GROUP "Api"`, `EXIT=1`; lowering `Admin`'s ceiling to 8 → `COVERAGE DEBT GREW … ceiling is 8`, `EXIT=1`.
8. **Did this round move any test selection?** No — the `ci.yml` delta is comment-only; the `security-regression` job, its `all-checks-pass` membership (`ci.yml:1249`) and both anchored PG allowlists are byte-identical to the round-3 state. **Tenancy-authz: no coverage regression this round.**
9. **Scope creep.** No control file touched (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, the control manifest, `.claude/agents/*` — verified over the full range), no production application code, no migration, no queue, no `app()`.

---

**Disposition.** The round-3 fixes are real, correctly targeted and red-first-verified — I re-derived that the new cases fail against the pre-fix checker, the suite is 20/20, and the C/D/A/ceiling invariants are non-vacuous against the live tree. What holds the gate is the pattern round 3 itself named and did not break out of: **each fix closes the door it was shown, and the family stays open one step sideways.** The parent-ruled PR→dev Security guarantee falls to a narrower *path* argument (G-1) and to `|| true` (G-2), both single-token edits against the shipped script; the 1114-class debt this milestone exists to make visible is still erasable by one search-and-replace, now `deferred`→`excluded` (G-3). All three were bypassed against the shipped checker. G-1 and G-2 have a single structural fix (validate the lane run line against an allowlist shape rather than denylisting four flags) that would close the whole family instead of a fourth door. G-4 is separate and cheap, and is the second consecutive round in which the decision doc under-reports its own register — this time inside the section correcting that exact defect. Fix rounds after this: 4 of 5.

VERDICT: CHANGES-REQUIRED
