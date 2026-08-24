# Session B · lane D-1 — `pg_constraint` enum↔CHECK parity gate — FISCAL lens, round 1

- **Lane / branch:** `fix/sb-d1-pg-constraint-parity-test` · worktree `.worktrees/sb-d1-check-parity`
- **Commit reviewed:** `70adfcb2e` — *test(architecture): pg_constraint enum<->CHECK parity ratchet (Session B lane D-1)*
- **Diff shape:** 9 new files, all under `apps/api/tests/Architecture/**`, +2045/-0. **Zero production files.** `git status --porcelain` clean at review end.
- **Reviewer:** fiscal-pos-reviewer (fiscal/POS half of the dual gate). Tenancy half is separate.
- **Evidence DB:** the implementer's throwaway `autoerp_sbd1_test` on PG 5433 (dropped at the end of this review).
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-D1-pg-constraint-parity-test.md`

## Verdict

**VERDICT: spec ✅ + quality APPROVED-with-residuals (fiscal lens). Do not merge on this lens alone — the tenancy lens must also clear.**

Every headline claim I could test held up on the live migrated schema: the derivation is genuinely
mechanical, the tenant/central split is real and asserted, the 190-key baseline is exactly
189 MISSING + 1 NARROWER, the ratchet is shrink-only and fail-closed in **both** directions under a
**live** CHECK drop / widen / narrow, and the AUDIT §#26 correction is correct — `pos_receipts.fiscal_status`
really is already constrained and §#26 really did file it as uncovered.

The lane's own **IMMEDIATE FINDING is over-stated and its committed register mislabels it** (F-1 below), and
the constraint parser has a **hard blind spot on `NOT VALID` CHECKs** — the exact rollout idiom the slice-D
burn-down batches are mandated to use (F-2). Neither blocks this test-only commit; both must be on the
LEDGER before the first CHECK-adding batch ships, and F-1's baseline key must be re-labelled.

## A · Ruling on the "write bomb" (`fiscal_event_quarantine.integrity_exception_class`)

**Ruling: (ii) — this is a DELIBERATE narrower gate, NOT a live write bomb. It is not launch-blocking.
The register must be corrected to say so, and the follow-up lane must NOT widen the CHECK.**

Live constraint on the migrated schema:

```
fiscal_event_quarantine_class_phase1_allowed
  CHECK (((integrity_exception_class)::text = ANY ((ARRAY['sequence_conflict'::character varying,
                                                          'malformed_envelope'::character varying])::text[])))
