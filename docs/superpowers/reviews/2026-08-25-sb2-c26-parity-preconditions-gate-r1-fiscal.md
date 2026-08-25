# Session B2 · lane B2-2 / C-26 — slice-D parity preconditions (R2-1 parser + R2-2/O-31 anti-growth ceiling) — GATE r1, fiscal lens

**VERDICT: ACCEPT-WITH-CONDITIONS · merge-blocking: NO (for the branch) · BLOCKING for the S-14 ci.yml leg: YES (F-1).**

- **Lane / branch:** `fix/sb2-c26-parity-preconditions` @ `cf411e1a5` · worktree `.worktrees/sb2-c26-parity-preconditions` · base `dabf8fbde`
- **Diff:** `git diff dabf8fbde..cf411e1a5` = **4 files, +513/−12** — `PgValueSetCheckReader.php`, `EnumCheckParityDetectorLivenessTest.php`, `EnumCheckParityTest.php`, NEW `docs/handoff/progress/slice-d-parity.progress.yaml`. No production code, no migration, no `.github/**`, no baseline JSON. Test-only, as briefed.
- **Reviewer:** fiscal-pos-reviewer. **Method: execution, not reading.** Every disposition below is a live run on my own throwaway `autoerp_fiscalgate_test` (PG 5433), migrated with `APP_ENV=testing php artisan migrate --force`, **dropped at the end**. Tamper cases were applied to working copies and reverted with `git checkout --` in the SAME bash invocation; `git status --porcelain` on the worktree is **empty** at review end and I modified no branch file that I did not restore.
- **Records:** brief `docs/sessions/session-B-2026-08-23/BRIEF-C26-parity-preconditions.md`; implementer report `docs/sessions/session-B-2026-08-23/REPORT-C26-implementer.md`; prior gate `docs/superpowers/reviews/2026-08-25-sb-d1-parity-gate-r2.md` (§R2-1, §R2-2, T-4). LEDGER C-26, O-31.

**Fiscal-lens scope note.** Nothing in this diff touches a fiscal event, a projection, money or quantity math, a device contract or a queue. Its fiscal weight is entirely indirect and entirely real: the artifact it protects is the only machine-readable statement of the **fiscal ledger / quarantine partition** (`IntegrityExceptionClass::isAdmissibleToLedger()`), whose destruction would let a `sequence_gap` or a `canonical_hash_mismatch` be written into the table reserved for classes that may never reach the ledger.

---

## 1. R2-1 — is the false COVERED really closed? YES, proven live on a real tenant table

I re-ran the gate-r2 repro verbatim on the migrated schema:

```sql
ALTER TABLE payments ADD CONSTRAINT payments_and_probe
  CHECK (status IN ('pending','completed') AND origin IN ('failed','reversed'));
```

| reader | `payments.status` read | verdict |
|---|---|---|
| **pre-fix** (`PgValueSetCheckReader` extracted at `dabf8fbde`, class renamed, run on the same connection) | `{"accepted":["completed","failed","pending","reversed"],"constraints":["payments_and_probe"]}` — the UNION, i.e. exactly `PaymentStatus`'s four cases | **COVERED** (false) |
| **post-fix** (`cf411e1a5`) | `null` | **MISSING** ✅ |

Probe output with the fix in place and the constraint planted:
`TENANT NEW=[] · TENANT STALE=[] · verdicts {"COVERED":55,"COVERED_BY_COMPOSITE":1,"INTENDED_NARROWER":1,"MISSING":188}`.
Note there is now **no STALE signal either** — under the old parser the only symptom was `STALE=1 [payments.status::MISSING]`, and the failure message instructs a burn-down batch to close a STALE row by deleting it. The write bomb no longer produces a *deletion instruction*. **R2-1 CLOSED.**

