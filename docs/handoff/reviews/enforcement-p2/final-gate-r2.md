# FINAL-GATE REGISTER — package p2 M4 round 2 attempt 3
accepted_sha: cabd15e5108b93d36b445d73aeddbb2a18c55641
base_sha: c97e0d1ada0ff73c7beb12dca478fa106df23128   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml
snapshot: detached-worktree @ cabd15e5108b93d36b445d73aeddbb2a18c55641 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-19-r2a3.md=6ce4a2da7b0eb482d756ca8eb4143d9aa7f9b6d4ac1d2715c8a26aed49237c66   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:frontend-conventions=278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385
control_sha256: lens:tenancy-authz=9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345
max_fix_rounds: 5
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement p2, milestone M4, round 2

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.69xk8SxO4K/snap` at `cabd15e5108b93d36b445d73aeddbb2a18c55641` (A′)
**Range:** `c97e0d1ada0ff73c7beb12dca478fa106df23128..cabd15e5108b93d36b445d73aeddbb2a18c55641` — base confirmed a strict ancestor of A′ and `BASE != A`; 57 commits; `git status --porcelain` empty in the sealed worktree.
**Handback evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-19-r2a3.md` (`sha256 6ce4a2da7b0eb482d756ca8eb4143d9aa7f9b6d4ac1d2715c8a26aed49237c66`)
**Lenses applied in full:** `frontend-conventions`, `tenancy-authz`.
**Control-file preflight:** clean — `git diff --name-only base..A′` (84 paths) contains no `scripts/adversarial-review*.sh`, no brief, no `SELF-REVIEW-HARNESS.md`, no `enforcement-control-manifest.yaml`, no `.claude/agents/*-reviewer.md`, and no added `*control-manifest*` surrogate (grep count = 0). No `apps/pos|mobile|erp-ml|platform` path. Exactly one production source file: `apps/web/src/features/treasury/statements/api.ts` (disclosed deviation 3).
**Candidate YAML field-check:** `base_sha` == dispatch base ✓ · final milestone M4 `status: review` ✓ · `fix_rounds: 1` ≤ top-level `max_fix_rounds: 5` ✓ · `control_manifest: {path, sha256: 709b6fe9…}` ✓.

## Content-derived evidence of inspection (required tables)

