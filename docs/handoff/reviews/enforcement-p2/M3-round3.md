## Adversarial merge-gate register — P2-M3, round 3

**Scope reviewed:** brief §3 "2(a)" + the `p2-M3` milestone line (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:391`) and the M3 YAML title (`docs/handoff/progress/enforcement-p2.progress.yaml:256`). Amending authority: **none**. Round-3 delta = `d69a45437` + `b92aa9dd7`; M3's whole footprint is four docs/YAML files (`git diff --name-only 0fce1206d~1..HEAD`), no code.

**Lens — frontend-conventions:** M3 adds zero `apps/web` code, so canonical components / design tokens / RHF / `tenantScopedKey` do not apply. Where the lens bites is the i18n contract the checklist asserts to other lanes; re-verified against code: `ns` = **56** (`apps/web/src/lib/i18n.ts`), `aliased` baseline entries = **23** (`tools/i18n-completeness-baseline.json`, whole-namespace `*` entries), 56−23 = **33** ar-wired, and all twelve namespaces named at `ANNOUNCE-…:107-108` have real `src/locales/ar/*.json` bundles. §3(a) is correct.

---

### 1. P2 — CONFIRMED — the file's own methodology header still states all three retracted methods, in one sentence
`docs/handoff/ANNOUNCE-enforcement-p2-ci-contract-change-2026-08-19.md:8-9`

> "Lane impact below is **measured**, not guessed: `git diff --name-only <p2-base>...<lane-tip>` per worktree, run 2026-08-19."

Every clause is a method this milestone retracted: `--name-only` (changed files — retracted at `:33-37` and §(90)), `<p2-base>` (stale base — retracted for `dev`), and *"per worktree"* (the round-1 P1 root cause, retracted twice). §10 now opens with the correct rule at `:265-271`, but a reader who checks the methodology where the document states it gets the discredited one, above tables that were re-measured a different way. §(92) names the countermeasure as *"a stated inclusion rule next to every enumeration"* — this is the document's most prominent enumeration statement and it states the wrong rule. Failure scenario: a lane or the parent re-runs the printed command to re-verify its own row before rebasing, gets a different (changed-files, stale-base) answer than the table, and cannot tell which is authoritative — the exact falsifiability the fix was supposed to buy.

### 2. P2 — CONFIRMED — `DECISION` §(82) is unchanged since the original M3 commit, and still carries the wording round 2 quoted verbatim
`docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:1951-1953` · `git log -S"three-lane" -- DECISION-…` → only `0fce1206d`

Round-2 finding 2 cited this section explicitly (*"§(82) still says the **three-lane** `ci.yml` reconciliation order"*). Neither fix round touched it. It still reads: *"Contents, all measured rather than assumed (`git diff --name-only <p2-base>...<lane-tip>` per worktree): the ceiling-hit remediation for **dn-consolidation, es-wave-a0 and dpa-wave3-3d**; the **three-lane** `ci.yml` reconciliation order (P2, UI Wave 0, dn-consolidation, then OpenAPI last)…"* — contradicted by §(80) eleven lines above it (`:1882-1888`: **six** writers, **four** `needs:` rewriters, both of which I reproduced) and by §(90) (`:2067-2070`: `dpa-wave3-3d` is merged into `dev`, its classes are §8-item-0 drift, not a lane action). Failure scenario: the parent sequences the merge from the decision record — the artifact that exists to be the durable ruling — gets a three-lane order that omits `enforcement-p1-dpa-guard` and `es-wave-a0`, and hands a ceiling remediation to a lane that is already in `dev`. This is a previously-raised P2 left neither fixed nor recorded as declined.

### 3. P3 — CONFIRMED — §1's "ceiling now" column is pre-re-baseline for the two `Inventory` rows, with no pointer to §8 item 0
`ANNOUNCE-…:26,29` (`Inventory` … "ceiling now **105**") vs `:204` (§8 item 0: `Inventory` → **106** in the first commit on `dev` after the merge)

By the time `feat/dpa-v8-supplier-goods-return` or `feat/supplier-invoice-ocr` acts, the ceiling they will find is 106, not the 105 printed. The action text is relative (*"raise `Inventory` … by 2"*), so a careful lane is fine — but the `dn` row states an absolute target (*"to ≥74"*), so the two styles are mixed in one table, and a lane copying 105+2 = 107 against an actual 108 classes gets `COVERAGE DEBT GREW`. One clause (*"these ceilings are P2-base values; §8 item 0 re-baselines `Inventory` to 106 — raise the value you find"*) closes it.

### 4. P3 — CONFIRMED — the stated inclusion rule does not survive its own application on three edges
`ANNOUNCE-…:265-271`, rows `:273-290`

I applied the rule mechanically to all 96 local branches. It reproduces the roster exactly, with three edges the rule does not cover: (a) `codex/enforcement-p2-ci-guards` — this package's own branch — passes every stated filter and has no row (a fourth unstated exclusion; harmless, but it is the shape §(92) names); (b) `factory/board` shares **no merge base** with `dev`, so `git diff dev...factory/board` exits `fatal: no merge base` — the rule cannot be executed for a row it lists (the conclusion holds anyway: 21 files, none under `tests/Feature`, `src/locales`, or `ci.yml`); (c) `l6-integration-verify` has **multiple** merge bases, so `dev...` is base-dependent and warns (the printed 2/4/2 does reproduce with git's chosen base).

### 5. P3 — CONFIRMED — the "already merged into `dev`" courtesy list is itself `codex/*`-filtered
`ANNOUNCE-…:292-295`

Seven `codex/*` branches are listed; `codex/reviewer-round6-unauthorized-mutations` is equally merged and absent, as are ~60 merged `feat/*`/`fix/*`/`chore/*`/`docs/*` branches. No action is lost (the stated rule drops merged branches), but the list is introduced as *"listed so their absence is not read as an oversight"*, which is only true for the subset it silently chose.

### 6. P3 — CONFIRMED — "Six new steps and one new job" is five steps and one job
`ANNOUNCE-…:79` vs `git diff <base>..HEAD -- .github/workflows/ci.yml | grep '^+.*- name:'`

Steps added to **existing** jobs: 2 in `backend-architecture`, 3 in `frontend-lint` = 5. The other seven added `- name:` lines are internal steps of the new `security-regression` job. The table's rows 1–5 are steps and row 6 is the job, so the headline counts the job as a step.

### 7. P3 — CONFIRMED — "OpenAPI is the **last** `ci.yml` writer … lands after P2 **with certainty**" is a scheduling assertion, not a measurement
`ANNOUNCE-…:56,133`

With six writers now identified, nothing in the repo establishes that ordering. Impact is nil — §1a's remedy (disposition the `OpenApi` group) is order-independent — but it sits inside a document whose stated standard is "measured, not guessed".

---

### Bypasses I tried that FAILED — the round-2 fixes hold, and hold under independent derivation

- **The §10 rule, executed rather than trusted.** Enumerated all 96 local branches, partitioned by `git merge-base --is-ancestor <b> dev`, applied the four stated filters. Result = exactly the 15 named rows + the 5 grouped "nothing to do" branches, no omissions except finding 4's three edges. The habit named in §(92) is genuinely broken this time.
- **Every per-lane measurement re-derived** with `git diff --diff-filter=A dev...<branch>`: `dn` 9 `Document`/0 `Partner`, `es-wave-a0` 6 `Fiscal`/0 `POS`, `openapi` 6 new `OpenApi`, `enforcement-p1` **0**, `l6` 2 `Http`/4 `Modules`/2 `Tenant`, `scan-vat` 1 `Taxation`, `dpa-v8` 2 `Inventory`, `r2f2` 1 `Document`, `r2d` 2 `Modules`, `ocr` 1 `Inventory`, `owner-dashboard` 1 `Seeders`, `r2f4` 2 `Accounting`, `ui-wave0`/`rafiq`/`pos-clean-workbench`/the four grouped branches 0. Locale counts (16/4/3/2/3/2/3) reproduce. All match.
- **§8 item 0 completeness — the check the executor could most plausibly have half-done.** I recomputed *every* deferred/excluded group against `dev`, not just the two named: exactly **two** have drifted (`Inventory` 105→106, `CountryDefaults` 27→28), and the deferred+excluded total on `dev` is **1116** including the `(root files)` group (`groupOf()`, `feature-lane-manifest-check.php:142-145`). No third drifted group exists. `Accounting` 79→81 on `dev` is a **lane** group and the ceiling test only fires for `deferred||excluded` (`:337-346`), so §1's *"nothing — `Accounting` is laned"* for `feat/r2f4-correcting-documents` is right.
- **Every §1 ceiling** matches `feature-lane-manifest.json` exactly: `Document` 65, `Fiscal` 73, `Http` 2, `Modules` 53, `Tenant` 29, `Inventory` 105, `Taxation` 31, `Seeders` 26.
- **The six/four `ci.yml` claim**, re-derived from branch tips vs `dev`: writers = P2, `ui-wave0`, `dn`, `es-wave-a0`, `openapi`, `enforcement-p1` (6); `needs:` rewriters = P2, `ui-wave0` (`+route-manifest-drift`), `openapi` (`+backend-openapi-contract`), `enforcement-p1` (`+backend-dpa-guard`) — and **not** `dn` or `es-wave-a0` (4). §4's table now carries all six rows.
- **The round-2 P2-5 fix, re-derived independently and pushed one level further than the executor.** I enumerated all 16 jobs in `ci.yml` and their `if:`. Jobs admitting PR→dev: `backend-lint`, `chokepoint-gate`, `backend-analyse`, `backend-architecture`, `security-regression`, `backend-test-pgsql`, `t6-phase0b-pgsql`, `treasury-spine-pgsql`, `frontend-lint`, `frontend-typecheck`, `pos-test`, `types-drift` = **exactly twelve**. Gated off: `backend-test` (`:211`), `frontend-test` (`:1063`), `frontend-build` (`:1122`). There is no thirteenth — Option 1 (`:1920-1934`) is now complete, and the stated rule ("every job that runs on the event") is the right one.
- **2(a) still verify-only:** `grep -c 'route-manifest-drift\|check-manifest-drift' .github/workflows/ci.yml` → 0; `git diff --name-only 0fce1206d~1..HEAD` → four docs/YAML files. No drift job, no manifest regeneration, no `gen-route-manifest.mjs` edit.
- **§2 counts:** `test:eslint-rules` 3 suites at base (`git show <base>:apps/web/package.json`) → 6 now ✓; `test:tools` = 7 files under `apps/web/tools/__tests__` ✓; `Security` = 17 classes ✓; the trigger sentence matches `ci.yml:3-8` exactly, including "a direct push to `dev` still starts nothing".
- **§7 pins:** `da151bbc51edcd066d2153b51a1df8ae1ab5bd32` == `progress.yaml:60`; `ci-pin/enforcement-p2-r1` == `ci.yml:998` == `progress.yaml:61`.
- **Process hygiene:** `fix_rounds: 2`, `commit: d69a45437`, `verdict: …/M3-round2.md`, `last_verdict: CHANGES-REQUIRED`, `status: review`, recorded in a separate metadata commit (`b92aa9dd7`, YAML only), no parent register committed into the candidate.

### Standing checks
Rule 19 (float on money/quantity, scale-resolver injection), tenant scoping, constructor injection, en+fr user-facing strings, migrations, Horizon queue coverage, red-first evidence: **not applicable** — the round-3 delta is two markdown files and one YAML, no PHP/TS, no behavioural change. The milestone's own invariants (2(a) verify-only per the M0 snapshot; the aggregate as a *proposal*; one actionable line per open lane) are present and non-vacuous; findings 1 and 2 are staleness in the artifacts that state them, not absence.

VERDICT: CHANGES-REQUIRED
