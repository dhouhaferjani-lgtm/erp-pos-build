# Adversarial merge-gate register — enforcement-p2 / milestone **p2-M2**, round 8

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD` (HEAD `e6f9ade4b`). M0/M1 (through `eb431c3b2`) were gated in their own rounds; the round-8 delta is `fd2bf2948..HEAD` = `127311ff3` (the round-7 fix round) + `e6f9ade4b` (bookkeeping) — 5 files: the checker, its liveness suite, the decision doc, the progress YAML, the round-7 register. Working tree verified clean before and after (`git status --porcelain` empty, HEAD unchanged). Every experiment ran in `/tmp` sandboxes (copied checker, symlink-mirrored api root with its own `ci.yml`, copied manifest); **nothing in the repository was modified**, and all sandboxes were removed.

**Amending authority.** My dispatch says `none`. As in round 7, the "final authorized continuation" ruling and its terminal condition exist only as a candidate-editable comment block in `docs/handoff/progress/enforcement-p2.progress.yaml:224-247` (+ `max_fix_rounds_override: 7`). I proceeded because the dispatch itself says "round 8", and I applied the carve-out literally: same-family (self-application / trigger-set) findings are named residuals; a **different-family** finding — a false claim in evidence, or a coverage/CI regression — still blocks. I hunted specifically for the latter and found none.

**Domain lenses.** `frontend-conventions`: **does not apply to this milestone** — the M2 surface contains zero `apps/web` paths (`git diff --name-only eb431c3b2..HEAD | grep '^apps/web/'` → none). `tenancy-authz`: **applies and is materially satisfied** — M2 moves `tests/Feature/Security` (module gating, per-module access control, marketplace kill-switch, rule-12 surface) out of the `if:`-gated `backend-test` into a new **ungated** `security-regression` job (`ci.yml:354-431`) that is in the `all-checks-pass` `needs` list (`:1249`), and the manifest+checker make that ungating mechanically un-droppable (tampers D1–D8 below). I ran that suite: **93 tests, 305 assertions, EXIT=0, 42.5 s**, matching the doc's 42.6 s (`DECISION…:1083`). No production permission/tenancy/module-gating code is touched anywhere in the range.

**Standing checks.** Rule 19: N/A (no money/quantity path). Migrations / named queues: none. Constructor injection: N/A — standalone CLI script + bare `PHPUnit\Framework\TestCase`; `grep -nE '\bapp\(|env\(|getenv'` on both files → no hits. i18n en+fr: no user-facing strings (CI/developer output only). PHPStan: unaffected — `phpstan.neon` `paths: [app/]`, the new files are in `tools/`/`tests/`. Red-first: **verified non-vacuous by re-running all four round-8 behaviours against the round-7 checker** (`fd2bf2948` copy) — every one `EXIT=0` there, `EXIT=1` at HEAD.

**Round-7 findings — all verified CLOSED by execution, not by reading the report:**
- **F1 (the blocking P1, Pint).** Lock-pinned `Pint 1.29.0`, no `pint.json`: `./vendor/bin/pint --test` over `apps/api` → `{"result":"pass"}`, **EXIT=0**; each file individually EXIT=0. `backend-lint` is green at HEAD.
- **F2 (shell-soft door on the checker's own steps).** `run: php tools/feature-lane-manifest-check.php || true` → EXIT=1 `SELF-CHECK: … the command contains '||'`. `…FeatureLaneManifestCheckerTest.php; exit 0` → EXIT=1 `SELF-CHECK: … contains ';'`. Cause fixed, not symptom: the door moved into `gatingDefects()` (`:79-90`) and the lane path now calls that same helper (`:490`), as does B3 (`:612`).
- **F3 (trigger set beyond `branches`).** `paths-ignore: ['**']` → EXIT=1; `types: [labeled]` → EXIT=1 (`:539-556`).
- **F4 (history rewritten by the N-6 blanket replace).** `:1578` and `:1647` restored to `1 707`; the live line `:1144` reads `1 708`, which matches shipped output (`1708`). The two records are coherent again.
- **F5.** One implementation of the six doors, called from both sites — verified above.
- **F6.** The companion guard now bounds its window at the next method and squashes whitespace (`FeatureLaneManifestCheckerTest.php:845-864`).
- **N-3 re-verified empirically *after* the Pint rewrite** (the exact interaction F6 warned about): in a mirrored api root, appending `--group zzz-nonexistent` to the **workflow's** run line makes `test_every_lane_actually_selects_tests` FAIL — *"lane security-regression selects ZERO tests … Run line: ./vendor/bin/phpunit tests/Feature/Security --group zzz-nonexistent"*. Baseline passes (10 assertions).

**No coverage regression.** `apps/api/tests/feature-lane-manifest.json` is byte-identical since round 5 (`git diff 990e3ae26..HEAD` on it → empty). Checker on the real tree: EXIT=0, 1329 Feature classes / 74 groups / debt 1114 / 1708 total classes — unchanged. Liveness suite at HEAD: **46/46, 121 assertions, 8.4 s** (was 42/112). `1316 distinct` in `ci.yml:178` verified against the tree (1329 files, 1316 distinct basenames) — not a drifted count.

---

## Findings

### 1 — P2 — CONFIRMED — same family (trigger set), NON-BLOCKING residual: three more one-line edits stop the workflow starting
`apps/api/tools/feature-lane-manifest-check.php:522-556`

Three bypasses I ran, **all `EXIT=0` / `lane manifest OK`** while every lane still certifies `runs_on_pr_dev: true`:
1. `on.pull_request.paths: ['docs/**']` — a *positive* path filter; no `apps/**` PR ever starts the workflow. Only `paths-ignore` is inspected.
2. `on.pull_request.paths-ignore: ['apps/**', '.github/**', 'scripts/**', 'docs/**']` — same effect, but not the literal `'**'`/`'**/*'` the new check matches.
3. `branches: [main, dev, '!dev']` — GitHub's negation pattern; `in_array('dev', …)` is satisfied by the positive entry.