```

`App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass` admits 6 cases and carries the predicate that
proves the partition is intentional:

- `app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php:26-29` — `isAdmissibleToLedger()` returns
  `false` for **exactly** `SequenceConflict` and `MalformedEnvelope`, `true` for the other four.

`fiscal_event_quarantine` is the **non-admissible partition**; the other four classes are *in-table*
quarantined on `fiscal_events.integrity_exception_class` (a different table, a different column, and one the
model declares as plain `string|null` at `app/Modules/Fiscal/Domain/Models/FiscalEvent.php:60` — so it is not
in this gate's population at all).

Every writer, verified by grep + read:

- `app/Modules/Fiscal/Application/Services/OutboxIngestor.php:925-933` — `insertQuarantineRow()` is the **only**
  `INSERT` into `fiscal_event_quarantine` anywhere in `app/`.
- Its only two callers: `:359` (via `buildQuarantineRow(... integrityClass: IntegrityExceptionClass::MalformedEnvelope ...)`
  at `:341-347`) and `:1154` (via `:1145-1151`, `integrityClass: IntegrityExceptionClass::SequenceConflict`).
- The chain-check classes are derived at `:767-770` (`CanonicalHashMismatch` / `SequenceGap` / `TimeAnomaly` /
  `CanonicalParseFailure`) and written at `:791` / `:839` — into **`fiscal_events`**, not the quarantine table.
- `QuarantineIncidentResolutionService.php:131` only **UPDATEs** an existing quarantine row (resolution stamp).
- `OutboxIngestor.php:1305-1320` (`envelopeQuarantineSafe`) and `:300-324` show the author reasoning
  explicitly about which quarantine-table CHECKs would reject a row — this is a designed boundary.

**Conclusion: there is no live path today by which a `sequence_gap` / `time_anomaly` /
`canonical_hash_mismatch` / `canonical_parse_failure` reaches `fiscal_event_quarantine`.** No 500, no
`QueryException`. The lane's "IMMEDIATE FINDING" of a live write bomb is **not confirmed**.

What the register must do instead — see finding **F-1**.

## B · Fiscal / POS COVERED + MISSING spot-check (against live `pg_get_constraintdef`)

Every row below was read from `pg_constraint` on the migrated `autoerp_sbd1_test` and diffed against the
enum's `case` values. **Verdicts are real set-equality, not "some CHECK exists".**

| Table.column | Register | Constraint read live | Enum cases | Real? |
|---|---|---|---|---|
| `pos_receipts.fiscal_status` | COVERED | `pos_receipts_fiscal_status_check` → {pending_seal, fiscalized, voided, pending_sync, synced, sync_failed} | POS `FiscalStatus` = same 6 | ✅ exact |
| `pos_terminals.type` (Q-7) | COVERED | `pos_terminals_type` → {web, physical, virtual_admin} | `TerminalType` = same 3 | ✅ exact |
| `pos_held_orders.status` (Q-8) | COVERED | `pos_held_orders_status_check` → {held, recalled, expired} | `HeldOrderStatus` = same 3 | ✅ exact |
| `pos_shifts.status` | COVERED | `pos_shifts_status` → {OPEN, CLOSED} | `ShiftStatus` = same 2 | ✅ exact |
| `pos_receipt_payments.instrument_type` | COVERED | `..._instrument_type_check` → NULL-guarded {store_voucher, restaurant_voucher, gift_card, none} | `PaymentInstrumentKind` = same 4 | ✅ exact |
| `documents.fiscal_status` | COVERED | `chk_fiscal_status_enum` → {DRAFT, SEALED, VOIDED} | Document `FiscalStatus` = same 3 | ✅ exact |
| `documents.fiscal_category` | COVERED | `chk_fiscal_category_enum` → 6 values | `FiscalCategory` = same 6 | ✅ exact |
| `fiscal_events.event_type` | COVERED | `fiscal_events_event_type_allowed` → 35 values | `FiscalEventType` = 35 | ✅ `diff` = IDENTICAL |
| `device_loss_incidents.recovery_status` | COVERED | `..._recovery_status_allowed` → 4 | `DeviceLossIncidentStatus` = same 4 | ✅ exact |
| `voucher_ledger.event` | COVERED | `voucher_ledger_event_check` → 9 | `VoucherEvent` = same 9 | ✅ exact |
| `vouchers.status` | MISSING (baselined) | **no** CHECK of any kind on `vouchers` | `VoucherStatus` | ✅ correct |
| `documents.status`, `documents.type` | MISSING | `documents` has only the 3 CHECKs above | — | ✅ correct (matches DS-2) |
| `journal_entries.status`, `.journal_code` | MISSING | **zero** CHECKs on `journal_entries` | — | ✅ correct (matches §#26) |
| `payments.status/.origin/.payment_type` | MISSING | no CHECK | — | ✅ correct |
| `instrument_events.from_status/.to_status` | MISSING | only `instrument_events_action_digest_chk` (cross-column) | `InstrumentStatus` | ✅ correct |
| `fiscal_events.integrity_status/.signature_status/.payload_parse_status` | MISSING | no value-set CHECK on those columns | — | ✅ correct |
| `pos_receipts.receipt_type` | MISSING | `pos_receipts_return_logic` (cross-column) pins it to {sale, return} | `ReceiptType` = {sale, return} | ⚠️ see **F-4** |

**AUDIT §#26 correction verified.** §#26 (`docs/handoff/AUDIT-state-machine-sweep-local-2026-08-23.md:133`)
lists `pos_receipts.fiscal_status` among the 76 *uncovered*; the CHECK is added at
`apps/api/database/migrations/tenant/2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:54-55`
(`CHECK (fiscal_status IN ('pending_seal', …))`). §#26 line 145 likewise files
`impersonation_grants.status` / `impersonation_elevations.status` as uncovered; both
`impersonation_grants_status_check` and `impersonation_elevations_status_check` exist live. The lane's
claim (3) is **TRUE**. `bank_reconciliations_status_check` exists with no Eloquent model — also true,
genuinely uncheckable by this gate. `fiscal_event_quarantine.payload_parse_status` has no cast — verified,
`FiscalEventQuarantine::casts()` (`:140-154`) omits it while casting `integrity_exception_class` (`:149`).

## C · Parser honesty — three real gaps, one of them material

`PgValueSetCheckReader::parseValueSet()` recognises exactly three rendered shapes (ARRAY-with-optional-null-guard
`:115`, null-guard-last `:130`, degenerate single value `:139`) and returns `null` for everything else. Everything
that returns `null` reads as **MISSING**, i.e. it over-reports rather than falsely COVERing — with the exception
of F-3. I planted real constraints on a scratch table and fed the *actual* rendered `pg_get_constraintdef()`
output back through the pure parser:

| Planted | PG renders | Parser | Effect |
|---|---|---|---|
| `a IN ('x','y') **NOT VALID**` | `CHECK ((a)::text = ANY (ARRAY[…])) NOT VALID` | **`null`** | **invisible — reads MISSING** (F-2) |
| `b = 'p' OR b = 'q'` | `CHECK (((b)::text='p') OR ((b)::text='q'))` | `null` | reads MISSING (F-5) |
| `c IN ('m','n')` on `text` | `CHECK ((c = ANY (ARRAY['m'::text,'n'::text])))` | `{m,n}` | ✅ |
| `d NOT IN ('bad')` | `CHECK (((d)::text <> 'bad'))` | `null` | correct — not a value set |
| literal containing `(` | `ARRAY['a(b)'…]` | `{"a b ", "z"}` | **corrupted set** (F-3) |

I also confirmed the four documented carve-outs behave: `pos_shifts_closed_logic`, `pos_terminals_code_format`,
the `max_discount_percent` range and the `length(genesis_seed)=64` check all parse to `null` and are excluded
from the comparison — verified both in the data provider
(`EnumCheckParityDetectorLivenessTest.php:222-241`) and against the live definitions.

**I found no false COVERED in this schema.** All ten fiscal/POS COVERED verdicts are exact set equality.

## D · Baseline / ratchet semantics — verified live, not just read

The pure-function tamper cases are in `EnumCheckParityDetectorLivenessTest.php:66-186`. I did not take them on
trust: I drove the **real** derivation + the **real** `pg_constraint` read against the **committed** baseline on
the throwaway DB and mutated the live schema. Result:

| Scenario | NEW | STALE |
|---|---|---|
| 0 · committed baseline, untouched schema | 0 | 0 |
| 1 · baseline minus `payments.status::MISSING` | 1 (`payments.status::MISSING`) | 0 |
| 2 · baseline plus bogus `pos_orders.status::WIDER` | 0 | 1 (`pos_orders.status::WIDER`) |
| 3 · **live `DROP CONSTRAINT pos_shifts_status`** (a COVERED column) | **1 (`pos_shifts.status::MISSING`)** | 0 |
| 4 · **live widened** `pos_shifts_status` (+`SUSPENDED`) | **1 (`pos_shifts.status::WIDER`)** | 0 |
| 5 · restored | 0 | 0 |
| 6 · **live narrow CHECK planted on baselined-MISSING `vouchers.status`** | **1 (`vouchers.status::DIVERGENT`)** | **1 (`vouchers.status::MISSING`)** |
| 7 · **live `NOT VALID` CHECK on `pos_receipts.receipt_type`** | **0** | **0** ← F-2 |
| 8 · restored | 0 | 0 |

Rows 3, 4, 6 are the three behaviours the brief demanded, proven on a live schema against the committed
baseline: **removing a CHECK fails immediately; widening fails immediately; a baselined-MISSING column that
grows a WRONG CHECK fails twice (NEW + STALE) and cannot hide behind its own entry.** A new enum-backed column
with no CHECK is pinned as a pure-function case at `:136-154` (a key the baseline has never seen ⇒ NEW).
The baseline is therefore genuinely **shrink-only**.

**The writer's DB-name guard is real.** `write-enum-check-parity-baseline.php:49-52` refuses any name outside
`^autoerp_[a-z0-9_]*test$` *before* touching the catalogue. Executed against `synerivia_central`:
`refusing to read 'synerivia_central' …`, `exit=2`, no file written (`git status` clean afterwards).

## Findings

### [Important] F-1 — the committed register calls an INTENDED partition gate a "write bomb", and baselines it as debt
`apps/api/tests/Architecture/baselines/enum-check-parity-register.md:93` (the `NARROWER` row) and `:292-293`
("*One column is already DIVERGENT, not merely uncovered … A CHECK that is narrower than its enum is a write
bomb, not debt*"), plus the baseline key `fiscal_event_quarantine.integrity_exception_class::NARROWER`.
**What's wrong:** as ruled in §A, `fiscal_event_quarantine_class_phase1_allowed` is the *by-design*
non-admissible partition — `IntegrityExceptionClass::isAdmissibleToLedger()`
(`app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php:26-29`) is literally that predicate, and the only
two `insertQuarantineRow()` callers (`OutboxIngestor.php:359`, `:1154`) pass exactly the two admitted values.
**Why it matters:** the artifact is the burn-down denominator the owner reads. A future slice-D batch that
"fixes the NARROWER row" by widening the CHECK to all six cases would **destroy the ledger/quarantine
partition** — it would let a `sequence_gap` (which belongs in-table on `fiscal_events`) be written into the
non-admissible quarantine table. This is the one line in the artifact whose plain reading tells a fiscal lane
to do the wrong thing.
**Fix:** re-label this as an **INTENDED narrower CHECK**, not debt. Concretely, either (a) add an
`INTENDED_NARROWER_COLUMNS` allow-map to `EnumCheckParityAnalyzer` keyed `table.column => [allowed subset] + why`,
so the verdict becomes `COVERED_BY_DESIGN` and the row leaves the baseline; or (b) at minimum, keep the key but
change the register prose to name this row as intentional with the `isAdmissibleToLedger()` citation and an
explicit "**do not widen**" instruction. (a) is preferable — under (b) the row is indistinguishable from debt
in the count. Either way the register's blanket "narrower = write bomb" sentence at `:292-293` must go.

### [Important] F-2 — a `NOT VALID` CHECK is completely invisible to the gate, and `NOT VALID` is the mandated rollout idiom
`apps/api/tests/Architecture/Support/PgValueSetCheckReader.php:115,130,139` — all three regexes anchor on
`\s*$`, so PostgreSQL's `… ) NOT VALID` suffix makes `parseValueSet()` return `null`.
**Proven live:** planting `CHECK (receipt_type IN ('sale','return')) NOT VALID` on `pos_receipts` produced
`NEW=0 STALE=0` — the gate saw nothing at all (row 7 of the table in §D).
**Why it matters:** AUDIT §#26's own fix shape (`…:151`) and the lane brief both mandate
"`NOT VALID` + separate `VALIDATE CONSTRAINT`" for every burn-down batch. So during the entire NOT-VALID
window every added CHECK is invisible: the register keeps saying MISSING, the burn-down number does not move,
and — the dangerous half — a **wrong** (wider/narrower) NOT VALID CHECK is *enforced by PG on every new write*
while `every_already_divergent_column_is_an_acknowledged_finding` cannot see it. A batch could ship a wrong
CHECK, break writes in production, and this gate would stay green.
**Fix:** strip a trailing `NOT VALID` in the normalisation step (before the regexes) and record it as a flag on
the parsed result; add both a data-provider case with the real rendered `… NOT VALID` string to
`EnumCheckParityDetectorLivenessTest::constraintDefinitionProvider()` and a planted-`NOT VALID` arm to
`the_pg_constraint_reader_actually_reads_a_planted_check()`. **This must land before the first CHECK-adding
batch**, not before this commit.

### [Important] F-3 — the parser's docblock makes a safety claim that is demonstrably false
`apps/api/tests/Architecture/Support/PgValueSetCheckReader.php:109-111`:
"*Literals are extracted from the ORIGINAL bracket body below, so this cannot corrupt a value containing a
parenthesis.*" They are not: `$flat` is the paren-flattened string (`:111-112`), `$m[3]` comes from `$flat`
(`:115`), and `self::literals($m[3])` (`:123`) therefore parses the **flattened** body.
**Proven:** `ARRAY['a(b)'::character varying, 'z'…]` parses to `["a b ", "z"]` — a silently wrong accepted set,
which is a **false COVERED / false DIVERGENT** vector, the one failure mode that makes the whole gate lie.
**Live risk today: NIL** — I grepped every `case … = '…'` under `app/` and no Domain-enum case value contains a
parenthesis. **Why it still matters:** a false comment is worse than no comment; the next reader will trust it
and add a value with a paren.
**Fix:** either delete the false sentence, or actually implement it (capture the bracket body from
`$normalised` before flattening). Deleting is acceptable given zero live exposure.

### [Important] F-4 — `pos_receipts.receipt_type` is a false MISSING today and a silent NARROWER tomorrow
`enum-check-parity-register.md:175` files `pos_receipts.receipt_type` as MISSING/baselined. Live,
`pos_receipts_return_logic` — `CHECK (receipt_type = 'sale' OR (receipt_type = 'return' AND original_receipt_id
IS NOT NULL AND return_reason IS NOT NULL))` — already pins the column to exactly `{sale, return}`, which is
exactly `App\Modules\POS\Domain\Enums\ReceiptType`'s two cases. The parser correctly refuses it as a
cross-column invariant (`PgValueSetCheckReader.php:14-18` carve-out).
**Why it matters (fiscal):** the *day someone adds a third `ReceiptType` case*, `pos_receipts_return_logic`
becomes a live NARROWER write bomb on a fiscal table — and this gate will keep reporting the column as
`MISSING`, a key that is already in the baseline, so it stays green forever. Same structural exposure applies
to any column whose only constraint is a cross-column invariant.
**Fix:** no code change in this lane. Record on the LEDGER, and have the slice-D batch that touches
`pos_receipts` add an explicit `pos_receipts_receipt_type_check` value-set CHECK (which then makes the
baseline entry STALE and forces its removal — the ratchet works correctly once the CHECK is explicit).

### [Minor] F-5 — OR-chain-of-equalities CHECKs read as MISSING and have no liveness case
`PgValueSetCheckReader.php:100-152` has no branch for `((c)::text = 'p') OR ((c)::text = 'q')` (proven above),
and `constraintDefinitionProvider()` (`EnumCheckParityDetectorLivenessTest.php:193-243`) does not exercise the
shape either way. Direction is safe (false MISSING, never false COVERED), and Laravel's `enum()`/`whereIn`
builders do not emit it, so exposure is hand-written migrations only. Fix: add a `null`-expecting provider case
so the *decision* to ignore the shape is pinned rather than accidental, or support it.

### [Minor] F-6 — `unconstructableModels()` is dead code and its docblock claims a guarantee it does not provide
`apps/api/tests/Architecture/Support/EnumBackedColumnRegistry.php:101-106` says a skipped model "*is counted by
`unconstructableModels()` so the skip is never silent*", but nothing calls it —
`grep -rn unconstructableModels tests/ app/` returns only the writer script's *sibling* method and the
definition at `:217`. A model that becomes unconstructable silently drops its enum columns from the population.
Exposure today is nil — I ran it: **247 models, 0 unconstructable**. Fix: assert
`$registry->unconstructableModels() === []` in `EnumCheckParityTest`, or delete the claim.

### [Minor] F-7 — register header mislabels the table count
`enum-check-parity-register.md:7` — "*schema: 274 tenant tables*". The value is
`count($scopes->map())` (`write-enum-check-parity-baseline.php:103`), which is every table declared by **both**
migration trees (central + tenant), not tenant tables. Fix the label on the next regeneration.

### [Minor] F-8 — `nullable` is parsed, carried, and never used
`PgValueSetCheckReader.php:76,88` populates `nullable`; `EnumCheckParityAnalyzer::analyze()` (`:60-80`) never
reads it. Harmless (a CHECK without a null guard admits NULL anyway under SQL three-valued logic), but it is
carried through three type signatures as if load-bearing. Informational.

### [Minor] F-9 — `fiscal_event_quarantine.payload_parse_status` is enum-governed in fact but invisible to the gate
`OutboxIngestor.php:1211` region writes `'payload_parse_status' => $payloadParseStatus->value` into the
quarantine row, but `FiscalEventQuarantine::casts()` (`:140-154`) does not cast it, so the registry cannot see
it. The register discloses this in prose (`:290-291`) but does **not** close it — neither via the model cast
nor via `GOVERNED_AUDIT_COLUMNS`. Correctly out of scope for a test-only lane (the cast is a production change).
LEDGER residual: add `'payload_parse_status' => PayloadParseStatus::class` to the quarantine model, which pulls
the column into the population for free.

## Gate verified (per-file counts, all run BY PATH — never the full suite)

| Check | Command | Result |
|---|---|---|
| Parity gate on PG 5433 | `phpunit tests/Architecture/EnumCheckParityTest.php` (pgsql/`autoerp_sbd1_test`) | **OK — 4 tests, 389 assertions**, 12.4 s |
| Liveness on PG 5433 | `phpunit tests/Architecture/EnumCheckParityDetectorLivenessTest.php` | **OK — 18 tests, 35 assertions**, 1.7 s |
| Combined | — | **22 tests / 424 assertions** — matches the implementer's claim exactly |
| sqlite self-skip | `phpunit tests/Architecture/EnumCheckParityTest.php --display-skipped` | **4 skipped**, message names the driver and the exact re-run line |
| Pint | `./vendor/bin/pint --test tests/Architecture` | `{"result":"pass"}` |
| PHPStan | `phpstan.neon:6-8` — `paths: [app/]` | **N/A confirmed** — `tests/` is not analysed |
| Feature-lane manifest | `phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` | **OK — 76 tests, 431 assertions**, EXIT=0 with the new files present |
| Baseline integrity | 190 keys, 190 unique = 189 `MISSING` + 1 `NARROWER` | matches the register's stated denominator |
| Writer name guard | writer pointed at `synerivia_central` | refused, `exit=2`, nothing written |
| Live ratchet tamper | drop / widen / narrow on the real schema vs the committed baseline | fires correctly (§D rows 3, 4, 6) |
| Worktree state | `git status --porcelain` | clean; single commit `70adfcb2e`; scope is `apps/api/tests/Architecture/**` only |

Scope check (F): nothing outside `apps/api/tests/Architecture/**` is touched; no Session A collision-matrix
file appears in the diff.

## CI wiring statement (report only — S-14, no workflow file touched)

- **No CI job runs this gate today, effectively.** `.github/workflows/ci.yml:490` does include
  `tests/Architecture` in `backend-test`'s partitioned run, but (a) that step is gated on
  `github.event_name == 'workflow_dispatch'` (`:477`) and (b) `backend-test` runs on **sqlite**, where all four
  parity tests self-skip. The 18 pure-function liveness cases DO execute there; the PG probe skips.
- **Proposed home is sound:** `treasury-spine-pgsql` (`ci.yml:1095`) already provisions a `postgres:16` service
  whose DB is `autoerp_treasury_test` — which satisfies the writer's `^autoerp_[a-z0-9_]*test$` guard and is a
  valid parity-gate host. Its trigger (`:1116`) covers PR→dev, PR→main and dispatch, i.e. it protects the
  slice-D burn-down while it is being merged, which is what this gate is for.
- `backend-architecture` (`ci.yml:143`) is a Deptrac job and runs named Architecture files by path
  (`:202,:215,:228`); adding the two files there is the cheap belt-and-braces for the pure-function half, but
  **the PG arm must go to `treasury-spine-pgsql`** or it will only ever skip.
- **Wiring is owed and unverified.** Until it lands, this gate protects nothing in CI. It should be an explicit
  precondition on the first CHECK-adding batch, not a follow-up.

## Residuals for the LEDGER

1. **F-1 · re-label `fiscal_event_quarantine.integrity_exception_class` as an INTENDED narrower CHECK.** Do NOT
   widen it — widening breaks the ledger/quarantine partition. Owner-visible: the burn-down denominator drops
   190 → 189 if the row leaves the baseline via an `INTENDED_NARROWER` map.
2. **F-2 · `NOT VALID` blindness must be fixed BEFORE the first burn-down batch** (the batches are mandated
   NOT VALID + VALIDATE). Include a planted-`NOT VALID` liveness arm.
3. **F-4 · `pos_receipts.receipt_type`** — false MISSING today (pinned by `pos_receipts_return_logic`), latent
   silent NARROWER if `ReceiptType` ever gains a case. Add an explicit value-set CHECK in the POS batch.
4. **F-9 · cast `fiscal_event_quarantine.payload_parse_status`** on the model (production change) to pull it
   into the population.
5. **CI wiring owed** — `treasury-spine-pgsql` + `backend-architecture` (S-14: workflow edit is a separate act).
6. **Disclosed by the lane and confirmed by me, not fixed here:** no anti-growth ceiling (matched growth — a new
   uncovered column plus its baseline entry in one diff — passes; caught only by baseline-diff review), and the
   detector is candidate-deletable (nothing asserts the files exist). Both are owner actions (repository
   variable + pin tag), correctly reported rather than faked.
7. **F-3, F-5, F-6, F-7, F-8** — comment/coverage hygiene; batch them into the next touch of these files.

## What to fix before merge

Nothing blocking in the code; **fix the register's F-1 mislabel** (one artifact edit — the "narrower = write
bomb" sentence and the `NARROWER` row's framing) so the burn-down does not instruct a future fiscal lane to
widen a deliberate partition gate, and put **F-2 (`NOT VALID` blindness) + the CI wiring** on the LEDGER as
hard preconditions for the first CHECK-adding batch.
