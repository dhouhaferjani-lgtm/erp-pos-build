## Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 1

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD`. Commits `eb431c3b2` and earlier are M0/M1 (already gated, ACCEPT at round 7); the M2 delta is `d215569a4` + `4ee7ca1c5`, touching `.github/workflows/ci.yml`, `apps/api/tests/feature-lane-manifest.json`, `apps/api/tools/feature-lane-manifest-check.php`, `docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md`, `docs/handoff/reviews/enforcement-p2/M1-round7.md`, `docs/handoff/progress/enforcement-p2.progress.yaml`.

**Domain lenses.** `frontend-conventions`: **does not apply** — the M2 delta contains no `apps/web` change (the one production FE change in the range, `apps/web/src/features/treasury/statements/api.ts`, is M1 and was gated there). `tenancy-authz`: **applies and was exercised** — the delta rewrites the test selection of the two PHPUnit lanes that carry the tenancy/authz suites (`t6-phase0b-pgsql` → `TenantDatabaseIsolationTest`, `TenancyResolverFailClosedTest`, `CrossDbForeignKeyAuditTest`, `CentralConnectionTest`; `backend-test-pgsql` → `SupportAccessPostgresEndToEndTest`, `TerminalResourcePolicyTest`, `AccountantReceiptPermissionsTest`, `ReceiptLocationScopeAuthorizationTest`, `ReceiptAuthorizationTest`). See "verified clean" below.

**Standing checks.** Rule 19 (money/quantity precision): not applicable — no money/quantity code path in the delta. Migrations / named queues: none added. Constructor injection: not applicable (standalone CLI script, no container). i18n en+fr: no user-facing strings added. Red-first evidence: see F-5.

---

### F-1 — P1 — CONFIRMED — the milestone's own encoded STOP fired and was overridden by the executor
`docs/handoff/progress/enforcement-p2.progress.yaml:198` (M2 title) · `:200` (`status: review`) · `:138-141` (F-2 record) · `docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1067-1075`

The parent-authored M2 title states the operative rule verbatim: *"LOCAL timing measurement only … **if material -> F-2 fires (blocked_owner)**"*. The `owner_gates` F-2 entry repeats it: *"Fires ONLY if the measurement is material: then the A-vs-B choice is the owner's with the number in front of them — **STOP blocked_owner**"* (`:139`). The brief is identical at `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:324` and `:481`, and STOP condition (B) at `:159` says *"set YAML `status` + `blockers` and end the run"*.

The measurement fired by the executor's own words — decision doc `:1043`: *"**~2 hours per CI run is material by any reading**"*. The executor nonetheless (a) made the A-vs-B choice itself (`:1047` *"Option A is rejected on the measured number"*), and (b) set M2 `status: review` rather than `blocked_owner`, justifying it at `:1069` on the `blocks_milestone: none` field.

That justification does not hold: `blocks_milestone: none` is present on `merge-announcement` (`:128`) and `pre-promotion-ci-dispatch` (`:132`) too, whose question text says *"promotion blocked"* — the field means "no unconditional milestone `owner_gate:` field", and the YAML header comment at `:120-122` says so outright: *"conditional logic lives in the milestone text"*. The milestone text is the authority, and it names `blocked_owner`.

**Failure scenario:** M2 is ACCEPTed and the wave advances to M3/M4 with the owner never having ruled on A-vs-B. P3-M2 must then wire its country-chart completeness test "into the lane 2(b) selects" (brief `:328`) — but the lane 2(b) actually selected for the 71 deferred groups is *none*, pending a ruling that was recorded as already made. The `p2_landed_sha` precondition (`:47`) then certifies a whole-package "complete" whose central decision was executor-taken.

*Note:* this is a status/authority defect, not a code defect. The shipped artifacts are usable as-is under whichever option the owner rules; the fix is the milestone state plus routing the A-vs-B question to the owner, not a rewrite.

### F-2 — P2 — CONFIRMED — the anchoring/uniqueness lint is blind to any `--filter` that is not double- or single-quoted
`apps/api/tools/feature-lane-manifest-check.php:187`

`preg_match_all('/--filter=(["\'])(.*?)\1/s', …)` only sees a **quoted `--filter=`**. The brief's requirement (`:326`) is *"if any `--filter` survives, anchor it … and add a CI-side lint … asserting every allowlist entry matches exactly one test class"* — i.e. the lint must own the mechanism, not today's two strings.

**Bypasses I ran (both PASSED the checker — the bypass succeeded):** appending `run: php artisan test --filter=AnalyticsTest|Foo` (unquoted) → `EXIT=0`; appending `run: php artisan test --filter "AnalyticsTest|Foo"` (space form, which PHPUnit and `php artisan test` both accept) → `EXIT=0`. **Failure scenario:** the next lane author adds an unquoted or space-form allowlist, the substring-shadowing mechanism this milestone exists to kill returns in full, and the guard reports OK. Fix: match `--filter` in all its argument forms (or fail closed on any `--filter` occurrence the parser cannot resolve).

### F-3 — P2 — CONFIRMED — "a lane cannot be a fiction" is defeated by a comment
`apps/api/tools/feature-lane-manifest-check.php:171` (`str_contains($workflow, $selector)`)

Lane reality is a raw substring test over the whole `ci.yml` text. **Bypass I ran (PASSED — bypass succeeded):** replaced the live step `./vendor/bin/phpunit tests/Feature/Treasury` with `# ./vendor/bin/phpunit tests/Feature/Treasury  (disabled)` → `EXIT=0`, manifest still asserts 119 Treasury classes are lane-covered and they stay out of the COVERAGE DEBT total. **Failure scenario:** a future PR comments out or `if:`-gates a whole-directory step; the manifest keeps certifying coverage that no longer exists, and the one metric designed to make holes loud under-reports by 215 classes. Fix: resolve the selector to a live `run:` block (parse the YAML) rather than to file text.