| Table | Rows | First row key | Last row key | Independently reproduced at A′? |
|---|---|---|---|---|
| **Handback §3a** i18n provenance | **3** (locale rows) | `en` — own 56 / en-aliased 0 / english-spread 0 | `ar` — own **21** / en-aliased **23** / english-spread **12** | **Rows: YES** — re-derived by importing `parseI18nWiring(DEFAULT_ROOT)`: `{en:{own:56}, fr:{own:56}, ar:{own:21,"en-aliased":23,"english-spread":12}}`. **Trailing "4 594": NO** — see finding 1 |
| **Handback §3b** baseline composition | **5** (metric rows) | `namespaces` = 56 | `structural failures` = 0 | YES — `namespaces` 56, `en 9242 / fr 9258 / ar 4702 (1998 behind aliases)`, locale files `en 57 · fr 57 · ar 34` (counted on disk), `2 917` entries, `fresh: []` / `stale: []` |
| **Handback §3c** feature-lane census | **3** (measure rows) | `all of tests/Feature (74 groups)` = 1 329 files / 1 316 distinct | `minus the 111 allowlisted names inside them` = 1 003 files / **991** distinct | YES — walked the tree: 1 329 files, 1 316 distinct (3 dup basenames), 1 114/1 102 laneless, 111 allowlisted inside them, 1 003/**991** remainder; allowlists 112 + 16 entries = **124 distinct** (4 overlap) |
| **Handback §3d** F-2 measurement | **1** (measurement row) | `6.24 s/class mean` (40-class random 6.18, `Accounting` 6.29, SQLite) | same row (whole suite ≈ 138 min; 71 laneless groups ≈ 116 min) | Arithmetic consistent (1 329 × 6.24 s = 138 min; 1 114 × 6.24 s = 116 min). Not re-runnable — sealed snapshot carries no `apps/api/vendor` |
| **Handback §5e** scope-proof allowlist | **12** bucket rows + total | `.github/workflows/ci.yml` = 1 | `docs/handoff/…` = 25 | YES — bucketed all 84 paths: 1/1/1/1/46/3/1/1/1/1/2/25 = **84**, none outside the allowlist; `generated.d.ts` absent |
| **Baseline artifact** `apps/web/tools/i18n-completeness-baseline.json` | **2 917** entries | `ar\|adminCountryDefaults\|aliased\|*` | `fr\|workshop-bundles\|plural\|interval.months_many` | YES — parsed; breakdown exactly `ar aliased 23 · ar missing 2 848 · ar plural 9 · fr plural 29 · en plural 8` = 2 917; identical to the pinned seed blob `da151bbc5…` at `cb618c12c` |
| **Lane manifest** `apps/api/tests/feature-lane-manifest.json` `groups` | **74** | `(root files)` — deferred, 1 class | `Workshop` — deferred, 46 classes | YES — keys sorted; 3 `lane` groups (215 classes: Security 17 + Accounting/Treasury 198) + 71 `deferred` (1 114) = **1 329** = tree count; `debt_ceiling` 1 114 ✓ |
| **DECISION §(1)** provenance table | **3** (locale rows) | `en` 56/0/0 | `ar` 21/23/12 | Rows YES; trailing "4 594" NO — finding 1 |
| **DECISION §(2)** "Baseline statistics at the seed" | **10** (metric rows) | `Namespaces in the ns array` = 56 | `Structural failures` = 0 | **NO** — `Total baseline entries 4 631` / `ar missing 4 585` reproduce **only at the superseded first seed `96b7fd0e8`**; at the pinned seed and at A′ the values are 2 917 / 2 848 (+ an `aliased 23` row the table does not have). Finding 1 |

## Round-1 findings — disposition

| # | Round-1 finding | Status |
|---|---|---|
| 1 | **[BLOCKER]** `packages/shared/types/generated.d.ts` changed while three artifacts denied it | **CLOSED.** `2bf0d7731` reverts it: blob at A′ = blob at base = `0c652f34a3f58f959ff21564643e8b40019a1fd4` (verified by `git rev-parse` on both revisions); `git diff --name-only base..A′` no longer contains the path (grep count 0). The three untouched-claims are true as written |
| 2 | **[MAJOR]** M4 scope proof was a stale paste (`84 files, 12022+/42−`) | **CLOSED.** §5e re-run at the tip with an in-proof HEAD-equality assertion; my own `git diff --stat base..A′` returns `84 files changed, 12059 insertions(+), 44 deletions(-)` — byte-for-byte the pasted value |
| 3 | **[MAJOR]** "FOUR inherited red gates" overstated by one | **PARTIALLY CLOSED.** Under the parent's REVERT ruling the correct answer is FOUR; the blocker `detail` and ANNOUNCE §8.3 now say FOUR and explain the revert. The blocker's own `condition:` headline still says THREE — finding 2 below |
| 4 | **[MINOR]** mid-wave `max_fix_rounds` overrides asserted from inside the candidate | **CLOSED** as an awareness item: authority pointers added beside both fields; handback deviation 7 states plainly that the quotations are a record, not evidence |

## What I verified by execution, not by reading

The i18n audit is dependency-free, so I re-ran it rather than trusting the paste:

- Green run reproduces the handback verbatim: `56 namespaces, en=9242, fr=9258, ar=4702 authored (1998 behind aliases); 2917 known gap(s)`, `fresh: []`, `stale: []`. **Exit 0.**
- `env -u I18N_BASELINE_PROTECTED_BLOB` → `FAIL CLOSED: … is unset`. **Exit 1.**
- Wrong variable value → `FAIL CLOSED: MIRROR DRIFT`, printing both hashes. **Exit 1.**
- Pin integrity: `cb618c12c:apps/web/tools/i18n-completeness-baseline.json` = `da151bbc51edcd066d2153b51a1df8ae1ab5bd32` = YAML mirror = blob at A′; two-commit topology (seed `cb618c12c` → metadata `29b6043bd`) matches R4-H-3, and the pre-allocated `i18n_baseline_pin_tag: ci-pin/enforcement-p2-r1` is non-null in A′ and is the exact string the `frontend-lint` fetch step uses.
- Workflow graph parsed from YAML (not grepped): 16 jobs; `on` = PR→`main`/`dev`, push→`main`, `workflow_dispatch` — the brief's true event graph; `security-regression`, `frontend-lint`, `backend-architecture`, `types-drift` all carry **no `if:`**; `all-checks-pass` `needs` has **13** members including `security-regression`. The handback's §5d step/job names match `ci.yml` exactly.
- Anchoring: both surviving `--filter` values are the `/\\(A|B)::/` form; the anchor requires a namespace separator, so `AnalyticsTest` can no longer select `ExpenseAnalyticsTest`.
- `security-regression`'s reduced environment is sound on inspection: `phpunit.xml` pins `DB_CONNECTION=sqlite`/`:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `BROADCAST_CONNECTION=null`, and PHPUnit sets those before Dotenv's non-overwriting load — so the absent postgres/redis services are not reachable by config. The residual (first real execution) is correctly disclosed in §5d and belongs to the S-14 dispatch.

PHPUnit and Vitest could not be executed (no `apps/api/vendor`, no `apps/web/node_modules`); I verified those suites structurally and state the limitation rather than certifying green.

**Deliverable coverage is strong and belongs on the record.** `test:tools` is exactly `vitest run tools/__tests__`; the CI step runs exactly `pnpm test:eslint-rules && pnpm test:tools`; the i18n gate is a discrete `frontend-lint` step (never the `lint` chain) with `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` mapped and the pin tag fetched explicitly; all three previously-untested ESLint rules gained RuleTesters; `feature-lane-manifest-check.php` closes its own bypasses at a level well beyond the brief (relabel-to-`excluded`, lane search-and-replace, ceiling growth, decoy-step shadowing, `-c` config substitution, trigger-set removal, aggregate membership derived from the *resolved* job, and self-application through one shared `gatingDefects()` door set). None of the findings below touch that work.

---

## Findings

### [MAJOR / P2] — the i18n census figures in the handback and the DECISION record are the **superseded first-seed** numbers and do not reproduce at A′

Three artifacts state Arabic-gap figures that reproduce **exactly at `96b7fd0e8`** — the original seed commit, discarded and replaced by the seed revision `cb618c12c` at M1 fix round 1 — and at no revision in the accepted candidate:

| Claim | Where | Value stated | Value at A′ (and at the pinned seed) | Value at `96b7fd0e8` |
|---|---|---|---|---|
| "The authored-provenance scanner reports **4 594**" | **Handback §3a**; `DECISION` §(1) | 4 594 | **2 880** `ar` baseline entries (key-level within those 35 namespaces: 4 608) | **4 594** ← exact |
| "**Total baseline entries** 4 631" | `DECISION` §(2) | 4 631 | **2 917** | **4 631** ← exact |
| "— `ar` `missing` 4 585" | `DECISION` §(2) | 4 585 | **2 848** (+ an `aliased` row of 23 the table does not have at all) | **4 585** ← exact |
| "Largest Arabic gaps … `adminCountryDefaults` 192, `withholding` 181, `loyalty` 178" | `DECISION` §(2) | those three | at A′ those three namespaces are `en-aliased` (one entry each, no per-key gaps); the actual 6th–8th are `import` 170, `compliance` 157, `common` 125 | those three ← exact |
| "…`finance` 268" | `DECISION` §(2) | 268 | **263** | **263** — reproduces at neither revision |

`git log -L '195,205:…DECISION-…md'` shows the §(2) table has not been touched since `6566b5188` (Phase 5.1.2) — written **before** the seed revision and before all seven M1 fix rounds. There is no note marking it historical; it is headed "Baseline statistics at the seed" while `i18n_baseline_seed_commit` pins `cb618c12c`, and §(3) reason 1 uses the stale 4 594 as the operative argument for the ruling the brief requires be recorded explicitly (*"4 594 Arabic findings would fail `frontend-lint`…"*). The doc's own later narrative says "2 917 entries" five times, so the file contradicts itself with the stale figure in the headline table position.

This matters at this gate specifically, not merely as tidiness. Brief §7 item 3 makes the census tables the review targets; R8-H-2 makes the handback bytes the object the pin-tag annotation binds and P3-M0 re-verifies; both files land verbatim in the closing commit C. It is also the same failure class as round-1's stale scope proof — a figure carried forward from an earlier revision — and the handback's own §4 item 9 names that pattern as the recurring failure mode with "a stated inclusion rule beside every enumeration" as the countermeasure. That countermeasure was not applied to §(2)'s own table.

**Fix:** re-derive §(2) at the pinned seed (`2 917` total; `ar aliased 23`, `ar missing 2 848`, `ar plural 9`, `fr plural 29`, `en plural 8`; `finance 263`; top-8 list re-taken) and correct the two "4 594" occurrences plus handback §3a's, stating which quantity is meant (baseline entries vs key-level gaps). If any figure is deliberately retained as history, label it as the first seed and say it was superseded.

### [MAJOR / P2] — the promotion blocker's own headline still says **THREE** inherited red gates while everything under it says FOUR

`docs/handoff/progress/enforcement-p2.progress.yaml`, `blockers[0]`:

```
condition: "THREE inherited CI gates are RED at base_sha, which blocks the mandatory
            pre-promotion workflow_dispatch (brief §5 item 4 / LEDGER S-14)."
detail:    … FOURTH, found at M4: types-drift fails … All FOUR jobs have no `if:` guard,
           so all four run on the dispatch.
```

`git log -L` on that line shows `condition:` was written at `e4b3c4dc4` (M1 era, when there genuinely were three) and never updated when the fourth was added at M4, nor by the round-1 fix. The fix commit `2bf0d7731`'s own message asserts *"The inherited-gates blocker and ANNOUNCE §8.3 **stay at FOUR** inherited reds with types-drift included"* — true of the `detail`, of ANNOUNCE §8.3 ("**FOUR** inherited red gates at `base_sha`"), and of handback §6, but false of the blocker line the commit edited. The `blockers:` entry is the harness's operative record of what blocks the wave, and the one it undercounts is precisely the gate round 1 flagged as being mis-stated.

**Fix:** one word — `THREE` → `FOUR` in `blockers[0].condition`.

---

## Assessment

The engineering holds up under execution and is in several places stronger than the brief required: the authored-provenance scanner defeats the merged-`resources` blindness by construction and its provenance classification reproduces exactly (21/23/12 for `ar`); the anti-growth ratchet fails closed on unset variable and on mirror drift; the lane manifest's 74 groups sum to the 1 329 classes actually on disk and its checker applies its own gating analysis to itself; the `security-regression` job closes a real rule-12 module-gating blind spot on PR→dev; the round-1 BLOCKER and the stale scope proof are both genuinely closed, and I reproduced the corrected scope proof byte-for-byte.

What still fails is the same evidence contract that failed at round 1, one layer further in. The scope proof was re-run; the census tables beside it were not. Three artifacts carry Arabic-gap figures that reproduce perfectly at a seed commit the candidate itself discarded, one of them inside the table headed "Baseline statistics at the seed" and one of them load-bearing for the `ar` policy ruling; and the blocker line that tells the parent how many gates block promotion still says three while its own detail, the announcement, and the handback all say four. Because the final ACCEPT and the promoted SHA are the same object — nothing may be committed between them, and these bytes are digest-bound into the pin tag and read by P3-M0 — a close-before-merge finding here has no window in which to close. The remedy is five figures, one top-8 list, and one word: a short fix round, not a rework.

VERDICT: CHANGES-REQUIRED
