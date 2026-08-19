# Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 7

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD` (HEAD `67ff614bf`). M0/M1 (through `eb431c3b2`) were gated in their own rounds; the M2 surface is `d215569a4..HEAD`, round-7 delta = `fd2bf2948` (the parent-authorized N-1/N-2/N-3/N-6 continuation) + `67ff614bf` (bookkeeping). Working tree verified clean before and after (`git status --porcelain` empty, HEAD unchanged). Every experiment ran in `/tmp` sandboxes (checker copy, symlink-mirrored api root, copied `ci.yml`); **nothing in the repository was modified**, and all sandboxes were removed.

**Amending authority.** My dispatch says `none`. The "final authorized continuation" ruling I am being asked to verify against exists only as a **candidate-editable comment block** in `docs/handoff/progress/enforcement-p2.progress.yaml:228-247` (+ `max_fix_rounds_override: 7`) — the same shape this wave elsewhere treats as a forgery class. I proceeded because the dispatch itself says "round 7", but I applied the ruling's own carve-out literally: same-self-application-family findings are residuals; **"a false claim in evidence, a coverage regression" of a different family still blocks.** Finding 1 is exactly that.

**Domain lenses.** `frontend-conventions`: **does not apply to M2** — the M2 delta contains **zero** `apps/web` paths (`git diff --name-only eb431c3b2..HEAD | grep -c '^apps/web/'` → 0). `tenancy-authz`: **applies**, and is unchanged this round — `ci.yml`'s only round-7 edit is a one-word comment (`:196`); `security-regression` still carries no `if:` (`ci.yml:357-360`) and is still in `all-checks-pass` `needs` (`:1249`). No production permission/tenancy/module-gating code is touched anywhere in the range.

**Standing checks.** Rule 19: N/A (no money/quantity path). Migrations / named queues: none. Constructor injection: N/A — standalone CLI script + a bare `PHPUnit\Framework\TestCase`; `grep -n "app(\|env("` on the checker → no hits. i18n en+fr: no user-facing strings (CI output only). Red-first: **verified non-vacuous by re-running the round-7 tampers against the round-5 checker** (`990e3ae26` copy) — trigger-set tamper `EXIT=0`, self-soften tamper `EXIT=0`; against HEAD both `EXIT=1`.

**The four authorized items — all CONFIRMED CLOSED (verified by execution, not from the report):**
- **N-1** `feature-lane-manifest-check.php:552-576`. `branches: [main, dev] → [main]` → `EXIT=1 TRIGGER SET`; deleting the `pull_request:` trigger entirely → `EXIT=1` (`= NULL`, fails closed).
- **N-2** `:597-635` + `gatingDefects()` `:50-98`. Six tampers, each `EXIT=1` with a `SELF-CHECK:` message: job `if:`, job `continue-on-error`, `needs: [backend-test]`, step `continue-on-error`, step `if:`, deleted liveness step.
- **N-3** `FeatureLaneManifestCheckerTest.php:618-643`. Verified **behaviourally**, not by reading: in a symlink-mirrored api root with its own `ci.yml`, the baseline passes (`OK, 10 assertions`), and appending `--group zzz-nonexistent` **to the workflow's run line** (not the manifest) fails it — `lane security-regression selects ZERO tests … Run line: ./vendor/bin/phpunit tests/Feature/Security --group zzz-nonexistent`. The claim in §(63)/§(72) is now true.
- **N-6** `ci.yml:196` is count-free; decision doc `:1144` reads 1 708.

Liveness suite at HEAD: **42/42, 112 assertions, 7.2 s**. Checker on the real tree: `EXIT=0`, 1329 classes / 74 groups / debt 1114.

---

## Findings

### 1 — P1 — CONFIRMED — **DIFFERENT FAMILY, BLOCKING:** this milestone turns `backend-lint` red on every event; the two files it ships are the only Pint failures in `apps/api`
`apps/api/tools/feature-lane-manifest-check.php:60` (and throughout) · `apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php:35` (and throughout) · `.github/workflows/ci.yml:57-58`

CI runs `./vendor/bin/pint --test` in `apps/api` (`ci.yml:58`), in the ungated `backend-lint` job that is in the `all-checks-pass` `needs` list (`:1249`).

Measured, both directions, with the lock-pinned Pint (`Pint 1.29.0` == `composer.lock` `laravel/pint v1.29.0`), no `pint.json` anywhere so CI and I resolve the identical default preset:
- **Base tree** (`git archive c97e0d1ad`, vendor symlinked): `./vendor/bin/pint --test` → **`{"result":"pass"}`, EXIT=0**.
- **HEAD**: **EXIT=1**, and the failure list is *exactly* `tools/feature-lane-manifest-check.php` (`single_quote`, `fully_qualified_strict_types`, `concat_space`, `unary_operator_spaces`, `not_operator_with_successor_space`) and `tests/Architecture/FeatureLaneManifestCheckerTest.php` (`+ php_unit_method_casing`). Nothing else in `apps/api` fails.
- Present since the **first** M2 commit `d215569a4`, and at `990e3ae26` and `fd2bf2948` — six review rounds, mine included until now, missed it.

**Failure scenario (not hypothetical — it is the current state of the branch):** the first PR opened from this branch to `dev` shows `backend-lint` ✗. A package whose entire thesis is "guards must actually gate" ships an ungated, aggregate-member CI job red — and the obvious remediation for a red ungated job is the one this milestone's own B3 comment (`:597-603`) names as the threat model. It also violates CLAUDE.md rule 10 (`./scripts/preflight.sh` before commit), in a wave that edited `scripts/preflight.sh` itself. Fix: `./vendor/bin/pint` on the two files (mechanical; I ran it against copies in `/tmp` — the diff is whitespace/quote/`use`-import only, no logic).

This is a **CI regression**, not a self-application residual, so the recorded terminal condition does not cover it.

### 2 — P2 — CONFIRMED — self-application implements **five** doors; the lane path enforces **six**. The shell-soft door is open on the checker's own steps
`apps/api/tools/feature-lane-manifest-check.php:50-98` (`gatingDefects`, five analyses) vs `:490-506` (the lane path's sixth: `||`, `;`, `|`, `set +e`, `&&`)

**Two bypasses I ran, both `EXIT=0` / `lane manifest OK`:**
1. `run: php tools/feature-lane-manifest-check.php || true`
2. `run: ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php; exit 0`

Both steps still "exist", still resolve to `backend-architecture`, still carry no `if:` and no `continue-on-error` — and neither can fail the build any more. Identical consequence to the softening N-2 closed, through the door the lane path already knows about and `gatingDefects()` does not. Same family as N-2 ⇒ a named residual under the ruling — but a fix round is required anyway (finding 1), and this is ~5 lines reusing the loop at `:491-503`.

### 3 — P2 — CONFIRMED — the trigger-set check reads `branches` only; two other one-line edits still stop the workflow starting
`apps/api/tools/feature-lane-manifest-check.php:565-576`

**Two bypasses I ran, both `EXIT=0`:**
1. `on.pull_request.paths-ignore: ['**']` — the workflow starts on **no** PR at all.
2. `on.pull_request.types: [labeled]` — it no longer starts on `opened`/`synchronize`, i.e. never on an ordinary PR→dev.

Every lane still certifies `runs_on_pr_dev: true`. Same family as N-1 ⇒ residual under the ruling; `paths-ignore` in particular is a two-line assertion next to the existing one.

### 4 — P2 — CONFIRMED — the N-6 fix retro-edited two *historical findings*, leaving self-contradictory statements inside the acceptance evidence
`docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1578`, `:1647`

Line `:1144` was the correct target and was fixed. But the same edit rewrote the recorded round-5 finding — §(67) now reads *"§(46) still showed `1 708 test classes` … that was corrected precisely **because it was false**"* (1 708 is the true number) — and the round-6 register bullet now reads *"one prose line still says '1 708 classes' (shipped output prints 1708)"*, i.e. a recorded drift finding whose two halves are now identical. The audit trail of what H-5/N-6 actually were is destroyed, and M4 carries these bytes into the handback. Restore both to the historical values (`1707` / `1 707`) and leave the correction confined to `:1144`.

### 5 — P3 — CONFIRMED — "the five analyses in one place" is a duplication, not a unification
`:50-98` vs `:466-529`. `gatingDefects()` is called only from B3 (`:632`); the lane path keeps its own inline copies (richer messages). The two have **already diverged** — that divergence is finding 2. Whichever fix round lands, either call it from both sites or say plainly in the doc that it is a self-application copy.

### 6 — P3 — CONFIRMED — the N-3 companion guard is lexical and whitespace-fragile
`FeatureLaneManifestCheckerTest.php:761-772`. It scans a fixed **2600-char window** of its own source and forbids the literal needle `' && ' . $selector`; reformatting to `.$selector.` (which is exactly what Pint's `concat_space` fixer does — see finding 1) or growing the method past the window defeats it silently. Its docblock is honest that it is lexical and names the behavioural proof, so this is a note — but the interaction with finding 1's fixer is worth checking when Pint is run.

### 7 — P3 — carried forward unchanged — N-4 (allowlists relocated outside `ci.yml` leave the anchoring lint's field of view) and N-5 (one more `<groups><exclude>` in the *default* `phpunit.xml` partially guts a certified lane). Both are correctly recorded as named open residuals in §(73) with the S-14 mitigation. No action this round; the M3 checklist line for P3-M2 (N-4) is still owed.

---

## Bypasses and hypotheses that FAILED (no defect)

1. `branches: [main, dev] → [main]` → `EXIT=1 TRIGGER SET`. **Closed.**
2. Delete the `pull_request:` trigger entirely → `EXIT=1` (`= NULL`) — fails closed on absence, not just on the wrong list.
3. Job `if: github.base_ref == 'main'` on `backend-architecture` → `EXIT=1 SELF-CHECK`.
4. Job `continue-on-error: true` → `EXIT=1 SELF-CHECK`.
5. `needs: [backend-test]` (transitively gated) → `EXIT=1 SELF-CHECK`, names `backend-test`.
6. Step `continue-on-error: true` on the checker step → `EXIT=1 SELF-CHECK`.
7. Step `if:` on the checker step → `EXIT=1 SELF-CHECK`.
8. Delete/replace the liveness step's `run:` → `EXIT=1 SELF-CHECK` ("not present in any live `run:` block").
9. Emptying the lane from the **workflow** (`--group zzz-nonexistent`) → the selection assertion FAILS with the resolved run line quoted. The empirical backstop is real.
10. Red-first non-vacuity: all of the above tampers against the **round-5** checker → `EXIT=0`. The new cases fail before the fix and pass after it.
11. Scope creep: none. M2 delta = `ci.yml` (1 comment line), the checker, its test, the manifest, the decision doc, the progress YAML, the review registers, `scripts/preflight.sh` (local parity). No control file (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`), no production code, no migration, no queue.
12. Coverage regression in the manifest: none — `feature-lane-manifest.json` is byte-identical since round 5; checker output still 1329/74/1114 with `debt_ceiling: 1114`.

---

**Disposition.** The four authorized items are genuinely closed, and N-3 is closed in the strong sense — I broke it from the workflow side and the assertion caught it, which is the first time in this milestone that a fix replaced a lexical claim with an observed one. Findings 2 and 3 are the predicted same-family residuals and, per the recorded ruling, would not have blocked on their own.

What blocks is finding 1, and it is not in that family: **the milestone's own two files are the only Pint failures in `apps/api`, so `backend-lint` — ungated, on PR→dev, in `all-checks-pass` — is red at HEAD and green at base.** That is a CI regression introduced by this milestone, in the exact register the package exists to police, and the ruling's terminal condition explicitly excludes it. The fix is mechanical (`./vendor/bin/pint` on two files, whitespace/quotes/one `use` import). Since a fix round is unavoidable, findings 2, 3 and 4 are cheap to fold into it rather than shipping as residuals — and finding 6 says to re-run the liveness suite *after* Pint touches the test file, because the formatter and that guard's literal needle interact.

VERDICT: CHANGES-REQUIRED