### F-4 — P2 — CONFIRMED — the debt can grow silently, and the brief's named negative proof was demonstrated in its weak form
`apps/api/tools/feature-lane-manifest-check.php:115-153`, `:271-281` · `apps/api/tests/feature-lane-manifest.json:1` (*"`classes` is documentation only; the checker never trusts it"*) · decision doc `:1105-1113`

The milestone text requires *"the planted-new-Feature-class negative proof **in a previously-UNCOVERED directory** (selected automatically or loud failure)"*. The pasted proof plants `tests/Feature/ZzzPlantedLaneProof/` — a directory that **did not previously exist**, which is the easy half of the property. I ran the other half against a mirror of the tree: planting `tests/Feature/Admin/ZzzBrandNewSilentTest.php` (an existing, uncovered, `deferred` group) → **`EXIT=0`**, no failure, debt silently 1114 → 1115. So a new class in any of the 71 uncovered directories is *neither* selected *nor* loudly failed — the exact silence the milestone exists to end, one level down.

**Failure scenario:** the 990-class hole grows by hand indefinitely; every CI run stays green and the only signal is a stdout warning whose number drifts upward unnoticed. **Concrete cheap fix:** the manifest already carries per-group `classes` counts (`feature-lane-manifest.json:44,52,…`) and the checker deliberately ignores them. Enforce them as a non-growth ceiling for `deferred`/`excluded` groups — that turns a new class in `Admin` into a loud failure, makes the strict negative proof pass, and adds no new machinery.

### F-5 — P2 — CONFIRMED — the new detector ships with no automated liveness test, breaking the convention this same package landed at M1
`apps/api/tools/feature-lane-manifest-check.php` (whole file) · `docs/conventions/08-DETECTOR-LIVENESS.md:3-5`, `:100-107`

`grep -rn "feature-lane-manifest"` across the repo returns only `ci.yml:182,191` and the script itself — **no test anywhere**. The convention landed by M1 in this package reads: *"Every detector, ratchet, audit script, and lint rule ships with at least one test that proves it FIRES on a planted violation — and that test runs in the SAME CI lane as the detector itself"*, with the checklist item *"the guard has at least one test that FAILS if the guard is neutered"*. The M2 checker is the only guard in the package whose liveness rests on a manual, reverted, prose-pasted demonstration.

**Failure scenario:** the C6 incident the convention doc cites, replayed — someone edits the `--filter=(["\'])` pattern at `:187` or the `str_starts_with('/\\\\(')` test at `:195`, the checker silently stops seeing the allowlists, and it reports OK forever. (The `Shell/CI drift check` row of the table at `:60` can be read as accepting a recorded red/green, so this is P2 rather than P1 — but the headline rule, the checklist, and the C6 lesson all point the other way, and the fix is a ~30-line PHPUnit test driving fixture copies.)

### F-6 — P3 — CONFIRMED — the COVERAGE DEBT line overstates the hole
`apps/api/tools/feature-lane-manifest-check.php:275-280`

The printed claim is *"1114 class(es) run in **NO CI lane on any event**"*. That is false for roughly 111 of them: the anchored allowlists at `ci.yml:653` / `:750` select 112 and 16 classes, most of which live in `deferred` groups. The decision doc keeps the two figures distinct (`:1023-1029`: 990 reachable by no job vs 1114 living in laneless groups); the permanent CI output conflates them. Errs toward alarm, not concealment — but it is a false statement in a guard's own output.

### F-7 — P3 — CONFIRMED — the manifest's `events:` claims are unverified
`apps/api/tests/feature-lane-manifest.json:11-33` · checker `:164-178`

