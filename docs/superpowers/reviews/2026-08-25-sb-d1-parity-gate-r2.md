# Session B · lane D-1 — `pg_constraint` enum↔CHECK parity gate — COMBINED gate, ROUND 2

- **Lane / branch:** `fix/sb-d1-pg-constraint-parity-test` · worktree `.worktrees/sb-d1-check-parity`
- **r1 target:** `70adfcb2e` · **fix round under review:** `33e26cd69` — *test(architecture): enum<->CHECK parity fix round — dual gate r1 (Session B lane D-1)*
- **Diff:** `git diff 70adfcb2e..33e26cd69` = **12 files, +2142/−420**. `git diff --name-only … | grep -cv '^apps/api/tests/Architecture/'` = **0** — zero files outside `apps/api/tests/Architecture/**`. No production code, no migration, no `.github/**`. `git status --porcelain` clean at review end; I modified no file in the worktree.
- **Reviewer:** fiscal-pos-reviewer, running the COMBINED r2 gate (re-verifying BOTH r1 lenses: fiscal F-1..F-9 + tenancy T-1..T-7).
- **Records re-verified against:** `docs/superpowers/reviews/2026-08-25-sb-d1-parity-gate-r1-fiscal.md`, `…-r1-tenancy.md`, fix brief `docs/sessions/session-B-2026-08-23/BRIEF-D1-fixround.md`.
- **Evidence DB:** my own throwaway `autoerp_gate_d1r2_test` on PG 5433, created for this review, migrated with `APP_ENV=testing php artisan migrate --force`, **dropped at the end**.
- **Method:** every disposition below is a **LIVE TAMPER on the migrated schema**, driven from a read-only script (phpunit cannot host a tamper — `RefreshDatabase` re-runs `migrate:fresh` and wipes it, r1 T-7). Nothing here is asserted from reading.

## Verdict

**VERDICT: spec ✅ + quality APPROVED-with-residuals (combined). Merge is defensible; two Important residuals must go on the LEDGER as preconditions for the first slice-D CHECK-adding batch.**

All seven re-verified conditions (F-1, F-2, F-3, F-4, T-1, T-2, T-3) hold under live tamper. The two blind spots
r1 proved (`NOT VALID` invisible; central CHECK drop invisible) are **closed and re-proven RED→GREEN on the live
catalogue**. The counts reconcile exactly with the implementer's claims — I counted the keys myself. The new
parser moves **ZERO live verdicts**: parsing all 75 constrained columns with the r1 reader and the r2 reader
side-by-side on the same connection yields identical column sets and identical accepted-sets.

