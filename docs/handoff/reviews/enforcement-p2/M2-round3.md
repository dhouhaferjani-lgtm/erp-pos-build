# Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 3

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD`. M0/M1 (through `eb431c3b2`) and the M2 round-1/round-2 states were gated in their own rounds; the round-3 delta is `ef8db151b` (M2 fix round 2) + `60626fff4` (bookkeeping), touching only `apps/api/tools/feature-lane-manifest-check.php`, `apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php`, the decision doc and `enforcement-p2.progress.yaml`. Working tree verified clean (`git status --porcelain` empty); every experiment ran against `/tmp` sandbox mirrors, now removed. **Nothing in the repo was modified.**

**Domain lenses.** `frontend-conventions`: **does not apply** — the round-3 delta contains zero `apps/web` change (no `.tsx`, no ESLint rule, no i18n string; the only FE file in the whole range, `features/treasury/statements/api.ts`, is M1 and was gated there). `tenancy-authz`: **applies and is the substantive surface** — the round-2 fixes exist to keep `tests/Feature/Security` (module-gating, per-module access control, marketplace kill-switches, rate-limit enforcement — the CLAUDE.md rule-12 surface) provably on the PR→dev merge gate. That guarantee is where three of my confirmed bypasses land.

**Standing checks.** Rule 19: N/A — no money/quantity path in the delta. Migrations / named queues: none. Constructor injection: N/A (standalone CLI script + a bare `PHPUnit\Framework\TestCase`; no container, no `app()`). i18n en+fr: no user-facing strings. **Red-first: VERIFIED, not accepted from the report** — I extracted the pre-fix checker (`git show 87d00b3e5:…`) into a second sandbox and replayed each new liveness case's mutation against it: N-1 step-`if:` → `EXIT=0`, N-4 `needs:` → `EXIT=0`, N-3 mass relabel → `EXIT=0`, N-2 pnpm → `EXIT=1` (the false positive). All four invert against the shipped checker, so all five new cases are non-vacuous. Suite runs green: **14/14, 46 assertions, 2.5 s**.

---

## Round-2 findings — disposition

**N-1, N-2, N-3, N-4 — fixed at the level tested, each re-verified by me.** Step-`if:` folds into `runsOnPrDev` (`feature-lane-manifest-check.php:318-330`); `--filter` scan scoped to `phpunit`/`artisan test` scripts (`:376-397`); a `lane` disposition must name a whole-directory selector ending in the group (`:201-225`); one-hop `needs:` on a gated job fires (`:332-342`, control run: `EXIT=1`, correct message). **N-5, N-6 — recorded as not-reachable-today; I agree.**

**N-7, N-8, N-9, N-10 — SILENTLY DROPPED.** See R-7 below.

---

## New findings

### R-1 — P2 — CONFIRMED — `continue-on-error` neuters the PR→dev Security gate; the checker still certifies it
`apps/api/tools/feature-lane-manifest-check.php:314-342` · `.github/workflows/ci.yml:357`, `:420-421`

The round-2 fix reads step `if:` and job `needs:`. It reads neither step nor job `continue-on-error`. **Bypasses I ran (both PASSED — bypass succeeded):**
- `continue-on-error: true` on the step `Security regression suite (module gating + kill-switches)` → `EXIT=0`, `lane manifest OK`, `runs_on_pr_dev: true` still certified.
- `continue-on-error: true` on the `security-regression` **job** → `EXIT=0`, same.

**Failure scenario:** the Security suite goes red on an unrelated PR; someone quiets it with two words that read as *less* alarming than an `if:` — the step still executes and still shows green-with-warning, but its failures can no longer block PR→dev. The 17 module-gating/kill-switch classes stop being a gate, the manifest keeps asserting they are one, and all 14 liveness cases stay green. This is exactly N-1's family (the parent's 2026-08-19 ruling installed this guarantee one commit earlier), through a third door the round-2 predicate does not look at. **Fix:** fold `continue-on-error` on the lane's step and on its job into `runsOnPrDev` — one more clause in the same predicate.

### R-2 — P2 — CONFIRMED — the N-2 scoping regressed invariant D for the repo's own documented test command
`apps/api/tools/feature-lane-manifest-check.php:383-385`

The scan now skips any `run:` script that does not match `/\bphpunit\b|\bartisan\s+test\b/`. `apps/api/composer.json` defines `"test": ["@php artisan config:clear", "@php artisan test"]`, and CLAUDE.md's Quality Gates block prescribes `cd apps/api && composer test`. That string contains neither token. **Bypass I ran (PASSED — bypass succeeded):** added a step `run: composer test -- --filter=AnalyticsTest|ZzzNotARealClass` → `EXIT=0`, `every --filter entry is anchored and uniquely matched`. Both defects the milestone exists to kill are present in that one line — an **unanchored** allowlist (invariant D) and a **dead entry** (invariant C) — and the guard reports OK.

**Failure scenario:** the next lane author follows CLAUDE.md, writes `composer test -- --filter=…`, and the substring-shadowing mechanism this milestone was dispatched to kill is back in full with the lint blind to it. Round 1 F-2 was the same defect at P2; the N-2 fix re-opened it for a different invocation form. **Fix:** add `composer\s+(run\s+)?test\b` (and any other repo-defined test alias) to the scope regex, or invert the rule — scan every script, and skip only the specific `pnpm`/`npm`/`yarn`/`turbo` invocation (which is what R-3 shows the line heuristic already tries and gets wrong).

### R-3 — P2 — CONFIRMED — a whole-directory lane can be narrowed to one class with the manifest still certifying the directory
`apps/api/tools/feature-lane-manifest-check.php:277` (`str_contains($run, $selector)`) · `:208-213` (coverage regex runs against the **manifest** string, not the live command)

The N-3 fix asserts the *manifest's* `selector` ends in `tests/Feature/<Group>`. Lane reality is still a `str_contains` of that selector **inside** the live `run:` script — so anything appended to the real command is invisible. **Bypass I ran (PASSED — bypass succeeded):** changed the live step to `./vendor/bin/phpunit tests/Feature/Treasury --filter='/\\(AcquirerFeeServiceTest)::/'` → `EXIT=0`, `lane manifest OK`, `Treasury` stays `lane`-dispositioned and **out of the COVERAGE DEBT total**, while the lane now runs **1 of 119** Treasury classes. The appended filter is even anchored and unique, so invariants C/D pass it happily. `--group` / `--exclude-group` / `--testsuite` behave the same.

**Failure scenario:** N-3 verbatim, one level down — *"the lane was real; it just did not run the group."* The 1114-class debt figure and the `lane` dispositions become defeatable by appending arguments, and the honest census this milestone exists to produce silently overstates coverage by up to 215 classes. **Fix:** compare the **live** command against the selector — require the resolved run line to be the selector plus only whitelisted flags, or fail closed on any `--filter`/`--group`/`--exclude-group`/`--testsuite` appearing on a whole-directory lane's own run line.

### R-4 — P2 — CONFIRMED — the round-2 response mis-tallies the register and drops four findings without disposition
`docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1301-1302`, `:1358-1367`

The response opens *"CHANGES-REQUIRED — 0 P1, 3 P2, 3 P3"*. The round-2 register carries **3 P2 and 7 P3** (`grep -c "— P3 —" M2-round2.md` → 7). Section (55) dispositions N-4/N-5/N-6 and stops. **N-7, N-8, N-9 and N-10 are never mentioned anywhere in the delta** — not fixed, not recorded, not routed. I verified each is still live:
- **N-7:** `apps/api/tests/feature-lane-manifest.json:1` still reads *"`classes` is documentation only; the checker never trusts it"*, and `feature-lane-manifest-check.php:12` still reads *"`tests/Feature/Security` (backend-test)"*. Both are false. I ran the consequence: deleting `classes` from the `Admin` group per the header's own instruction → **`EXIT=1`, `GROUP "Admin" is deferred and must carry an integer classes ceiling`**. The file instructs a maintainer into a hard CI failure.
- **N-8:** `~43 s` still stands at decision doc `:1242`, `:1260`, `:1274` **and is now also baked into `ci.yml:377`** as the job's own justifying comment, unqualified.
- **N-9:** the M3 title (`enforcement-p2.progress.yaml:235`) still names only the OpenAPI line and the M1 `frontend-lint` change — neither the F-2 execution-scope owner line nor the `security-regression`-on-PR→dev CI-contract change. (Parent action, as round 2 said — but still open, and M3 is next.)
- **N-10:** `commit: ef8db151b` / `updated: ef8db151b` while HEAD is `60626fff4`; consistent with the M1 backfill pattern, so bookkeeping only.

**Failure scenario:** this package's entire subject is that guards and their records must not make false statements (round-1 F-6 was raised and fixed on exactly that principle). A fix round that under-reports its own register by four findings, and leaves two verifiably false statements inside the guard's own manifest header and docblock, undercuts the artifact the M4 whole-package gate and the P3-M0 `p2_landed_sha` precondition will certify as complete. **Fix:** correct the tally, disposition all four explicitly, and fix N-7's two sentences (a two-line edit).

### R-5 — P3 — CONFIRMED — the `needs:` predicate is one hop deep
`apps/api/tools/feature-lane-manifest-check.php:334-342`

**Bypass I ran (PASSED — bypass succeeded):** inserted an ungated `zzz-bridge` job with `needs: [backend-test]`, and set `security-regression: needs: [zzz-bridge]` → `EXIT=0`, `runs_on_pr_dev: true` certified. GitHub skips transitively, so the Security suite would not run on PR→dev. The one-hop form correctly fires (control run: `EXIT=1`). Lower severity than R-1 only because a two-job chain is a less natural edit. **Fix:** walk `needs` transitively (the graph is already collected at `:115-116`).

### R-6 — P3 — CONFIRMED — the pnpm line-skip swallows a genuine PHPUnit filter
`apps/api/tools/feature-lane-manifest-check.php:388-397`

Any `pnpm|npm|yarn|turbo` token *earlier on the same line* suppresses the `--filter` that follows it, regardless of what actually owns that filter. **Bypass I ran (PASSED — bypass succeeded):** `run: pnpm install --frozen-lockfile && ./vendor/bin/phpunit --filter=AnalyticsTest|ZzzNotARealClass` → `EXIT=0`. Unanchored **and** dead, invisible. Narrower than R-2, same root cause: a textual heuristic standing in for "which binary owns this flag".

### R-7 — P3 — CONFIRMED — a same-job step whose text merely mentions the selector shadows the real one
`apps/api/tools/feature-lane-manifest-check.php:276-281`, `:321-327` (both loops take the **first** `str_contains` match)

**Bypass I ran (PASSED — bypass succeeded):** added `run: echo 'runs ./vendor/bin/phpunit tests/Feature/Security below'` as an earlier step **inside** `security-regression`, then gated the real step with `if: github.base_ref == 'main'` → `EXIT=0`, `runs_on_pr_dev: true`. The N-1 fix reads the echo step's (empty) `if:`. The cross-job variant is caught by the `job` field (see failed bypasses), so this needs the shadow in the same job — contrived, but it is the exact seam N-1 was fixed at. **Fix:** resolve the lane to the step whose `run` *starts with* the selector, and fail on more than one candidate.

### R-8 — P3 — CONFIRMED — `if: always()` on a lane step produces a false, all-PR-blocking failure with a wrong message
`apps/api/tools/feature-lane-manifest-check.php:328-330`, `:345-347`

The rule is "ANY step `if:` means not certified". **Test I ran (FAILED CI — false positive):** `if: always()` on the Security step → `EXIT=1`, *"the lane STEP carries `if: always()`, **which skips it**"*. `always()` skips nothing; the step runs on every event. This hard-fails `backend-architecture` — a job with **no `if:` guard**, so it blocks every PR — with a statement that is factually false. That is the N-2 defect class (over-general fix → false positive blocking everyone) reintroduced by the N-1 fix, plus a false statement in a guard's own output (round-1 F-6's principle). Not reachable today (no lane step carries an `if:`), hence P3. **Fix:** allowlist the always-true forms (`always()`, `success()`, `!cancelled()`), or reword to "carries an `if:`, which this checker cannot prove is true on PR→dev".

### R-9 — P3 — CONFIRMED — aggregate membership is asserted only for one lane, and only by the test
`apps/api/tools/feature-lane-manifest-check.php` (no `all-checks-pass` logic) · `FeatureLaneManifestCheckerTest.php:233`

**Bypass I ran (PASSED at the checker):** removed `security-regression` from the `all-checks-pass` inline `needs` list (`ci.yml:1242`) → checker `EXIT=0`. The one PHPUnit case at `:233` does catch it, so the gate as a whole holds today — but only for `security-regression`. Neither the checker nor any test asserts that `backend-architecture` (the job carrying the checker itself) or `treasury-spine-pgsql` stay in the aggregate. Brief H-9 makes aggregate membership a package-wide obligation. **Fix:** move the membership assertion into the checker, keyed off each lane's declared `job`.

---

## Bypasses and hypotheses that FAILED (no defect)

1. **Lane fiction via a disabled step** — replacing the Security `run:` with `echo skipped` → `EXIT=1`, `LANE "security-regression" is a FICTION`. Round-1 F-3's fix holds.
2. **Cross-job selector shadow** — an `echo` mentioning the selector placed in `backend-architecture`, real step gated → `EXIT=1`, `claims job "security-regression" but its selector lives in job "backend-architecture"`. The `job` field is load-bearing and works.
3. **Removing the `classes` ceiling** — `EXIT=1`, `must carry an integer classes ceiling`. Round-1 F-4's fix holds (and is what makes R-4/N-7's header sentence harmful).
4. **Narrowing a lane with a *dead* filter** — `--filter='/\\(TreasuryTransferTest)::/'` (no such class) → `EXIT=1`, `DEAD --filter entry`. R-3 only works with a real class name; invariants C/D still bite on typos.
5. **One-hop `needs:` on a gated job** — fires correctly, with an accurate message.
6. **Vacuous new liveness cases** — disproved: all four round-2 mutations run green against the pre-fix checker and red against the shipped one (red-first re-derived, see Standing checks).
7. **Scope creep** — no control file touched (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, the control manifest, `.claude/agents/*`), no production application code, no `ci.yml` change at all this round, no migration, no queue.
8. **Tenancy/authz coverage regression this round** — none: `ci.yml` is byte-identical across `87d00b3e5..HEAD`, so the `security-regression` job, the two anchored PG allowlists and the tenancy/authz classes they select are unchanged from the round-2 accepted state.

---

**Disposition.** The round-2 fixes are real, correctly targeted, and red-first-verified — I independently re-derived that all four new liveness cases fail against the pre-fix script, and the suite is 14/14 green. What holds the gate is the same pattern round 2 itself named: **each fix closes the door it was shown, and the family stays open one step sideways.** `continue-on-error` re-neuters the PR→dev Security guarantee the parent ruled on (R-1); the scope narrowing that fixed the pnpm false positive blinded the anchoring lint to `composer test`, the repo's own documented command (R-2); and the "lane must cover the group" check validates the manifest string while the live command can append a `--filter` that cuts a 119-class lane to 1 (R-3). All three were bypassed against the shipped script, and each maps directly onto a named milestone invariant (the parent's F-2 sub-decision, invariants C/D, invariant A). R-4 is separate and cheap: the fix round under-reports its own register by four findings and leaves two verifiably false sentences inside the guard's manifest header and docblock — in a package whose thesis is that guards must not make false statements. Fix rounds after this: 3 of 5.

VERDICT: CHANGES-REQUIRED
