## Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 5

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD`. M0/M1 (through `eb431c3b2`) and M2 rounds 1–4 were gated in their own rounds; the round-5 delta is `aaa4de9be` (M2 fix round 4) + `f8d7fd080` (bookkeeping), touching `apps/api/tools/feature-lane-manifest-check.php`, `apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php`, the decision doc, the progress YAML and the committed round-4 register. **`ci.yml` is byte-identical across `4c0b3789c..HEAD`** (`git diff 4c0b3789c..HEAD -- .github/workflows/ci.yml` → empty), so no test selection moved this round. Working tree verified clean before and after (`git status --porcelain` empty, HEAD `f8d7fd080`). Every experiment ran in `/tmp/p2sbx` (checker copy + symlinked `vendor`/suite dirs + copied `ci.yml`/manifest) and `/tmp/evil.xml`; **nothing in the repo was modified.**

**Domain lenses.** `frontend-conventions`: **does not apply to M2** — the entire M2 range (`d215569a4^..HEAD`) contains zero `apps/web` paths (verified by `--name-only`); the only FE file in the whole range, `features/treasury/statements/api.ts`, is M1 and was gated there. `tenancy-authz`: **applies and is the substantive surface** — M2 moves `tests/Feature/Security` (module access control, marketplace kill-switches, the rule-12 gating surface) out of the `if:`-gated `backend-test` into the new ungated `security-regression` job. I verified that guarantee end-to-end rather than from the doc: the job has no `if:` (parsed YAML), the workflow triggers on `pull_request: [main, dev]`, the job is in `all-checks-pass` `needs`, and the suite is **green locally under exactly the pinned env** — `./vendor/bin/phpunit tests/Feature/Security` → **93 tests, 305 assertions, OK, 60 s** (`phpunit.xml` pins `DB_CONNECTION=sqlite:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`, so the job's serviceless setup is sufficient). The other two lanes' job (`treasury-spine-pgsql`) genuinely carries `base_ref == 'dev'`. **Net: a real coverage gain, no coverage regression.**

**Standing checks.** Rule 19: N/A — no money/quantity path in the delta. Migrations / named queues: none. Constructor injection: N/A (standalone CLI script; a bare `PHPUnit\Framework\TestCase`; no container, no `app()`). i18n en+fr: no user-facing strings. **Anchoring is coverage-neutral — replicated independently, not accepted from §(46):** I extracted both allowlists at base and at HEAD and compared `--list-tests` class sets under `phpunit-pgsql.xml` — lane 1: 112 → 112, **0 dropped, 0 added**; lane 2: 16 → 16, **0 dropped, 0 added**; and the unanchored control still selects `ExpenseAnalyticsTest` from `AnalyticsTest`. **Red-first: VERIFIED against the pre-fix checker (`git show 4c0b3789c:…`), not accepted from the report** — narrower path → `EXIT=0` pre-fix; `|| true` → `EXIT=0` pre-fix; mass `deferred`→`excluded` → debt block gone pre-fix; aggregate-`needs` removal → `EXIT=0` pre-fix; and the two false positives the round fixed were reproduced red pre-fix (`if: always()` → `EXIT=1` "which skips it"; `env CI=1 pnpm --filter @autoerp/web build` → `EXIT=1` UNANCHORED). All six are non-vacuous. Liveness suite: **30/30, 79 assertions, 4.8 s**. Invariant A and the ceiling re-proved non-vacuous on the live tree (deleting the `Api` entry → `UNASSIGNED GROUP`; lowering `Admin`'s ceiling → `COVERAGE DEBT GREW`), and **every one of the 71 ceilings equals its group's current class count exactly — no slack**.

**Round-4 findings — disposition.** G-1, G-2, G-3, G-5, G-6, G-7 fixed and each re-verified by me (controls in the failed-bypass list). G-4 corrected: §(59) restates round 3 as 4 P2 / 5 P3, dispositions R-8 and R-9 explicitly, and the round-4 tally in the doc (0 P1, 4 P2, 3 P3) matches the round-4 register exactly (`grep -c` re-run by me).

---

## New findings

### H-1 — P2 — CONFIRMED — the allowlist admits `-c` / `--configuration`, the one flag that can redefine the entire run; the rule-12 lane can still be cut to **zero tests, exit 0**
`apps/api/tools/feature-lane-manifest-check.php:339` (`$neutralValueFlags`) · `:337-375`

The round-4 fix inverted the rule to "selector plus neutral flags only" — correct in shape — but `-c`/`--configuration` is not a neutral flag: it replaces the bootstrap, the env, the group filters and the testsuite definitions for the entire invocation.

**Bypass, proven in both halves and then end-to-end:**
- Checker accepts it: `run: ./vendor/bin/phpunit tests/Feature/Security -c phpunit-security.xml` → `EXIT=0`, `lane manifest OK`, `runs_on_pr_dev: true` still certified. Same for `--configuration /tmp/evil.xml`.
- The runner obeys it: `./vendor/bin/phpunit tests/Feature/Security -c /tmp/evil.xml` (a config whose only content is `<groups><include><group>zzz-never-annotated</group></include></groups>`) → **`No tests executed!`, `EXIT=0`**. `apps/api/phpunit.xml` sets no `failOnEmptyTestSuite`, and I confirmed the general property separately: `--group zzznonexistent` → `EXIT=0`.

**Failure scenario:** G-1 verbatim, through the one token the allowlist blesses. The repo's own convention already passes configs on test lanes (`php artisan test -c phpunit-pgsql.xml`, `ci.yml:732`, `:829`), so `-c <something>.xml` on the Security lane reads as a normal, reviewable-looking edit — while the 93 module-gating/kill-switch tests run zero times, the step goes green, the manifest keeps certifying 17 classes and `runs_on_pr_dev: true`, and all 30 liveness cases stay green. A config that merely *excludes a group* (the repo's `phpunit.xml` already excludes `sweep-progress`, `:24-28`) does the same thing partially and even more quietly.

**In fairness to the executor:** round 4's own register prescribed this allowlist and named `-c` in its example set. That was my predecessor's error, not a deviation — but the defect is live in the shipped guard and has the same consequence the last three rounds blocked on.

**Fix (one line, or one better line):** drop `-c`/`--configuration` from `$neutralValueFlags`, so a lane needing a config is an explicit reviewed exception. Structurally better, if cheap enough: have the liveness test run the lane's run line with `--list-tests` and assert a **nonzero** selected-test count — that closes `-c`, `--group`, `--list-tests`, a narrower path and the empty-selection family the brief itself names (R2-H-7) empirically rather than lexically.

### H-2 — P3 — CONFIRMED — the H-9 aggregate assertion is keyed off an OPTIONAL manifest field; deleting one key erases it
`apps/api/tools/feature-lane-manifest-check.php:489-494` · `:380-388`

`$mustBeInAggregate` is built from `$lane['job']`, and `job` is optional everywhere (`:380` uses `?? null`). `$owningJob` — the job the checker already resolved from the workflow itself, authoritatively — is not used.

**Bypass I ran (PASSED):** removed `"job": "security-regression"` from the manifest lane and removed `security-regression` from `all-checks-pass` `needs` → `EXIT=0`, `lane manifest OK`. **Control:** removing it from `needs` alone → `EXIT=1`, correct message. So the fix works exactly until someone deletes the key it depends on, and the job-identity mismatch check (`:381`) vanishes in the same edit.

**Failure scenario:** the H-9 obligation G-6 raised is re-openable by a one-key manifest deletion, with the guard silent. Contained (the aggregate only gates PR→main/push→main today, and G-6 was itself P3), hence P3. **Fix:** key the check off `$owningJob`, or make `job` required.

### H-3 — P3 — CONFIRMED — the ratchet is per-group only: a whole lane can be retired and the total debt can GROW, with `EXIT=0`
`apps/api/tools/feature-lane-manifest-check.php:258-275` (per-group ceiling) · `:667-684` (report)

**Bypass I ran (PASSED):** relabelled group `Security` from `lane` to `deferred` with a fresh `classes: 17` ceiling, deleted the `security-regression` lane from the manifest and the job from `ci.yml`, and dropped it from the aggregate → `EXIT=0`, `lane manifest OK`, debt line moves **1114 → 1131**.

**Failure scenario:** the parent-ruled PR→dev Security guarantee is removable without a single failing check; only the printed number moves. Materially milder than G-3 (the number stays truthful and *grows*, and the diff is loud), so P3 — but it means the checker enforces "no new *silent* hole", not "no coverage loss", and it does **not** machine-enforce the brief's reciprocal 2(b) obligation that *"the landed P3 test must never be silently dropped from CI"* — a later P2 restructuring can drop P3's country-chart lane the same way. Worth one sentence in the M3 announcement checklist / handback so P3-M2 does not assume machine protection it does not have. **Fix, if cheap:** a global debt ceiling alongside the per-group ones, or a pinned minimum lane set.

### H-4 — P3 — CONFIRMED — the package-manager heuristic now fails the other way: a phpunit `--filter` behind a pnpm script is invisible
`apps/api/tools/feature-lane-manifest-check.php:540-551`

**Bypass I ran (PASSED):** a step `run: pnpm test:backend --filter=AnalyticsTest` → `EXIT=0`, no finding. **Control:** the identical filter as `./vendor/bin/phpunit --filter=AnalyticsTest` → `EXIT=1`, `UNANCHORED --filter`. The skip fires whenever the segment resolves to a package manager and does not literally contain `phpunit`/`artisan test`, so any wrapper script that runs PHPUnit reintroduces substring shadowing invisibly. No such script exists today (`composer test` is correctly scanned — re-verified), which is why this is P3 and not higher. Fourth occurrence of the root cause §(62) records honestly — this time as a false negative rather than a false positive.

### H-5 — P3 — CONFIRMED — pasted transcripts in the decision doc have drifted from the shipped output
`docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1104-1112` · `:1146`

§(46) pastes `against 1707 test classes` and the older debt wording *"run in NO CI lane on any event"*; the shipped checker now prints `1708` (the liveness test class added since) and the corrected wording *"sit in groups that NO CI lane runs as a whole… Some are individually named in a --filter allowlist"* — the precision fix round 3 made specifically because the old sentence was false. Harmless as a dated transcript, but M4 re-runs the accumulated evidence into the final handback; regenerate rather than carry these bytes forward.

---

## Bypasses and hypotheses that FAILED (no defect)

1. **Narrower path on a whole-directory lane** (`tests/Feature/Security/ModuleAccessControlTest.php`) → `EXIT=1`, names the offending token. G-1 closed (red-first: `EXIT=0` pre-fix).
2. **`|| true`** and **`; exit 0`** on a lane → `EXIT=1`, two independent errors (allowlist + shell-soft) plus the `runs_on_pr_dev` mismatch. G-2 closed (red-first: `EXIT=0` pre-fix).
3. **`--list-tests`** appended → `EXIT=1`. Closed.
4. **Mass relabel of all 71 `deferred` groups to `excluded`** → `EXIT=0` but the debt is now printed in its own `⚠ EXCLUDED: 71 group(s) / 1114 class(es)` block. G-3 closed (red-first: the block vanished entirely pre-fix).
5. **`security-regression` removed from `all-checks-pass` `needs`** → `EXIT=1`, `JOB … missing from the all-checks-pass needs list`. G-6 closed for the declared-job case (red-first: `EXIT=0` pre-fix).
6. **`if: always()` on the lane step** → `EXIT=0` (was a hard PR-blocking false positive pre-fix). **`env CI=1 pnpm --filter @autoerp/web build`** → `EXIT=0` (was `EXIT=1` pre-fix). G-5/G-7 closed without losing the genuine detections (an unanchored plain-phpunit filter still fires).
7. **Value-flag swallow** (`--log-junit --list-tests`) → `EXIT=0`, but harmless: PHPUnit consumes `--list-tests` as the junit filename and still runs the directory. Not a defect.
8. **Are the ceilings padded?** No — all 71 equal the current counts exactly; and `deferred` totals reconcile to 1114 independently of the checker.
9. **Does anchoring drop coverage?** No — 112/112 and 16/16, identical sets, independently derived (see standing checks).
10. **Does the new ungated job actually pass?** Yes — 93 tests green under the pinned sqlite/array/sync env, no PG or Redis service required.
11. **Scope creep.** None. No control file touched over the full range (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*` — all verified absent from `--name-only`). No production application code, no migration, no queue, no `app()`. `scripts/preflight.sh` (+8 lines) mirrors the two new CI steps for local parity — in scope. `ci.yml` parses as valid YAML (16 jobs).

---

**Disposition.** This round's work is the strongest of the five: the denylist→allowlist inversion is the right structural shape, the `excluded` debt door is shut, both round-4 false positives are gone without losing detection, the G-4 tally defect is corrected *and* its process cause addressed, and I could not break the PR→dev guarantee through any of the shapes that worked in rounds 3 and 4. The tenancy-authz substance is a genuine net win, verified in code and by running the suite, and the anchoring is provably coverage-neutral. What holds the gate is one door, not a family: the neutral-flag allowlist blesses `-c`/`--configuration`, and I demonstrated end-to-end that a lane carrying it runs **zero tests and exits 0** while the manifest still certifies 17 classes on PR→dev — the same consequence, through the one flag that is not neutral, in a package whose whole subject is guards that actually gate. The remedy is a one-line deletion (or, better, a nonzero-selection assertion that closes the family empirically). H-2/H-3/H-4 are contained residuals; H-3 additionally needs one sentence in the M3 checklist so P3-M2 does not assume protection that is not there. Fix rounds after this: 5 of 5 — the last one.

VERDICT: CHANGES-REQUIRED
