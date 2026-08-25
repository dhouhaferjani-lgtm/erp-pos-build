Lane complete. Throwaway DB dropped, worktree clean, nothing merged, nothing pushed.

## 1. Branch / worktree / commit

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-c26-parity-preconditions`
- **Branch:** `fix/sb2-c26-parity-preconditions` (base = worktree tip `dabf8fbde`; the brief's `0613e2ce8` is its parent — local `dev` had already moved to `dabf8fbde` when I started, and to `7cd024946` by the end. All three parity artifacts carry identical blobs at `da5ae1379`, `dabf8fbde` and `7cd024946`, verified below.)
- **Commit:** `cf411e1a5` — `fix(sb2-c26): slice-D parity preconditions — R2-1 false-COVERED closed, R2-2/O-31 anti-growth ceiling over all three artifacts`
- 4 files, test-only. No production code, no migration, no `.github/**`, no baseline JSON.

## 2. Red → green, per test file, by path

### R2-1 — pure-function pins (`EnumCheckParityDetectorLivenessTest`, sqlite)

RED, against the **untouched** parser:

```
..............FF.........                                         25 / 25 (100%)
1) …the_constraint_parser_fires_on_every_shape_it_claims with data set "an AND of two ARRAY value sets is NOT a single-column value set — R2-1"
Failed asserting that Array &0 [
    'column' => 'status',
    'values' => Array &1 [ 0 => 'completed', 1 => 'failed', 2 => 'pending', 3 => 'reversed' ],
    'nullable' => false, 'not_validated' => false,
] is identical to null.
2) … with data set "NOT VALID does not launder an AND of two ARRAY value sets — R2-1"
Failed asserting that Array &0 [ 'column' => 'status', 'values' => [completed, failed, pending, reversed], 'nullable' => false, 'not_validated' => true ] is identical to null.
Tests: 25, Assertions: 25, Failures: 2.
```

The union `{completed, failed, pending, reversed}` is exactly `PaymentStatus`'s four cases — the gate record's live `payments.status` repro, reproduced as a unit.

The other four new provider cases were **already green** and are pins, not fixes, exactly as the brief anticipated: `AND` of a set + a non-set clause, `AND` of a set + a foreign-column null guard, and the OR-chain-with-nested-AND branch all already returned `null` (the flattened text does not end in `]`, so no ARRAY regex ever matched). I also added a **counterweight** case — `CHECK (label IN ('salt AND pepper','z'))` must still parse — which is what forces the `AND` scan to be quote-aware rather than a `str_contains`.

### R2-1 — planted arm on a real table (PG)

RED:

```
1) …the_pg_constraint_reader_actually_reads_a_planted_check
A cross-column AND of two value sets was parsed as a single-column value set — the accepted set is
the UNION of both halves, i.e. a FALSE COVERED (gate r2 R2-1). Read:
{"accepted":["a","b","c","d"],"constraints":["enum_check_parity_liveness_probe_and_chk"],"nullable":false,"not_validated":false}
Failed asserting that an array does not have the key 'and_status'.
Tests: 1, Assertions: 13, Failures: 1.
```

GREEN after the fix — `phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php`:
- sqlite: `OK, but some tests were skipped! Tests: 47, Assertions: 86, Skipped: 1` (46 run; the skip is the PG plant arm only)
- pgsql: `OK (47 tests, 102 assertions)` — was 41/93 at the gate.

### R2-2 / O-31 — the pin (`EnumCheckParityTest`, PG)

**RED 1, fail-closed, variable unset:**
```
ENUM_CHECK_PARITY_PROTECTED_SEED is unset or empty, so the anti-growth ceiling over the three parity
artifacts cannot be read and this gate FAILS CLOSED. … Tests: 1, Assertions: 1, Failures: 1.
```

**RED 2, mirror drift** (variable = `7cd024946`, mirror = `da5ae1379`):
```
The progress-YAML mirror (enum_check_parity_seed_commit in docs/handoff/progress/slice-d-parity.progress.yaml)
disagrees with ENUM_CHECK_PARITY_PROTECTED_SEED.
That is a TAMPER SIGNAL, not a skip condition… Tests: 1, Assertions: 3, Failures: 1.
```

**RED 3, tampered working copies** (tenant baseline +1 key, central baseline +1 key, quarantine acknowledgement deleted — all three assertions red at once, plus the rot guard):
```
  tenant baseline GREW: tampered_table.tampered_column::MISSING
  central baseline GREW: tampered_central.tampered_column::MISSING
  acknowledgement DROPPED: fiscal_event_quarantine.integrity_exception_class::NARROWER — widening a CHECK and deleting its acknowledgement in one diff is the move this refuses.
2) …the_named_acknowledgements_still_resolve
  fiscal_event_quarantine.integrity_exception_class — named in NAMED_ACKNOWLEDGEMENTS but GONE from …
Tests: 2, Assertions: 422, Failures: 2.
```

**RED 4, relabel + predicate move** (kind `INTENDED_NARROWER`→`COVERED_BY_COMPOSITE`, `predicate.expects` false→true):
```
  acknowledgement RELABELLED: fiscal_event_quarantine.integrity_exception_class::NARROWER — kind 'INTENDED_NARROWER' -> 'COVERED_BY_COMPOSITE'
  acknowledgement PREDICATE MOVED: … {"expects":false,…} -> {"expects":true,…}
```

Every tamper was applied to a working copy and reverted with `git checkout --` in the **same** bash invocation; `git status --porcelain apps/api/tests/Architecture/baselines` printed empty immediately after each.

**GREEN, armed** — `ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf phpunit tests/Architecture/EnumCheckParityTest.php`:
```
OK (11 tests, 829 assertions)
```

**Zero live verdicts moved by the parser change:** with the fix applied and before the pin tests existed, the untouched gate ran `OK (9 tests, 407 assertions)` — NEW=0, STALE=0, ACK PROBLEMS=0 on all four assertions. (Gate r2 recorded 9/409 at `33e26cd69`; the delta is `da5ae1379` shrinking the tenant baseline 189→188 for `documents.status`, i.e. 1 key × 2 `assertIsString` calls. Current key counts: tenant **188**, central **11**.)

Other gates: `pint --test tests/Architecture` → `{"result":"pass"}` (I ran `pint` once on `EnumCheckParityTest.php` and re-ran the suite green afterwards). `phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` → `OK (76 tests, 431 assertions)` — no manifest edit needed, no new test files. PHPStan N/A (`phpstan.neon` analyses `app/` only).

## 3. Production changes — exact file:line

All test-tree; there is no `app/` change.

`apps/api/tests/Architecture/Support/PgValueSetCheckReader.php`
- `:144-171` — the R2-1 rejection block + rationale; `:168` `if (self::containsUnquotedAnd($flat)) { return null; }`, placed **before** any shape match.
- `:173` — `ARRAY\[([^\]]*)\]\s*$` (was `(.*)`), null-guard-first form.
- `:189` — `ARRAY\[([^\]]*)\] OR (\w+) IS NULL\s*$` (was `(.*)`), null-guard-last form.
- `:214-243` — new `private static function containsUnquotedAnd(string $flat): bool`, reusing `consumeLiteral()` to skip quoted bodies.

`apps/api/tests/Architecture/EnumCheckParityDetectorLivenessTest.php`
- `:645-690` — six new `constraintDefinitionProvider()` cases (four AND shapes, the NOT VALID + AND combination, and the "literal containing AND must still parse" counterweight).
- `:741` — probe table gains `and_status`, `and_origin`.
- `:752-758` — the planted `CHECK (and_status IN ('a','b') AND and_origin IN ('c','d'))`.
- `:780-810` — assertions: neither column is read as a value set, **and** the analyzer verdict for `and_status` is `MISSING`, not `COVERED`.

`apps/api/tests/Architecture/EnumCheckParityTest.php`
- `:63-84` — class docblock: the "no anti-growth ceiling yet" disclosure was **false after this change** and is replaced with the ceiling's description + the R2-2 rationale.
- `:106-139` — `PROTECTED_SEED_ENV`, `MIRROR_YAML_RELATIVE`, `MIRROR_FIELD`, `PIN_TAG_FIELD`, `PROTECTED_ARTIFACTS` (three repo-relative paths), `NAMED_ACKNOWLEDGEMENTS`.
- `:588-698` — `the_parity_artifacts_never_grow_against_the_owner_pinned_seed()`.
- `:702-735` — `the_named_acknowledgements_still_resolve()` (the cheap half, armed while the variable is unset).
- `:737-813` — helpers `acknowledgementIndex()`, `decodeKeyList()`, `mirrorPin()`, `gitCatFile()`, `repositoryRoot()`.

`docs/handoff/progress/slice-d-parity.progress.yaml` — NEW mirror: `enum_check_parity_seed_commit`, `enum_check_parity_pin_tag: ci-pin/enum-check-parity-r1`, a `pin_history` line, and the four named re-pin triggers.

## 4. Migration

**No.** No migration, no DDL, no schema change. Nothing MIGRATION-BEARING, no census query owed.

## 5. §3 — CI wiring, REPORT ONLY (not applied; S-14 dispatch leg)

One correction to the brief's anchors: the DPA pin-tag fetch (`:289-305`) and zero-selection guard (`:311-315`) live in job **`backend-dpa-guard`** (starts `:236`), not `backend-architecture` (`:143`). The clone source is right; the job name in the brief is not.

Because the O-31 pin test lives in `EnumCheckParityTest` (PG-only class), the **parity pin-tag fetch belongs in `treasury-spine-pgsql`**, not in `backend-architecture`.

```diff
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -228,6 +228,17 @@ jobs:
         run: ./vendor/bin/phpunit tests/Architecture/OrphanedEventRatchetTest.php tests/Architecture/ProjectorEmissionRatchetTest.php
 
+      - name: enum<->CHECK parity detector liveness (driver-free arm)
+        # Conv. 08 liveness for the slice-D parity ratchet. 46 of its 47 cases are
+        # pure-function tamper tests that need no database — only the planted
+        # pg_constraint arm is PG-only and self-skips here. The PG arm of the gate
+        # itself runs in treasury-spine-pgsql; this step is what keeps the PARSER
+        # honest on every event, including PR->dev.
+        run: |
+          set -o pipefail
+          ./vendor/bin/phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php | tee parity-liveness.out
+          grep -qE 'OK \([1-9][0-9]* test' parity-liveness.out || {
+            echo "::error::the parity liveness guard selected zero tests — phpunit exits 0 on an empty selection (phpunit.xml sets no failOnEmptyTestSuite), so this is asserted explicitly";
+            exit 1; }
+
       - name: Run Deptrac architecture ratchet
         # Static-analysis-only gate (~3-4 s). Hard-fails on hexagonal domain
@@ -1172,7 +1183,12 @@ jobs:
     steps:
-      - uses: actions/checkout@v5
+      - uses: actions/checkout@v5
+        with:
+          # Full history: the enum<->CHECK anti-growth ceiling resolves THREE
+          # artifacts out of the object store at an owner-pinned seed COMMIT, and
+          # fetches the durable pin tag. Same reason backend-dpa-guard pins it.
+          fetch-depth: 0
 
       - name: Setup PHP
         uses: shivammathur/setup-php@v2
@@ -1229,6 +1245,44 @@ jobs:
       - name: PG-only invariants — Accounting Feature suite
         run: ./vendor/bin/phpunit tests/Feature/Accounting
 
+      # The two steps below are SEQUENTIAL phpunit invocations in this same
+      # runner, deliberately — see the "SERIAL EXECUTION IS LOAD-BEARING" note at
+      # the top of this step list (ci.yml:1213). EnumCheckParityTest is a
+      # RefreshDatabase pgsql suite; racing it against the Treasury/Accounting
+      # suites on autoerp_treasury_test corrupts the schema mid-run and throws
+      # fake 2BP01 errors. Do NOT matrix or parallelise.
+      - name: Fetch the durable enum<->CHECK parity pin tag
+        working-directory: .
+        run: |
+          # Same shape as the DPA pin-tag fetch (ci.yml:289-305): awk drops any
+          # trailing `# …` comment, tr is defensive against a quoted YAML value.
+          PIN_TAG="$(sed -n 's/^enum_check_parity_pin_tag:[[:space:]]*//p' docs/handoff/progress/slice-d-parity.progress.yaml | head -1 | awk '{print $1}' | tr -d "\"'")"
+          if [ -z "$PIN_TAG" ] || [ "$PIN_TAG" = "null" ]; then
+            echo "::error::enum_check_parity_pin_tag is not recorded in docs/handoff/progress/slice-d-parity.progress.yaml — the owner-pinned seed commit would have no durable ref. Failing closed."
+            exit 1
+          fi
+          echo "Fetching pin tag: $PIN_TAG"
+          git fetch --no-tags origin "refs/tags/${PIN_TAG}:refs/tags/${PIN_TAG}"
+
+      - name: enum<->CHECK parity gate (ratchet + anti-growth ceiling)
+        env:
+          # OWNER-SET repository variable — the anti-growth ceiling over all THREE
+          # parity artifacts (tenant baseline, central baseline, acknowledgements),
+          # deliberately outside every candidate diff. A seed COMMIT, not a blob, so
+          # one variable + one tag cover all three. Unset => the gate fails closed,
+          # which is the designed forcing function for the owner bootstrap (O-31).
+          ENUM_CHECK_PARITY_PROTECTED_SEED: ${{ vars.ENUM_CHECK_PARITY_PROTECTED_SEED }}
+        run: |
+          set -o pipefail
+          ./vendor/bin/phpunit tests/Architecture/EnumCheckParityTest.php | tee parity.out
+          grep -qE 'OK \([1-9][0-9]* test' parity.out || {
+            echo "::error::the parity gate selected zero tests, or self-skipped — this class is PG-only and MUST execute here"; exit 1; }
+
+      - name: enum<->CHECK parity detector liveness (PG plant arm)
+        run: |
+          set -o pipefail
+          ./vendor/bin/phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php | tee parity-liveness-pg.out
+          grep -qE 'OK \([1-9][0-9]* test' parity-liveness-pg.out || {
+            echo "::error::the parity liveness guard selected zero tests"; exit 1; }
+
       - name: PG-only invariants — Treasury Unit suite
         run: ./vendor/bin/phpunit tests/Unit/Treasury