Failure scenario: one line in `on:` removes the whole gate surface (including `security-regression`) from PR→dev, and this checker reports OK. Same family as N-1 / round-7 finding 3 ⇒ named residual under the recorded terminal condition. Mitigation stands: S-14's owner-run pre-promotion `workflow_dispatch` executes the real jobs.

### 2 — P2 — CONFIRMED — same family (self-application), NON-BLOCKING residual: the `if:` door is a substring test, so a *containing* expression walks through it
`apps/api/tools/feature-lane-manifest-check.php:60-62` (job `if:`) and `:103-105` (transitive `needs`) — `! str_contains($jobIf, "base_ref == 'dev'")`

Two bypasses I ran, both **`EXIT=0`**, on `backend-architecture` (the checker's own host job):
1. `if: ${{ github.base_ref == 'dev' && false }}` — never true, passes the substring test.
2. `if: ${{ github.base_ref == 'dev' && github.actor == 'nobody' }}` — never true in practice.

Identical consequence to the N-2 job-`if:` door it closes. Same family ⇒ residual; a stricter form would be exact-match against a small allowlist of accepted expressions.

### 3 — P3 — CONFIRMED — the N-3 companion guard is still lexical, only harder to trip over
`apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php:845-864`

Method-bounded window + whitespace-squashed comparison is a real improvement (it survives `concat_space`, which had already made it vacuous once). It remains a single-needle check: `sprintf('%s && %s', …)`, `implode`, or renaming `$selector` all evade it silently. The docblock is honest that it is lexical and names the behavioural proof — which I re-verified independently above — so this is a note, not a defect.

### 4 — P3 — CONFIRMED — a shipped in-file comment now under-counts the doors it describes
`apps/api/tools/feature-lane-manifest-check.php:582`: *"43 s of security tests were protected against five doors; the guard that protects them was protected against none."* Post-unification both sides enforce six. This is precisely the prose-drift class N-6 was raised about, inside a shipped file. Count-free wording would immunise it.

### 5 — P3 — CONFIRMED — the new `security-regression` job's environment is a *subset* of `backend-test`'s; local green is not CI green
`.github/workflows/ci.yml:354-431` vs `:219-300`

`backend-test` provides `services: postgres (timescaledb) + redis` and installs `pdo_pgsql`; the new job provides **no services** and installs `pdo_sqlite`. The reasoning is sound and documented (`phpunit.xml:44-45` pins sqlite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`), and I confirmed the suite is green locally — but my local box has a running stack, so a latent service dependency would not surface here, and the executor cannot push (gate-r1 H-7). Concrete requirement, not a blocker: the S-14 pre-promotion `workflow_dispatch` must show **`security-regression` executed and green**, and the M4 handback's event-graph acceptance check must name that job id alongside the M1 step ids already named at `DECISION…:765`. §1726 records the standing mitigation; the job-id naming is owed at M3/M4.

### 6 — P3 — CONFIRMED — two of the four new liveness cases assert on weak needles
`FeatureLaneManifestCheckerTest.php:909` (`assertStringContainsString('paths-ignore', $out)`) and `:924` (`'types'`)

Both are non-vacuous — I confirmed each tamper is `EXIT=0` against the round-7 checker and `EXIT=1` at HEAD, and a no-op `str_replace` would fail the `assertSame(1, $exit)` loudly — but `'types'` in particular would match unrelated future output. Pin the full `TRIGGER SET:` prefix.

### 7 — P3 — carried forward unchanged — N-4 (allowlists relocated outside `ci.yml` leave the anchoring lint's field of view) and N-5 (one more `<groups><exclude>` in the *default* `phpunit.xml` partially guts a certified lane). Both correctly recorded as named open residuals in §(73) under the S-14 mitigation. §(78) states the N-4 line is included in the M3 checklist; M3 is `pending`, so that is verifiable only at M3.

---

## Bypasses and hypotheses that FAILED (no defect)

1. Job `if: github.base_ref == 'main'` on `backend-architecture` → EXIT=1, both self-steps named.
2. Job `continue-on-error: true` → EXIT=1 SELF-CHECK.
3. `needs: [backend-test]` (transitively gated) → EXIT=1 SELF-CHECK, names `backend-test`.
4. Step `continue-on-error: true` on the checker step → EXIT=1 SELF-CHECK.
5. Step `if:` on the checker step → EXIT=1 SELF-CHECK.
6. Liveness step replaced by `echo removed` → EXIT=1 (*"not present in any live `run:` block"*).
7. `branches: [main, dev] → [main]` → EXIT=1 TRIGGER SET. All six doors survive the unification intact.
8. **Suspected regression from the unification — refuted.** The lane path lost its *unconditional* shell-soft error (it now only flips `runsOnPrDev`, which errors on claim mismatch). I tested the gap directly: a lane declaring `runs_on_pr_dev: false` **and** softened with `|| true` still fails — the R-3 neutral-flag allowlist (`:414-457`) catches `'||'` in the run-line tail regardless of the claim (D9, EXIT=1). `set +e` *before* the command breaks `str_starts_with` and fails closed as a FICTION lane. A lane claiming `false` on an ungated job also still errors (D10). No coverage lost.
9. **Pint reformat silently breaking the `--filter` machinery — refuted.** Un-anchoring the 16-entry allowlist → EXIT=1 UNANCHORED; planting `ZzzNoSuchClassTest` → EXIT=1 DEAD entry. String semantics survived `single_quote`/`concat_space` (verified by output, including newline-bearing `sprintf` segments left double-quoted).
10. Renaming/removing the `all-checks-pass` job → EXIT=1, three JOB-missing-from-`needs` errors. Not silently satisfiable.
11. Wrapping the self step (`bash -c '…' || exit 0`) → EXIT=1 (step no longer present in any live `run:`). A trailing `# noop` comment → EXIT=0, correctly (it softens nothing).
12. False claim in evidence — searched, none found. `1316 distinct` reconciled against the tree; `990`/`1114` are two different metrics and both consistent with `DECISION…:980` and `:1058`; "46 cases" and "pint pass" measured; §(75)/§(76)/§(77) claims each verified against code and by execution. Historical sections describing "five analyses" (`:1617`, `:1695`) are round-7-era records, deliberately left intact — which is exactly the principle round-7 finding 4 established.
13. Scope creep: none. Round-8 delta = checker, its test, decision doc, progress YAML, the round-7 register. No control file (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`), no production code, no migration, no queue, no `ci.yml` edit at all this round.

---

**Disposition.** The blocking round-7 P1 is closed and measured green in both directions; all four parent-authorized items (N-1/N-2/N-3/N-6) remain closed, and N-3 is closed in the strong empirical sense **after** the formatter rewrote the file that carries it — the one interaction most likely to have silently regressed. The three same-family findings round 7 raised as residuals were fixed rather than shipped, the rewritten history is restored, and the manifest/debt numbers are unmoved. What I surface new (findings 1 and 2) is squarely within the two families the recorded terminal condition names as residuals — the `if:`-substring door and the trigger-set surface — and I found nothing of a different family: no false claim in the evidence, no coverage regression, no CI job turned red. `backend-lint` green, checker green, liveness suite 46/46, `tests/Feature/Security` green locally. Findings 1–2 belong in the §(73) residual list with the S-14 mitigation; finding 5's job-id naming is owed at M3/M4.

VERDICT: ACCEPT
