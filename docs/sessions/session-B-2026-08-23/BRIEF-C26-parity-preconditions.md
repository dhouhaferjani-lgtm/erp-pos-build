# BRIEF — Lane B2-2 / C-26: Slice D preconditions (parser R2-1 + owner-pin reader R2-2/O-31 + CI-wiring report)

Test-only lane. Follow `LANE-PROTOCOL.md`. Worktree (parent creates it): `.worktrees/sb2-c26-parity-preconditions`,
branch `fix/sb2-c26-parity-preconditions`, base = dev tip at dispatch. PG 5433 user `autoerp`/`autoerp_secret`,
own throwaway DB `autoerp_c26_test`. Tool calls < 90 s, one test file per run, by path, never the suite, no stash, never push.

Scope = exactly these files:
- `apps/api/tests/Architecture/Support/PgValueSetCheckReader.php`
- `apps/api/tests/Architecture/EnumCheckParityDetectorLivenessTest.php`
- `apps/api/tests/Architecture/EnumCheckParityTest.php`
- NEW `docs/handoff/progress/slice-d-parity.progress.yaml` (mirror file, see §2)
- `apps/api/tests/feature-lane-manifest.json` only if the checker demands it.
**Do NOT edit `.github/**`** (S-14: workflow edits are a dispatch leg owned by Session A/owner) — §3 is a REPORT.
Do NOT touch any baseline JSON, any migration, any production code.

## 1. R2-1 — the greedy ARRAY-body false COVERED (gate record `2026-08-25-sb-d1-parity-gate-r2.md` §R2-1)
`PgValueSetCheckReader::parseValueSet()` `:145` and `:161` capture `ARRAY\[(.*)\]\s*$` greedily, so
`CHECK (status IN (...) AND origin IN (...))` — which PG renders as two `= ANY (ARRAY[...])` joined by AND —
parses as ONE column with the UNION of both sets → FALSE COVERED (reproduced live on `payments.status`).
Fix: `parseValueSet` must return `null` for any definition whose top-level predicate contains ` AND ` (a
compound is never a single-column value set), AND both ARRAY regexes must forbid `]` inside the body
(`ARRAY\[([^\]]*)\]`). Do both (belt and braces); keep the OR-chain path unchanged.
Red-first pins in `EnumCheckParityDetectorLivenessTest`:
- `constraintDefinitionProvider()` gains: (a) `AND of two ARRAY value sets → null`; (b) `AND of one ARRAY set and a
  non-set clause → null`; (c) OR-chain with a nested AND branch → null (confirm it already is; if already green,
  say so — it is a pin, not a fix); (d) the NOT VALID + AND combination → null.
- `the_pg_constraint_reader_actually_reads_a_planted_check()` gains a planted-AND arm on a real table (PG-only):
  plant `CHECK (status = ANY(ARRAY['a','b']) AND origin = ANY(ARRAY['c','d']))` on a scratch table and assert the
  reader reports it as NOT a value set (verdict MISSING for that column, not COVERED).
Paste the red output of the new cases against the untouched parser, then the green.

## 2. R2-2 / O-31 — owner-pinned anti-growth ceiling over ALL THREE artifacts (reader side; the owner arms it)
Model: `DocumentPerActionBaselineRatchetTest::the_working_baseline_never_grows_against_the_owner_pinned_blob()`
(`:114-175`) + its `mirrorPin()`/`gitCatFile()` helpers. Differences, deliberately:
- Pin ONE SEED COMMIT, not a blob, so a single variable + a single tag covers all three files:
  env `ENUM_CHECK_PARITY_PROTECTED_SEED` = 40-hex commit sha; the test reads
  `git cat-file -p <seed>:apps/api/tests/Architecture/baselines/{enum-check-parity-baseline.json,
  enum-check-parity-central-baseline.json, enum-check-parity-acknowledgements.json}` (repository root =
  the same `repositoryRoot()` technique the DPA test uses).
- Mirror file `docs/handoff/progress/slice-d-parity.progress.yaml` with fields
  `enum_check_parity_seed_commit:`, `enum_check_parity_pin_tag:` (name pre-allocated:
  `ci-pin/enum-check-parity-r1`), and a one-line history block. Variable ≠ mirror ⇒ FAIL (tamper signal).
- Assertions (all fail-closed when the env is unset/empty — the DPA wording is the template):
  (a) tenant working baseline ⊆ pinned tenant baseline (shrink-only);
  (b) central working baseline ⊆ pinned central baseline;
  (c) EVERY key present in the pinned acknowledgements is still present in the working acknowledgements with
      the SAME `verdict`/predicate (an acknowledgement can be ADDED with review, never silently dropped or
      relabelled — this closes R2-2: widen-the-CHECK + delete-the-entry is now red).
- Add the named-entry rot guard the gate suggested as the cheap in-lane half regardless
  (`fiscal_event_quarantine.integrity_exception_class` + `pos_receipts.receipt_type` must resolve), so R2-2
  is closed even while the owner variable is unset.
- Local run recipe in the test's failure message:
  `export ENUM_CHECK_PARITY_PROTECTED_SEED=$(git rev-parse <seed>)` — the parent will fill the seed sha
  in the O-31 owner sheet from your report.
Red-first: the three pinned-vs-working assertions must be shown red with a deliberately tampered working
copy (grow the tenant baseline by one key in a temp copy / drop the quarantine acknowledgement) before green.
Do NOT mutation-test by editing the committed JSON files in place and forgetting to restore them — run
`git status` at the end and paste it.

## 3. CI wiring — REPORT ONLY (S-14 leg for Session A's promotion)
Write the exact `ci.yml` hunk (as a fenced diff in your report, not applied): PG arm
`./vendor/bin/phpunit tests/Architecture/EnumCheckParityTest.php` + `EnumCheckParityDetectorLivenessTest.php`
into `treasury-spine-pgsql` (after `:1229` `tests/Feature/Accounting`, same `DB_DATABASE=autoerp_treasury_test`
env, with the `grep -qE 'OK \([1-9][0-9]* test'` zero-selection guard the DPA step uses at `:311-315`); the
driver-free arm (`EnumCheckParityDetectorLivenessTest`) into `backend-architecture` (`:143`); the pin-tag fetch
step cloned from `:289-305` reading `enum_check_parity_pin_tag` from the new YAML; and the variable mapping
`ENUM_CHECK_PARITY_PROTECTED_SEED: ${{ vars.ENUM_CHECK_PARITY_PROTECTED_SEED }}`. Note the concurrency rule
comment at `:1222` (sequential phpunit invocations only).

## Deliverable
LANE-PROTOCOL §Deliverable + the §3 diff + `git status` proof that no baseline JSON changed + the exact
seed-commit candidate (the dev commit at which the three artifacts have their current blobs — today:
baseline `47e77720a` / central `d3623927c` / acks `3df370e19`, last moved by `da5ae1379`; re-derive with
`git rev-parse dev:<path>` at your base). Do NOT merge.