**No live verdict moved.** Old reader vs new reader, same connection, clean schema:
`new=76 old=76 · ONLY-IN-NEW=[] · ONLY-IN-OLD=[] · CHANGED-SET=[]`.
Gate probe on the clean schema: `TENANT keys=188 / baselinefile=188 · NEW=[] · STALE=[] · CENTRAL keys=11 / baselinefile=11 · NEW=[] · STALE=[]`. **Tenant baseline 188 / central 11 confirmed by an independent run of the real registry + real reader + real analyzer**, not by reading the JSON. (76 parsed columns, not r2's 75: `documents.status` gained its CHECK in `2026_08_24_100100`, which is also the 189→188 shrink.)

**The quote-aware AND scan does not over-reject legitimate literals** — planted for real, not argued:

| planted CHECK | `parseValueSet` |
|---|---|
| `a IN ('salt AND pepper','z')` | `{"column":"a","values":["salt AND pepper","z"]}` ✅ |
| `d IN ('AND','OR')` | `{"column":"d","values":["AND","OR"]}` ✅ |
| `b IN ('x]y','z')` | `null` (disclosed narrowing, report residual 1) |
| `c IS NOT NULL AND c IN ('p','q')` | `null` — **see F-5** |

`containsUnquotedAnd()` (`PgValueSetCheckReader.php:224-243`) is sound at the string end: `substr_compare($flat,' AND ',$i,5)` past the end returns a value without notice on PHP 8.4 (checked directly).

The liveness pins are real, not decorative: the planted-AND arm asserts both `assertArrayNotHasKey('and_status', …)` **and** the analyzer verdict `MISSING` (`EnumCheckParityDetectorLivenessTest.php:780-810`), and the six new provider rows include the counterweight case that forces quote-awareness (`:645-690`).

---

## 2. R2-2 / O-31 — the anti-growth ceiling. Tamper matrix: 9 for 9 RED, armed GREEN

Every row below is an executed run against the migrated PG database. `git status --porcelain` was empty immediately after each revert.

| # | tamper | variable | result |
|---|---|---|---|
| 1 | none — **fail-closed probe** | **unset** | **RED** — `ENUM_CHECK_PARITY_PROTECTED_SEED is unset or empty … this gate FAILS CLOSED` (`EnumCheckParityTest.php:593`); `Tests: 11, Assertions: 409, Failures: 1` |
| 2 | none — **armed** | `da5ae1379…` | **GREEN — OK (11 tests, 829 assertions)** |
| 3 | tenant baseline +1 key | armed | **RED** — `tenant baseline GREW: tampered_table.tampered_column::MISSING` |
| 4 | central baseline +1 key | armed | **RED** — `central baseline GREW: tampered_central.tampered_column::MISSING` |
| 5 | quarantine acknowledgement **deleted** | armed | **RED ×2** — ceiling: `acknowledgement DROPPED: fiscal_event_quarantine.integrity_exception_class::NARROWER`; rot guard: `named in NAMED_ACKNOWLEDGEMENTS but GONE from …` |
| 6 | acknowledgement **relabelled** `INTENDED_NARROWER`→`COVERED_BY_COMPOSITE` | armed | **RED ×2** — `acknowledgement RELABELLED: …` + `acknowledged as COVERED_BY_COMPOSITE, expected INTENDED_NARROWER` |
| 7 | **predicate moved** (`expects` false→true) | armed | **RED** — `acknowledgement PREDICATE MOVED: … {"expects":false…} -> {"expects":true…}` |
| 8 | **mirror drift** (variable `dabf8fbde…`, mirror `da5ae1379…`) | armed-wrong | **RED** — `That is a TAMPER SIGNAL, not a skip condition` |
| 9 | **unreachable seed object** (mirror + variable both `000…001`) | armed-bogus | **RED** — `could not be read (git cat-file exit 128) … CI must fetch the durable pin tag … an unreachable object fails closed` |

Malformed-sha arm is code-verified (`assertMatchesRegularExpression('/^[0-9a-f]{40}$|^[0-9a-f]{64}$/', …)`, `EnumCheckParityTest.php:595-599`) and precedes the mirror check.

**The seed-commit design cannot be satisfied by a self-referential pin.** Three independent legs, all verified:
1. The authority is the env var, and the proposed CI mapping is `${{ vars.ENUM_CHECK_PARITY_PROTECTED_SEED }}` — a repository variable, unwritable from a PR, matching the DPA precedent at `.github/workflows/ci.yml:318`. A candidate diff cannot move it.
2. The seed is **`da5ae13792e2a5067edde96f85858d2ea37efccf`, not the candidate**: `git merge-base --is-ancestor da5ae1379 cf411e1a5` → true. Blobs re-derived myself and **identical at `da5ae1379`, `dabf8fbde` and `cf411e1a5`** — `47e77720a…` / `d3623927c…` / `3df370e19…`, exactly the report §6 table.
3. Moving the mirror alone is red (row 8); moving the working artifacts alone is red (rows 3-7). There is no in-diff move that satisfies both sides.

**Mirror wording matches the DPA precedent.** `docs/handoff/progress/slice-d-parity.progress.yaml:11-18` reproduces the `enforcement-p1.progress.yaml:53-56` formula (authority = owner-set repository variable; YAML = NON-AUTHORITATIVE mirror; mirror ≠ variable = FAIL, not skip), and `:52-57` mirrors `enforcement-p1.progress.yaml:93-94`'s `<name>_protected_*` + `<name>_pin_tag` pair. The pre-allocated ordinal is correct: `git tag -l 'ci-pin/*'` and `git ls-remote --tags origin 'refs/tags/ci-pin/*'` show only `enforcement-p1-r1/r2`, `p2-r1`, `p3-r1` — `ci-pin/enum-check-parity-r1` is free.

**The fiscal partition acknowledgement is now undeletable** — proven twice over (row 5), by the ceiling AND by the variable-independent rot guard that names `fiscal_event_quarantine.integrity_exception_class` and `pos_receipts.receipt_type` by hand (`EnumCheckParityTest.php:136-140`, `:702-735`).

### Ruling on R2-2: **CLOSED as scoped**, with one named residual (F-4)
Deletion, relabel, predicate flip and matched baseline growth are all closed and proven. What is **not** closed is acknowledgement **content** movement — see F-4. R2-2's own text ("deletable for free in the same diff that widens the CHECK") is discharged.

---

## 3. §5 CI-wiring report vs the LIVE `ci.yml` — one blocking defect and a set of anchor errors

Checked against `.github/workflows/ci.yml` on `dev` (`41d589faa`). **Reported, not applied.** See F-1, F-2, F-6, F-8.

Correct in the report: `backend-architecture` starts `:143` and has **no `if:`** (runs on every event that starts the workflow) ✅; `treasury-spine-pgsql` starts `:1109`, `if:` at `:1130` covers PR→dev / PR→main / `workflow_dispatch` ✅; `steps:`/`checkout` at `:1172-1173` ✅; the Accounting step is the right insertion point (`:1228-1229`) ✅; `DB_DATABASE=autoerp_treasury_test` at `:1168` satisfies the writer's `^autoerp_[a-z0-9_]*test$` name guard ✅; the report's own correction — the pin-tag fetch and zero-selection guard live in `backend-dpa-guard`, not `backend-architecture` — is right ✅; `working-directory: .` on the fetch step is required and present ✅; the parity gate step correctly inherits `apps/api` ✅.

**Skip-detector claim: TRUE for the PG arm.** Executed: on sqlite the class prints `OK, but some tests were skipped!` / `Tests: 11, Assertions: 0, Skipped: 11`, and `grep -qE 'OK \([1-9][0-9]* test'` **rejects** it. A self-skipped PG gate would fail the step, as claimed. The same property is what breaks the *other* new step — F-1.

---

## 4. Residual R2-3 (both new pin tests behind the class-wide PG skip) — RULING: acceptable, NOT merge-blocking, conditional

Verified: `EnumCheckParityTest::setUp()` `:194-206` skips the whole class on any non-pgsql driver; both new tests are entirely driver-free (they read files and shell out to `git`); on sqlite the class now reports **11 tests, 0 assertions, 11 skipped**. So the fail-closed ceiling arms **only** where the PG arm runs.

I rule this **acceptable for merge** because:
- The ceiling is **inert until the owner arms the variable** regardless of where it runs, so R2-3 does not change what C-26 delivers today.
- `grep -rn "EnumCheckParity" .github/workflows/` on the branch returns **nothing** — the gate is CI-dead either way until the S-14 leg lands. R2-3 is dominated by C-26(i), which is already an open LEDGER row.
- Fixing it inside this lane would contradict the brief's §3 wiring, which the parent asked for as a report.

**Condition:** it must be fixed, or explicitly re-ruled, in the same batch that applies the S-14 leg — and the better target is now visible: move the driver skip into the methods that need `pg_constraint` (R2-3's own fix) and put `the_parity_artifacts_never_grow_against_the_owner_pinned_seed()` in `backend-architecture`, which has **no `if:`** and therefore runs on strictly more events than `treasury-spine-pgsql`. That is the version of this ceiling that actually protects the promotion path.

---

## 5. Did the fix break anything? No. All gates re-run by path

| check | driver / DB | result |
|---|---|---|
| `phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php` | pgsql `autoerp_fiscalgate_test` | **OK (47 tests, 102 assertions)** — matches the claim |
| same | sqlite | `OK, but some tests were skipped!` **47 tests, 86 assertions, 1 skipped** (46 run; the skip is the PG plant arm only) |
| `phpunit tests/Architecture/EnumCheckParityTest.php` **armed** | pgsql | **OK (11 tests, 829 assertions)** — matches the claim |
| same, **unarmed** | pgsql | `Tests: 11, Assertions: 409, Failures: 1` — the fail-closed arm only (the report's 9/407 was measured before the pin tests existed; the 407→409 delta reconciles as the 2 extra assertions of the new tests before the ceiling's own failure) |
| same | sqlite | 11 skipped, 0 assertions (R2-3) |
| `./vendor/bin/pint --test tests/Architecture` | — | `{"result":"pass"}` |
| `phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` | sqlite | **OK (76 tests, 431 assertions)** — no manifest edit needed |
| PHPStan | — | **N/A confirmed** — `phpstan.neon:6-8` `paths: app/` |
| worktree scope | — | 4 files; `git status --porcelain` **empty** at review end |
| old-vs-new parser, clean schema | pgsql | 76 = 76, 0 only-in-new, 0 only-in-old, 0 changed accepted-sets |

---

## Findings

### [Critical — blocks the S-14 leg, not this merge] F-1 · REPORT §5 hunk 1 would turn `backend-architecture` RED on every CI event
`docs/sessions/session-B-2026-08-23/REPORT-C26-implementer.md:133-144` proposes the driver-free liveness step with the DPA zero-selection guard copied verbatim:
```
./vendor/bin/phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php | tee parity-liveness.out
grep -qE 'OK \([1-9][0-9]* test' parity-liveness.out || { echo "::error:: …"; exit 1; }
```
On sqlite — which is what `backend-architecture` runs — that class **self-skips exactly one case** (the planted `pg_constraint` arm) and PHPUnit therefore prints `OK, but some tests were skipped!`, never `OK (47 tests …)`. Executed: the grep **fails**, so the step `exit 1`s. The report states the very same sqlite output at its own §2 (`REPORT…md:47`) and then proposes a guard that rejects it. The DPA original works only because `DocumentPerActionWriteGuardTest` has no skips.
*Why it matters:* `backend-architecture` has no `if:` — it runs on every PR→dev, PR→main, push→main and dispatch. Applying §5 as written makes the whole pipeline red immediately and the "fix" a reviewer would reach for is deleting the guard.
*Fix:* on the driver-free arm assert a positive selection without demanding a skip-free run, e.g. `grep -qE '^(OK \([1-9][0-9]* test|OK, but some tests were skipped)' out && grep -qE '^Tests: [1-9][0-9]*,' out` (or pin the expected skip count). Keep the strict `OK (` pattern on the **PG** arm, where a self-skip must fail.

### [Important] F-2 · The §5 diff is not machine-appliable, and four anchors are off
`git apply --check` on the fenced diff extracted from `REPORT…md:127-206` → **`error: corrupt patch at line 21`**. Hunk 1 declares `@@ -228,6 +228,17 @@` but supplies **4** old lines; hunk 2 declares `-1172,7` and supplies **2**. Anchor drift against live `ci.yml`: `backend-dpa-guard` starts **:237** (report: `:236`); the DPA pin-tag fetch is **:289-303** (report: `:289-305`); the zero-selection guard is **:307-311** (report: `:311-315`); the SERIAL-EXECUTION note starts **:1214** (report: `:1213`); the Accounting step's `- name:` is **:1228** with `run:` at `:1229`. Placement *intent* is correct everywhere — but "the exact ci.yml hunk" it is not.
*Fix:* whoever runs the S-14 leg must hand-place the steps and re-derive the diff from the real file; do not pipe this into `git apply`.

### [Important] F-3 · Ordering hazard: arming the variable and the tag must PRECEDE the ci.yml leg, and the seed is not on `origin` yet
The gate is fail-closed by design (row 1) and the proposed pin-tag fetch step `exit 1`s when the tag is missing. So if the ci.yml leg lands before the owner (a) sets `vars.ENUM_CHECK_PARITY_PROTECTED_SEED` and (b) pushes `ci-pin/enum-check-parity-r1`, **`treasury-spine-pgsql` is RED on every PR**. Compounding it: `git merge-base --is-ancestor da5ae1379 origin/dev` → **false** — the seed commit exists only on local `dev`/this branch today, so the tag cannot be pushed until the batch is promoted. Neither the report nor the YAML states the sequence.
*Fix:* record the order explicitly on O-31 — **promote the batch → owner sets the variable AND pushes the tag (together) → only then apply the ci.yml leg**; and add that line to `slice-d-parity.progress.yaml`'s re-pin block.

### [Important] F-4 · R2-2 is closed for DELETION and RELABEL, but the ceiling is blind to acknowledgement CONTENT — proven
`EnumCheckParityTest.php:625-640` compares only `kind` and `predicate` between pinned and working entries. I rewrote, in the working acknowledgements file, `intended_set` (2 → 3 classes incl. `canonical_hash_mismatch`), `enum_cases_at_acknowledgement` (+1 fake case), and the composite entry's `pinned_set`/`constraint` — and both new tests stayed **GREEN (`OK (2 tests, 422 assertions)`)**. Only `acknowledgements_still_describe_the_live_schema()` catches those, and only by contradiction with the live DB/enum. Consequence, in fiscal terms: a diff that moves the partition *consistently* — edit `IntegrityExceptionClass::isAdmissibleToLedger()` (`app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php:26-29`), widen `fiscal_event_quarantine_class_phase1_allowed` to match, update `intended_set` — is **fully green under the new ceiling**, and that is the same end state R2-2 named: a `sequence_gap` becomes writable to the non-ledger-admissible quarantine table. The delete door is shut; the production-code door is open.
*Fix (small):* add `intended_set`, `enum_cases_at_acknowledgement`, `constraint` and `pinned_set` to the compared fields in direction (c), and add "the partition definition moved" as re-pin trigger 5 — an owner re-pin is exactly the reviewed authorisation such a change should need.

### [Minor] F-5 · The over-rejection is wider than the report discloses: a same-column `IS NOT NULL AND` value set now reads MISSING
Report residual 1 (`REPORT…md:237`) names only the `]`-in-a-literal cost. Live probe, planted for real: `CHECK (c IS NOT NULL AND c IN ('p','q'))` — PG renders `((c IS NOT NULL) AND ((c)::text = ANY (…)))` — now returns `null`, so the column reads **MISSING** even though it is a perfectly ordinary single-column value set. Safe direction, but a slice-D batch that writes that idiom can never close its baseline key, and the gate's message will claim the CHECK is absent.
*Fix:* name the shape in `PgValueSetCheckReader`'s docblock, add a provider row so the disposition is pinned rather than accidental, and add to the slice-D authoring rules: *never put `IS NOT NULL` inside a value-set CHECK — use a `NOT NULL` column constraint, or the `col IS NULL OR col IN (…)` form the parser does support.*

### [Minor] F-6 · "protects the day-to-day merge path" overstates what `treasury-spine-pgsql` covers
`REPORT…md:208`. `ci.yml:3-8` is `push: [main]` + `pull_request: [main, dev]` + `workflow_dispatch`, and the job's own comment (`ci.yml:1124-1128`, echoed by `backend-dpa-guard` at `:241-244`) says a **direct push to `dev` never starts the workflow at all**. This fleet merges into local `dev` and pushes — so on the actual promotion path the ceiling runs only at PR→main or a manual dispatch. Say so in the S-14 handback rather than implying PR→dev is the merge path.

### [Minor] F-7 · `enum_check_parity_pin_tag` is diff-controlled; harmless but worth a comment
The proposed fetch step reads the tag NAME from `slice-d-parity.progress.yaml`, which any candidate diff can edit. Repointing it cannot forge a pass — the ceiling still resolves `<owner-set seed>:<path>`, and an unfetched seed fails closed (row 9, `exit 128`). It is a DoS-only surface. One line in the YAML saying "the tag field is a convenience, not an authority" would close the reasoning gap for the next reader.

### [Minor] F-8 · Applying §5 makes a live comment false
`ci.yml:1219` reads "Three separate sequential steps in one runner"; §5 adds three more. Update the sentence in the same hunk.

---

## Conditions for merge (branch is otherwise sound)

1. **F-1 must be fixed before the S-14 ci.yml leg is dispatched** — as written, §5 hunk 1 reds the whole pipeline. Do not apply the fenced diff (F-2); hand-place and re-derive.
2. **Record the arming ORDER on O-31** (F-3): promote → owner sets `ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf` **and** pushes `ci-pin/enum-check-parity-r1` → then wire ci.yml. The seed is not on `origin` today.
3. **File F-4 on the LEDGER under C-26/O-31** as the remaining half of R2-2 — the ceiling should pin `intended_set` / `enum_cases_at_acknowledgement` / `constraint` / `pinned_set`, not just `kind` + `predicate`.
4. **R2-3 stays open with a target**: when the S-14 leg lands, move the driver skip into the methods that need `pg_constraint` and run the ceiling in `backend-architecture` (no `if:`).
5. F-5 into the slice-D authoring rules; F-6/F-7/F-8 into the S-14 handback text.

**What to fix before merge:** nothing in the branch — merge it; fix F-1 in the S-14 ci.yml leg before that leg is dispatched, and add F-3/F-4 to the LEDGER as the residual halves of O-31.