Two new findings. Neither is introduced by this diff, but both are load-bearing for what this gate is FOR:
one **false COVERED is reachable and I reproduced it live on `payments.status`** (R2-1, a pre-existing greedy-regex
defect the fix round's hardened-tokenizer claim does not cover), and the `INTENDED_NARROWER` acknowledgement —
the sole guard on the fiscal ledger/quarantine partition — is **deletable for free in the same diff that widens
the CHECK** (R2-2, proven).

## Per-condition disposition — every row is a live tamper result

| # | Condition (from r1) | Tamper performed on `autoerp_gate_d1r2_test` | Result | Disposition |
|---|---|---|---|---|
| **F-1** | quarantine row must be an INTENDED partition, pinned to the predicate — not baselined debt | `ALTER TABLE fiscal_event_quarantine DROP/ADD … CHECK (integrity_exception_class IN (all 6))` | main gate `NEW=0 STALE=0` (verdict flips NARROWER→COVERED, 54→55) **but** `ACK PROBLEMS=1`: *"the CHECK no longer admits exactly the acknowledged set (acknowledged [malformed_envelope, sequence_conflict], live [all 6]) … If the CHECK was WIDENED, that is the partition being destroyed, not a gap being closed."* | **CLOSED** ✅ |
| **F-1b** | pin must move if the PREDICATE's case set changes | Mechanism read, not app/ mutated: `EnumCheckParityAcknowledgements::casesWherePredicate()` (`Support/EnumCheckParityAcknowledgements.php:116-136`) reflects `IntegrityExceptionClass::isAdmissibleToLedger()` at runtime; `problems()` `:178-184` fails when `predicate_cases !== intended_set`, `:165-170` fails when `enum_cases !== enum_cases_at_acknowledgement` (both pinned in the JSON at `baselines/enum-check-parity-acknowledgements.json:10-22`), `:121-123` throws if the method is renamed/removed | a 7th case, a predicate flip, or a rename all break the claim | **CLOSED** ✅ |
| **F-2** | `NOT VALID` must be READ, never absent | `ALTER TABLE vouchers ADD CONSTRAINT vouchers_status_probe CHECK (status IN ('issued','partially_redeemed','fully_redeemed','expired')) NOT VALID` | **`NEW=1 [vouchers.status::NARROWER]` + `STALE=1 [vouchers.status::MISSING]`** — r1 row 7 was `NEW=0 STALE=0` | **CLOSED** ✅ (RED→GREEN) |
| **F-3** | paren-literal liveness case must be real | `the_pg_constraint_reader_actually_reads_a_planted_check()` plants a REAL `CHECK (label IN ('a(b)','z'))` (`EnumCheckParityDetectorLivenessTest.php:709`) and asserts `['a(b)','z']` verbatim (`:729`); pure-function provider cases at `:619-626` (paren + whitespace-run). Passed on PG in my run. Tokenizer is genuinely quote-aware (`PgValueSetCheckReader.php:245-320`) | real, not decorative; the false docblock sentence is gone | **CLOSED** ✅ |
| **F-4** | `pos_receipts.receipt_type` composite must be pinned, both directions | (a) `ALTER TABLE pos_receipts DROP CONSTRAINT pos_receipts_return_logic` → `ACK PROBLEMS=1`: *"the pinning constraint … no longer exists … delete the acknowledgement so it returns to the ratchet as MISSING"*. (b) same drop **plus** the ack entry deleted → **`TENANT NEW=1 [pos_receipts.receipt_type::MISSING]`** (the key was removed from the baseline, verified: `grep -c pos_receipts.receipt_type baseline.json` = **0**) | fail-closed **both** ways — the ack is not deletable for free | **CLOSED** ✅ |
| **T-1** | `products.enrichment_status` in the population and baselined; exclusions named | Probe on live schema: population contains `products.enrichment_status` → `App\Shared\Enums\EnrichmentStatus`; `enum-check-parity-baseline.json:130` = `"products.enrichment_status::MISSING"`; `excludedEnumColumns()` returns **exactly one** row: `tenants.vertical / App\Enums\Vertical / app/Enums/Vertical.php / central`. `INCLUDED_ENUM_PATHS` = `Domain/Enums` + `Shared/Enums` (`EnumBackedColumnRegistry.php:143-146`) | denominator 244→245, baseline is 189 | **CLOSED** ✅ |
| **T-2** | reason corrected + disjointness asserted with a real pin | Reason corrected at `EnumCheckParityTest.php:44-54`, `MigrationTableScopeMap.php:12-27`, `write-enum-check-parity-baseline.php:140` (register line 32-35). `no_central_migration_mutates_a_tenant_scoped_table()` exists (`EnumCheckParityTest.php:466-481`); live `centralMutationsOfTenantTables()` = `[]`. **Mutation test:** I copied the class, neutered `centralMutationsOfTenantTables()` to `return []`, and re-ran the liveness fixture (`EnumCheckParityDetectorLivenessTest.php:537-569`, synthetic tree, BOTH idioms `Schema::table` + raw `ALTER TABLE`) → mutant returns `[]` where the pin asserts `['pos_receipts ← …']` ⇒ **the pin would go RED**. Not vacuous | | **CLOSED** ✅ |
| **T-3** | central CHECK regressions must be caught | `ALTER TABLE impersonation_grants DROP CONSTRAINT impersonation_grants_status_check` | **`CENTRAL NEW=1 [impersonation_grants.status::MISSING]`** (central verdicts 9→8 COVERED) — r1 row 3 was `NEW=0 STALE=0` | **CLOSED** ✅ (RED→GREEN) |

All tampers were reverted from the captured `pg_get_constraintdef()` text and the final probe re-read
`TENANT NEW=0 STALE=0 · CENTRAL NEW=0 STALE=0 · ACK PROBLEMS=0`.

## The false-COVERED hunt — old parser vs new parser, on the same live catalogue

The one question that decides whether the new parser can be trusted. I extracted `PgValueSetCheckReader` at
`70adfcb2e`, renamed the class, and ran **both readers against the same connection**:

```
new parsed=75   old parsed=75
ONLY-IN-NEW (0): []
ONLY-IN-OLD (0): []
CHANGED-SET (0): []
```

**The COVERED set is byte-identical between `70adfcb2e` and `33e26cd69`.** The `NOT VALID` strip, the
quote-aware tokenizer and the OR-chain branch introduce **no new COVERED verdict and change no accepted-set on
this schema**. The implementer's "parser changes move ZERO live verdicts" claim is confirmed empirically, not
taken on trust.

I then fed eight adversarial shapes through both parsers (paren literals, `NOT` negation, cross-column
`AND`-tails, two-column OR-chains, a literal whose value IS the string `'NOT VALID'`). Seven behave identically
and safely in both. The eighth is R2-1.

## Findings (new in r2)

### [Important] R2-1 — a FALSE COVERED is reachable through the greedy ARRAY-body regex, and I reproduced it LIVE on `payments.status`
`apps/api/tests/Architecture/Support/PgValueSetCheckReader.php:145` and `:161` — both ARRAY regexes capture the
bracket body with a greedy `(.*)` anchored on `\]\s*$`, so the capture happily spans an intervening
`] … ARRAY[`. A cross-column `AND` of two value sets is therefore parsed as ONE column admitting the UNION of both.

**Proven live**, on a real tenant table in the fiscal/treasury spine:

```sql
ALTER TABLE payments ADD CONSTRAINT payments_and_probe
  CHECK (status IN ('pending','completed') AND origin IN ('failed','reversed'));
```
```
TENANT NEW=0 []
TENANT STALE=1 ["payments.status::MISSING"]
verdicts={"COVERED":55, … "MISSING":188}
```

`payments.status` is reported **COVERED**. In reality that CHECK restricts `status` to `{pending, completed}`
only — a `failed` or `reversed` payment is **rejected by PostgreSQL**, i.e. a live NARROWER write bomb on the
payments table — while the gate reads the union `{pending, completed, failed, reversed}` = exactly
`Treasury\PaymentStatus`'s four cases and calls it covered. The only signal is `STALE=1`, and the burn-down
batch is *instructed by the failure message* to close a STALE entry by deleting it. The batch would land green
with a live write bomb. This is the one failure mode that makes the whole gate lie.

**Pre-existing, not a regression** — the r1 parser produces the identical mis-parse (verified side-by-side:
`AND-of-two-array-sets → NEW={"column":"a","values":["x","y"]} / OLD={"column":"a","values":["x","y"]}`), and no
constraint of this shape exists on today's schema, so no live verdict is wrong right now. **Why it still blocks
the burn-down:** this diff's whole premise is a hardened parser, and `parseValueSet()`'s own docblock at
`:124` — *"Returns null when the definition is not a single-column value-set CHECK"* — is **false for this
shape**. That is the same claim-vs-behaviour defect class r1 filed as F-3 and this round claimed to close; it
was closed for the paren case and left open here. Slice-D batches will be hand-writing CHECKs on tables that
already carry cross-column invariants, which is precisely where this shape appears.
**Fix (small, in the next touch of this file):** forbid a `]` inside the captured body — e.g.
`ARRAY\[([^\]]*)\]\s*$` — or reject any `$flat` containing ` AND ` before matching; and add
`'an AND of two value sets is NOT a single-column value set'` (expecting `null`) to
`EnumCheckParityDetectorLivenessTest::constraintDefinitionProvider()`, plus a planted arm in
`the_pg_constraint_reader_actually_reads_a_planted_check()`.

### [Important] R2-2 — the `INTENDED_NARROWER` acknowledgement is deletable FOR FREE by the same diff that destroys the partition
`apps/api/tests/Architecture/EnumCheckParityTest.php:303-304` guards only the file being **emptied**
(`assertNotSame([], $entries)`). There is no named-entry rot guard, and `EnumCheckParityAnalyzer::finding()`
(`Support/EnumCheckParityAnalyzer.php:211-215`) relabels on the RAW key, so removing an entry simply restores the
raw verdict.

**Proven live.** With `fiscal_event_quarantine_class_phase1_allowed` widened to all six cases AND the
quarantine entry removed from the acknowledgements file (1 entry left, so the not-empty assertion still passes):

```
TENANT NEW=0 []   TENANT STALE=0 []   CENTRAL NEW=0 []   ACK PROBLEMS=0
verdicts={"COVERED":55,"COVERED_BY_COMPOSITE":1,"MISSING":189}
```

**Fully green.** The widened CHECK reads COVERED, needs no baseline key, and the acknowledgement that carried
the *only* machine-readable statement of the ledger/quarantine partition is gone from the record.
**Why it matters (fiscal):** this is the exact move r1's F-1 was written to prevent — a future lane "closing the
NARROWER row" by widening the CHECK, which lets a `sequence_gap` / `canonical_hash_mismatch` be written into the
table reserved for classes that may never reach the ledger (`IntegrityExceptionClass::isAdmissibleToLedger()`,
`app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php:26-29`). The acknowledgement mechanism is correct and
fires whenever the entry is KEPT; it just is not pinned against its own deletion.
Note the asymmetry, which I also proved: `COVERED_BY_COMPOSITE` is **not** free to delete — dropping
`pos_receipts_return_logic` plus deleting its entry yields `NEW=1`, because its raw verdict (`MISSING`) is a
failure and the key is no longer in the baseline. Only `INTENDED_NARROWER` has the escape hatch, because widening
produces a PASSING raw verdict.
**Fix:** cheapest in-lane option — assert by name that the two acknowledged keys are present (same shape as
`UNGATEABLE_COLUMNS`' rot guard, `the_ungateable_supplement_still_resolves()`). Stronger and preferred — fold
`enum-check-parity-acknowledgements.json` into the **T-4 owner-pinned blob** alongside the baseline, so neither
artifact can shrink without the owner-set repository variable moving. **T-4's ruling should be amended to name
all three baseline artifacts, not just `enum-check-parity-baseline.json`.**

### [Minor] R2-3 — the one PURE assertion in the parity class is gated behind the class-wide pgsql skip, so it never runs where CI runs
`EnumCheckParityTest::setUp()` (`:146-157`) skips the whole class on any non-pgsql driver, and
`no_central_migration_mutates_a_tenant_scoped_table()` (`:466-481`) needs no database at all — it reads only
`MigrationTableScopeMap`. Confirmed: on sqlite the class reports **9 skipped**, including that one. CI's
`backend-test` lane is sqlite (r1 CI statement, `ci.yml:490`/`:477`), so the T-2 disjointness property — the thing
that keeps the union honest — is unguarded everywhere except a hand-run PG invocation. The synthetic-fixture
liveness pin DOES run on sqlite, but it pins the *detector*, not the *live migration tree*.
**Fix:** move the driver skip into the methods that need the catalogue, or move this one assertion into
`EnumCheckParityDetectorLivenessTest` (which has no class-level skip).

### [Minor] R2-4 — r1's F-6 was closed by deleting the counter rather than asserting it empty; the silent skip remains unobservable
`Support/EnumBackedColumnRegistry.php:163-174` — a model that cannot be constructed with no arguments is now
`continue`d with a comment saying the counter "was never called by anything". True, and the false docblock claim
is gone (r1's F-6 offered exactly this as the acceptable option). But the hole itself is untouched: a model that
becomes unconstructable silently drops its enum columns from the population and **nothing can observe it** now
that the counter is deleted. Set is empty today. Prefer restoring the method and asserting `=== []`.

### [Minor] R2-5 — `not_validated` is parsed, carried, rendered, and never gated
`Support/EnumCheckParityAnalyzer.php:126,230` carries the flag into every finding and the register renders
`NOT VALID` (`write-enum-check-parity-baseline.php:204-206`), but the verdict KEY does not include it. A validated
CHECK silently re-created as `NOT VALID` produces an identical key and the gate stays green — the column's
guarantee over EXISTING rows quietly drops to nothing. Arguably correct (a `NOT VALID` CHECK still guards every
new write, which is the r2 docblock's own reasoning at `PgValueSetCheckReader.php:35-41`), but the asymmetry
should be a deliberate, recorded choice. At minimum the slice-D batches must be required to land
`VALIDATE CONSTRAINT` in a *tracked* second step, because this gate cannot tell them apart.

### [Minor] R2-6 — stale `@return` docblock on the parser provider
`EnumCheckParityDetectorLivenessTest.php:574` still declares
`array{column: string, values: list<string>, nullable: bool}|null` — `not_validated` is missing, though every
data row now carries it. Cosmetic; same family as the r1 hygiene batch.

## Gate verified — per file, per driver, all run BY PATH (never the full suite)

| Check | Driver / DB | Result |
|---|---|---|
| `phpunit tests/Architecture/EnumCheckParityTest.php` | pgsql `autoerp_gate_d1r2_test` | **OK — 9 tests, 409 assertions**, 10.5 s (matches claim 9/409) |
| `phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php` | pgsql | **OK — 41 tests, 93 assertions**, 3.7 s (matches claim 41/93) |
| `phpunit tests/Architecture/EnumCheckParityTest.php --display-skipped` | sqlite | **9 skipped**, message names the driver and the exact pgsql re-run line (`EnumCheckParityTest.php:152-155`) |
| `phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php --display-skipped` | sqlite | **41 tests, 80 assertions, 1 skipped** ⇒ **40 run** (matches claim "40 run on sqlite"); the skip is the planted-CHECK arm only |
| `./vendor/bin/pint --test tests/Architecture` | — | `{"result":"pass"}` |
| `phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` | sqlite | **OK — 76 tests, 431 assertions, EXIT=0** (unchanged from r1; Architecture is not a ceiling group) |
| PHPStan | — | **N/A confirmed** (`phpstan.neon` analyses `app/` only) |
| Writer name guard | — | unchanged from r1 (`write-enum-check-parity-baseline.php:55-58`, `^autoerp_[a-z0-9_]*test$` checked before any catalogue read); my DB name satisfies it |
| Live tamper battery | pgsql | 7/7 conditions fire as specified (table above); schema fully restored, final probe `0/0/0/0`, `ACK PROBLEMS=0` |
| Old-vs-new parser diff | pgsql | 75 = 75 columns, **0 only-in-new, 0 only-in-old, 0 changed accepted-sets** |
| Worktree scope | — | 12 files, **all** under `apps/api/tests/Architecture/**`; `git status --porcelain` clean |

## Final counts — reconciled against the generated artifacts (I counted the keys myself)

`php -r` over the committed JSON, plus an independent run of the real registry + real
`PgValueSetCheckReader` + real `EnumCheckParityAnalyzer` against my migrated DB:

| Scope | Columns | COVERED | MISSING | INTENDED_NARROWER | COVERED_BY_COMPOSITE | Baseline keys (file) | Baseline unique |
|---|---:|---:|---:|---:|---:|---:|---:|
| **tenant (ASSERTED)** | **245** | 54 | 189 | 1 | 1 | **189** | 189 |
| **central (ASSERTED, T-3)** | **20** | 9 | 11 | 0 | 0 | **11** | 11 |
| total registry | **265** | 63 | 200 | 1 | 1 | 200 | 200 |

- 54 + 189 + 1 + 1 = **245** ✅ · 9 + 11 = **20** ✅ · 245 + 20 = **265** = `derive()` total ✅
- Every implementer claim reconciles exactly: 245 / 54 / 189 / 1 / 1 → baseline 189; central 9 COVERED + 11 MISSING → baseline 11.
- **Movement vs r1:** denominator 244→**245** (T-1 pulls in `products.enrichment_status`); baseline 190→**189**
  (the `::NARROWER` key left via `INTENDED_NARROWER`, and `pos_receipts.receipt_type::MISSING` left via
  `COVERED_BY_COMPOSITE` — offset by the one new T-1 MISSING key). Central baseline **11**, newly asserted.
  The brief predicted 191/188; the delivered 189 is the arithmetically correct number and the deviation is
  explained by both acknowledged rows leaving the baseline, not by absorption.
- `centralMutationsOfTenantTables()` = `[]`; `excludedEnumColumns()` = exactly one row (`tenants.vertical`);
  `collidingTables()` = `[]`; SCOPE_UNKNOWN = `[]`.
- Register header label corrected (r1 F-7): `register.md:7` now reads *"274 tables declared across BOTH migration
  trees (32 central + 242 tenant)"*.
- r1 F-1's blanket *"a CHECK narrower than its enum is a write bomb, not debt"* sentence is **gone**; the only
  surviving "write bomb" phrase (`register.md:44`) correctly describes the FUTURE `ReceiptType`-grows risk inside
  the `COVERED_BY_COMPOSITE` reason.
- r1 F-9 / T-5 are now a first-class rot-guarded section: `register.md:319-321` names `bank_reconciliations.status`,
  `fiscal_event_quarantine.payload_parse_status`, `super_admins.role`, guarded by
  `the_ungateable_supplement_still_resolves()` (proven RED-capable in all four rot directions at
  `EnumCheckParityDetectorLivenessTest.php:500-526`).

## Residuals for the LEDGER

1. **R2-1 · the greedy-ARRAY-body false COVERED** (`PgValueSetCheckReader.php:145,161`). Pre-existing, no live
   instance today, **reproduced live on `payments.status`**. Must be fixed **before the first CHECK-adding batch**
   touches a table that already carries a cross-column invariant. One-line regex change + two liveness cases.
2. **R2-2 · pin the acknowledgements file.** Amend the **T-4** ruling to cover all THREE artifacts —
   `enum-check-parity-baseline.json`, `enum-check-parity-central-baseline.json`, **and**
   `enum-check-parity-acknowledgements.json` — or add a named-entry rot guard now. Today, widen-the-CHECK +
   delete-the-entry in one diff is fully green.
3. **T-4 · arm the owner-pinned protected blob** (unchanged from r1, still owed, still a precondition for slice D).
   New seed blob: `33e26cd69:apps/api/tests/Architecture/baselines/enum-check-parity-baseline.json`.
   No anti-growth ceiling exists yet — matched growth still passes and is disclosed honestly at
   `EnumCheckParityTest.php:63-75`. **The owner blob is where R2-2 should be solved.**
4. **CI wiring · STILL OWED and STILL UNVERIFIED.** `grep -rn "EnumCheckParity" .github/workflows/` returns
   **nothing** on this branch (S-14 — this test-only lane correctly touched no workflow file). Until it lands the
   gate protects nothing in CI. r1's recommendation stands and is now sharper: the **PG arm** must go to
   `treasury-spine-pgsql` (`ci.yml:1095`, `DB_DATABASE=autoerp_treasury_test`, which also satisfies the writer's
   name guard); the **pure-function arm** (`EnumCheckParityDetectorLivenessTest`, 40/41 cases run driver-free) to
   `backend-architecture` (`ci.yml:143`). Note R2-3: on the sqlite lane the parity class contributes **zero**
   assertions.
5. **F-9 production half · still owed.** `FiscalEventQuarantine` still does not cast `payload_parse_status`
   (`app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php:116` lists it as fillable only, no cast) while
   `OutboxIngestor` writes a `PayloadParseStatus->value` into it. Correctly out of scope for a test-only lane; the
   column is now disclosed by name in the un-gateable register section (`register.md:320`) instead of being
   invisible. Adding the cast is a one-line production change that pulls the column into the population for free.
6. **T-5 · `super_admins.role`** — no cast, no CHECK, central auth table. Disclosed at `register.md:321`;
   production change, own lane.
7. **R2-3 / R2-4 / R2-5 / R2-6** — skip-scope, unobservable model skip, ungated `not_validated`, stale docblock.
   Batch into the next touch of these files.
8. **T-6 / T-7** — both now disclosed in-file (`MigrationTableScopeMap.php:34-39` for `migrations/manual/`); the
   `RefreshDatabase`-cannot-host-a-tamper recipe is still not written down next to the writer, and both r1 and r2
   reviews had to reconstruct it. Worth 5 lines in the writer's docblock for the burn-down lanes.

## What to fix before merge

Nothing blocking in the code — all seven r1 conditions are closed under live tamper and the counts reconcile.
**Before merge:** add R2-1 and R2-2 to the LEDGER as hard preconditions for the first slice-D CHECK-adding batch,
and amend the T-4 ruling so the owner pin covers the acknowledgements file — otherwise the fiscal
ledger/quarantine partition's only machine-readable guard can be deleted by the same diff that destroys it.