Each lane records an `events:` string ("PR->dev, PR->main, …"). The checker verifies only that the selector text exists; nothing compares the claim against the job's `if:` (`ci.yml:772` for `treasury-spine-pgsql`, `:205` for `backend-test`). Narrowing a job's `if:` to `main` leaves the manifest asserting PR→dev coverage with the check green.

### F-8 — P3 — CONFIRMED — tenancy/authz consequence of the honest classification, not surfaced in the metric
`apps/api/tests/feature-lane-manifest.json:12-17`

`tests/Feature/Security` — the module-gating + kill-switch regression suite, i.e. the authz surface CLAUDE.md rule 12 leans on — carries disposition `lane` and is therefore **excluded from the COVERAGE DEBT total**, even though its `events` field correctly records *"job is `if:`-gated; skipped on PR->dev"*. On the day-to-day merge gate (PR→dev) those 17 classes run nowhere. The record is truthful; the headline number hides it. Turning it on is exactly the F-2 purchase, so this belongs in the owner packet of F-1 rather than being fixed here.

### F-9 — P3 — CONFIRMED — not reproducible in local preflight
`scripts/preflight.sh` — `grep` for `feature-lane` returns nothing.

The convention doc's own line (`08-DETECTOR-LIVENESS.md:110`) is *"A CI gate a developer cannot reproduce locally is a gate that gets disabled."* A developer adding `tests/Feature/NewThing/` discovers the hard failure only in CI. (Its neighbour `deptrac-ratchet.php` is equally absent, so this is consistent with the job, not a regression.)

### F-10 — P3 — PLAUSIBLE — an un-namespaced test class in an allowlist would select nothing, silently
checker `:214-218` (uniqueness by basename only)

The anchored form requires a `\` immediately before the class name. I verified **0** of the 1707 current test classes lack a namespace, so nothing is affected today; and a mis-namespaced test file would fail autoloading first. Recorded for completeness: the uniqueness lint would still report such an entry as healthy while it matched zero tests.

---

### Verified clean (claims I re-derived rather than accepted)

1. **Anchoring is coverage-neutral — CONFIRMED independently.** I reconstructed all 1707 test FQCNs from the tree and replayed both filters under PHPUnit's exact semantics (`vendor/phpunit/phpunit/src/Runner/Filter/NameFilterIterator.php:65` — `$name = $test::class . '::' . $test->nameWithDataSet()`, i.e. the **fully-namespaced** name, and `:88-128` — the pre-change unquoted filters were wrapped as `/…/i` case-insensitively, the new delimited ones are used as-is). Result: list 1 `112 → 112`, list 2 `16 → 16`, **0 dropped, 0 added**, including under the old case-insensitive semantics. **Tenancy-authz lens: no tenancy, authz, permission, support-access or location-scope test is dropped from either PG lane by this change.**
2. **Substring shadowing is genuinely killed** for the two surviving lists: `AnalyticsTest` no longer drags in `ExpenseAnalyticsTest` (the anchor requires a namespace boundary).
3. **CI wiring claims are accurate.** `backend-architecture` (`ci.yml:143`) has **no `if:`**, sets `working-directory: apps/api` (`:146-148`) so the relative script path and the script's `dirname($apiRoot, 2)` repo-root derivation both resolve, and it **is** in `all-checks-pass` `needs` (`:1172`) — no aggregate edit needed, as claimed.
4. **The checker runs green on the real tree** and its report is reproducible: `1329 Feature classes in 74 groups`, `1707 test classes`, `EXIT=0`, debt `71 groups / 1114 classes`. The `1316` in the `ci.yml:177` comment is distinct-basename count (326 + 990), consistent — not an error.
5. **Ambiguity and dead-entry detection are real, not vacuous** — planting a second `AnalyticsTest.php` under `tests/Unit/` produced `AMBIGUOUS --filter entry`, `EXIT=1`; a regex-bearing entry (`Voucher[A-Za-z]*Test`) produced `DEAD --filter entry`, `EXIT=1`.
6. **Scope discipline holds.** The M2 delta touches no control file (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, the control manifest, `.claude/agents/*-reviewer.md`) and no production application code.
7. **The `deferred` deviation is disclosed, not smuggled** (decision doc `:1085-1096`, commit body, manifest `:3-8`), and the reasoning — that labelling 990 runnable classes "genuinely cannot run" would be a false permanent record — is sound. I do not contest it.

---

**Disposition.** The engineering shipped here is strong and the census work is honest — the anchoring is correct and provably coverage-neutral, and the manifest closes the new-directory hole. What blocks the gate is F-1: a machine-encoded owner STOP fired by the executor's own measurement and the milestone was advanced to `review` anyway, with the owner-reserved A-vs-B choice taken by the executor. F-2 through F-5 are fix-before-merge defects in the guard's own invariants — three of them I bypassed successfully against the shipped script.

VERDICT: CHANGES-REQUIRED