```

Notes for whoever applies it: the zero-selection `grep` on the parity step doubles as a **skip detector** — the class self-skips on a non-pgsql driver and PHPUnit then prints `OK, but some tests were skipped`, which the pattern rejects. `treasury-spine-pgsql`'s `if:` covers PR→dev, PR→main and `workflow_dispatch`, so the gate protects the day-to-day merge path.

## 6. Seed-commit candidate (O-31 owner sheet)

**`da5ae13792e2a5067edde96f85858d2ea37efccf`** (`da5ae1379`) — the earliest commit at which all three artifacts carry their current blobs (it is the commit that last moved any of them: the 189→188 shrink for `documents.status`).

Blobs, re-derived at my base with `git rev-parse <commit>:<path>` — **identical at `da5ae1379`, at my base `dabf8fbde`, and at current local `dev` `7cd024946`**:

| artifact | blob |
|---|---|
| `enum-check-parity-baseline.json` | `47e77720a11e9d9e5acd1143a203a78a6c38141b` |
| `enum-check-parity-central-baseline.json` | `d3623927c3257630801c9559d5577aacfdd7308e` |
| `enum-check-parity-acknowledgements.json` | `3df370e19e44544f9df36160cf2bacc9da2758e5` |

Owner actions, both required, together: set repository variable `ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf`, and `git tag ci-pin/enum-check-parity-r1 da5ae1379 && git push origin ci-pin/enum-check-parity-r1`. The tag name is pre-allocated in the YAML but **not created** — I never push. Local recipe: `export ENUM_CHECK_PARITY_PROTECTED_SEED=$(git rev-parse da5ae1379)`.

## 7. `git status` proof

```
$ git status --porcelain apps/api/tests/Architecture/baselines/
(empty)
$ git status --porcelain
(empty — all four files committed in cf411e1a5)
```

Committed set: `PgValueSetCheckReader.php`, `EnumCheckParityTest.php`, `EnumCheckParityDetectorLivenessTest.php`, `docs/handoff/progress/slice-d-parity.progress.yaml`. Throwaway DB `autoerp_c26_test` created on PG 5433 and **dropped** at the end.

## 8. Residuals / adjacent defects seen, NOT touched

1. **The `]`-in-a-literal cost of the second brace.** `ARRAY\[([^\]]*)\]` means a value set one of whose literals contains `]` now returns `null` → MISSING. That is the safe direction and no such literal exists in this schema (the whole 75-column read is unchanged, `OK 9/407`), but it is a real behaviour narrowing that the brief mandated as belt-and-braces alongside the `AND` scan. The `AND` scan alone would have sufficed for R2-1; both are in, as instructed.
2. **R2-3 is now larger, not smaller — and I deliberately did not fix it.** Both new tests (`the_parity_artifacts_never_grow_…`, `the_named_acknowledgements_still_resolve`) are driver-free but sit behind `EnumCheckParityTest::setUp()`'s class-wide pgsql skip: `phpunit tests/Architecture/EnumCheckParityTest.php --display-skipped` on sqlite now reports **11 tests, 0 assertions, 11 skipped**. So the fail-closed ceiling arms only where the PG arm runs (`treasury-spine-pgsql` per §5, which does cover PR→dev). Moving the driver skip into the methods that need the catalogue is R2-3's own fix and belongs to the residual batch; the brief's §3 wiring assumes the class stays PG-only, so changing it here would have contradicted the CI leg.
3. **`not_validated` is still ungated** (R2-5, unchanged): the verdict key does not carry it, so a validated CHECK silently re-created `NOT VALID` reads identical. The parser change does not touch this.
4. **Unchanged from the gate's residual list, all out of scope for a test-only lane:** R2-4 (unobservable model-construction skip), R2-6 (stale `@return` docblock on the provider — I added `not_validated` to every new row but did not correct the declaration), F-9 production half (`FiscalEventQuarantine` still does not cast `payload_parse_status`), T-5 (`super_admins.role`), T-6/T-7 (the `RefreshDatabase`-cannot-host-a-tamper recipe still is not written next to the writer).
5. **`feature-lane-manifest.json` untouched** — the checker did not demand it (76/431, unchanged); no new test files were created.