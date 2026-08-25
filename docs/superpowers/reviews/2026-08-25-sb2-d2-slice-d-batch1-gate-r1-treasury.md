# Session B2 · lane B2-4 / Slice D batch 1 — 13 money/fiscal enum CHECKs — TREASURY gate, ROUND 1

**VERDICT: spec ✅ + quality ACCEPT-with-conditions. MERGE-BLOCKING: NO** (two pre-merge docblock corrections, C1/C2; everything else is a LEDGER row).

- **Lane / branch:** `fix/sb2-d2-slice-d-batch1` @ `3535d55b1` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-d2-slice-d-batch1` (base `68de40b44`)
- **Diff:** `git diff 68de40b44..3535d55b1` = **9 files, +1312/−33**. `grep -c '^\.github/\|apps/api/app/'` over the name-only list = **0** — no production code, no workflow edits. **MIGRATION-BEARING** (5 tenant migrations).
- **Reviewer:** treasury-reviewer. A fiscal-pos reviewer runs in parallel on the same branch; I edited **no** branch file, ran no `git stash`, merged/pushed nothing. `git status --porcelain` in the lane worktree = empty at review end.
- **Evidence DB:** my own throwaway `autoerp_treasurygate_test` on PG 5433 (`autoerp`/`autoerp_secret`), created for this review and migrated with the branch's migration tree. A second DB `autoerp_treasurygate2_test` was created for the freeze experiment (F-1) and **dropped**. `autoerp_treasurygate_test` is left in place for reproduction.
- **Method:** every disposition below is either a live execution on that DB or a byte-comparison I performed. Nothing is asserted from reading the implementer's report.
- **Records read:** brief `docs/sessions/session-B-2026-08-23/BRIEF-D2-slice-d-batch1.md`, report `docs/sessions/session-B-2026-08-23/REPORT-D2-implementer.md`, D-1 r2 gate `docs/superpowers/reviews/2026-08-25-sb-d1-parity-gate-r2.md`, N-6 treasury gate `docs/superpowers/reviews/2026-08-24-n6-gate-r1-treasury.md`, LEDGER rows C-26 (`docs/handoff/LEDGER.md:149`) and C-37 (`:161`).

---

## Executive summary

The money-correctness core of this lane is **clean and I verified it independently of the implementer's tooling**. All 13 CHECK value sets equal their enum's cases exactly (machine-compared against `pg_get_constraintdef()`, not against the parity parser); all 13 are `convalidated = true`; nullability is exactly right on all 13 against `information_schema`; the in-migration census aborts **atomically** before any DDL survives; `down()` drops exactly its own constraints and nothing else; `up()` is re-runnable; the register and both baselines **regenerate byte-identically** from the committed writer; the armed parity gate is `OK (11 tests, 790 assertions)` and fails closed unset; and eight treasury/accounting/POS-projection Feature files pass on PG.

Two Important findings, both **claim-vs-behaviour** rather than data-correctness, both proven live:

1. **The "FROZEN AT MIGRATION-RUN TIME … the obligation is enforced, not merely documented" sentence in all five migration docblocks is FALSE.** I added a 6th `VoucherStatus` case with no widening migration; on a fresh schema the migration re-derives from `Enum::cases()` so the CHECK silently widened, and **both** gates stayed fully green (`EnumCheckParityTest` 11/790, `SliceDBatch1CheckConstraintsTest` 19/70). Nothing in the tree can go red for the exact failure the docblock says is enforced. This template will be copied into batches 2..N.
2. **The `NOT VALID` → `VALIDATE` split delivers zero lock benefit as executed.** Laravel wraps the migration in a transaction (`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migration.php:19`, `Migrator.php:449`), so the `ACCESS EXCLUSIVE` lock taken by `ADD CONSTRAINT … NOT VALID` is held until COMMIT — through the validation scan. Proven live: with that transaction open, a plain `SELECT count(*) FROM payments` from a second session **blocked and hit `statement_timeout`**. The docblocks promise "a brief ACCESS EXCLUSIVE lock" and a VALIDATE that "does not block readers or writers while it scans"; on `documents` at a real tenant's row count that is the whole table offline for the scan.

Neither risks wrong money today. Both must be resolved on the record, because the five files are the template for the rest of Slice D.

---

## (1) Value sets = enum cases at HEAD, for all 13 columns — VERIFIED (independently of the parity parser)

The whole lane's premise is "a missing case is a live write bomb on a money table", so I did **not** trust `EnumCheckParityTest` for this. I read `pg_get_constraintdef()` straight from my migrated DB, extracted every quoted literal, and compared to `Enum::cases()` reflected from the branch's autoloader:

```
chk_vouchers_status_enum               MATCH  sqlN=5  enumN=5   onlySQL=[] onlyENUM=[]
chk_vouchers_source_enum               MATCH  sqlN=6  enumN=6   onlySQL=[] onlyENUM=[]
chk_vouchers_voucher_kind_enum         MATCH  sqlN=2  enumN=2   onlySQL=[] onlyENUM=[]
chk_vouchers_redemption_mode_enum      MATCH  sqlN=2  enumN=2   onlySQL=[] onlyENUM=[]
chk_journal_entries_status_enum        MATCH  sqlN=3  enumN=3   onlySQL=[] onlyENUM=[]
chk_journal_entries_journal_code_enum  MATCH  sqlN=6  enumN=6   onlySQL=[] onlyENUM=[]
chk_payments_status_enum               MATCH  sqlN=4  enumN=4   onlySQL=[] onlyENUM=[]
chk_payments_payment_type_enum         MATCH  sqlN=7  enumN=7   onlySQL=[] onlyENUM=[]
chk_payments_origin_enum               MATCH  sqlN=6  enumN=6   onlySQL=[] onlyENUM=[]
chk_documents_type_enum                MATCH  sqlN=13 enumN=13  onlySQL=[] onlyENUM=[]
chk_instrument_events_event_type_enum  MATCH  sqlN=9  enumN=9   onlySQL=[] onlyENUM=[]
chk_instrument_events_from_status_enum MATCH  sqlN=9  enumN=9   onlySQL=[] onlyENUM=[]
chk_instrument_events_to_status_enum   MATCH  sqlN=9  enumN=9   onlySQL=[] onlyENUM=[]
ALL 13 EXACT
```

Because this comparison bypasses `PgValueSetCheckReader` entirely, the C-26 R2-1 false-COVERED class cannot have contaminated it. (C-26's fix is present at base — the quote-aware top-level-AND refusal at `apps/api/tests/Architecture/Support/PgValueSetCheckReader.php:235`.)

The brief's scope table is wrong in three places and the implementer is right in all three; I confirmed each at HEAD:
- **13 columns, not 12** — brief row 12 is `instrument_events.from_status` **and** `to_status`, two columns / two register rows / two baseline keys. Baseline lands at **175**, not the brief's 176.
- **`PaymentType` has 7 cases, not 9** — `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:10,13,16,19,22,25,38`.
- **`to_status` is nullable too**, not just `from_status`.

No table has a second model with a rival vocabulary: `App\Modules\Billing\Domain\Payment` is on `billing_payments` (`:58`), not `payments`, and carries its own `PaymentStatus`. All 13 columns are enum-cast on their models except `instrument_events.from_status` / `.to_status` (declared `string|null` at `app/Modules/Treasury/Domain/InstrumentEvent.php:23-24`); I traced every writer of those two — `InstrumentLifecycleService.php:122-123,164-165,871`, `InstrumentRemittanceService.php:182-185`, `OutboundInstrumentIssuer.php:197-198`, `OutboundInstrumentService.php:173,705` — and every one emits an `InstrumentStatus::…->value` or `null`. No raw writer on any of the 13 columns exists in `app/`, `database/seeders/` or `database/factories/`.

## (2) Nullability + defaults — VERIFIED against `information_schema`

```
documents.type                NO   default -
instrument_events.event_type  NO   default -
instrument_events.from_status YES  default -
instrument_events.to_status   YES  default -
journal_entries.journal_code  YES  default -
journal_entries.status        NO   default 'draft'
payments.origin               YES  default -
payments.payment_type         NO   default 'document_payment'
payments.status               NO   default 'pending'
vouchers.redemption_mode      NO   default -
vouchers.source               NO   default -
vouchers.status               NO   default -
vouchers.voucher_kind         NO   default 'MPV'
```

Exactly the four nullable columns carry the `(c IS NULL OR c IN (…))` form and the nine NOT NULL columns carry the plain `(c IN (…))` form — **C-37(iii) honoured, no `IS NOT NULL AND`, no compound CHECK**, confirmed in `pg_get_constraintdef()` output for all 13. The `checkPredicate()` / `violationPredicate()` split that produces this is at e.g. `apps/api/database/migrations/tenant/2026_08_25_130300_add_enum_check_constraints_to_payments.php:174-192`.

**Ruling on "NOT NULL column with a PG default":** all four defaults (`'draft'`, `'document_payment'`, `'pending'`, `'MPV'`) are **inside** their sets, so they are safe. A default **outside** the set would be a write bomb on every INSERT that omits the column — PostgreSQL applies the default and then the CHECK rejects it, and neither the census (which scans existing rows only) nor the parity gate (which compares CHECK to enum, not to the default) would see it. Nothing in this batch pins that property. See M-2.

Additional note on the hardcoded `bool $nullable` flag in each `COLUMNS` constant (`…_payments.php:95-99`): it is a **build-time** assertion about a **run-time** schema. On a tenant where an earlier migration silently dropped a NOT NULL, the census correctly aborts on existing NULLs (the NOT-NULL arm treats `col IS NULL` as a violation, `:178`), so the dangerous direction is covered. The residual is that a *future* NULL would then pass a `(col IN (…))` CHECK by three-valued logic — acceptable, and the new test's `test_nullable_set_matches_the_live_schema()` (`SliceDBatch1CheckConstraintsTest.php:143-164`) pins the four-column list against `information_schema` on every run.

## (3) Census-before-DDL, NOT VALID/VALIDATE, `down()`, re-runnability, guards — VERIFIED BY EXECUTION

All four proven on `autoerp_treasurygate_test` by rolling the `payments` migration back and driving it against planted dirt:

| Property | How I proved it | Result |
|---|---|---|
| `down()` is exact | `migrate:rollback --path=…130300_…payments.php` | `chk_payments%_enum` count **3 → 0**; the other 13 `chk_%_enum` constraints untouched (16 → 13); only the payments row left `migrations` |
| census aborts before any DDL | planted `payments.status='abolished'` (NOT NULL arm), re-ran the migration | `Cannot create chk_payments_status_enum: payments.status carries 1 row(s) with the unknown value "abolished"` — and **0** `chk_payments%_enum` constraints created |
| census catches a bad NON-NULL on a NULLABLE column | fixed `status`, left `origin='martian'`, re-ran | `Cannot create chk_payments_origin_enum: payments.origin carries 1 row(s)…` — and **0** constraints created, i.e. the `status` and `payment_type` constraints created earlier in the same loop were **rolled back**. The migration is atomic per table (bonus property the brief did not ask for) |
| clean re-run | fixed `origin`, re-ran | `DONE`; all three constraints present, `convalidated = t` |
| re-runnable / idempotent | full `migrate` re-applied over an already-constrained schema during the tests' `migrate:fresh` cycles | 16 `chk_%_enum`, `DROP CONSTRAINT IF EXISTS` makes the ADD idempotent |
| `pgsql` + `Schema::hasTable` guards | ran the new test file on the default sqlite suite | `Tests: 19, Assertions: 0, Skipped: 19` — `migrate:fresh` completed on sqlite with no error, so the migrations are genuine no-ops there |
| `VALIDATE` really ran | `pg_constraint.convalidated` for all 13 | **all `t`** (also pinned by `test_every_constraint_exists_and_was_validated()`, `SliceDBatch1CheckConstraintsTest.php:166-187`) |

`NOT VALID` and `VALIDATE` are indeed separate statements (`…_payments.php:140-141`). See **I-2** for why that separation buys nothing as executed.

## (4) Every legitimate treasury write path still works on PG — 8 files, all by path, one per invocation

| File | Result |
|---|---|
| `tests/Feature/Treasury/SliceDBatch1CheckConstraintsTest.php` (new, PG) | **OK (19 tests, 70 assertions)** |
| `tests/Feature/Treasury/SliceDBatch1CheckConstraintsTest.php` (sqlite) | **19 skipped**, 0 failures |
| `tests/Feature/Treasury/MultiPaymentTest.php` | **OK (17, 65)** — matches report |
| `tests/Feature/Accounting/CreateJournalEntryTest.php` | **OK (11, 29)** — matches report |
| `tests/Feature/Treasury/OutboundInstrumentServiceTest.php` (instrument lifecycle) | **OK (14, 77)** |
| `tests/Feature/Voucher/VoucherIssuanceServiceTest.php` | **12 tests, 155 assertions, 1 incomplete** (pre-existing) — matches report |
| `tests/Feature/Treasury/PosBridgeSpineTest.php` (POS payment projection) | **OK (7, 41)** |
| `tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php` (POS→treasury/GL projection, writes `PaymentOrigin`/`PaymentType` with no `CompanyContext`) | **OK (16, 54)** |

The two projection files are the ones that matter for rule 20 — a CHECK that rejected a projection-authored `origin`/`payment_type` would surface there, and does not.

## (5) Ratchet artifacts — VERIFIED, and the register is genuinely REGENERATED

- Baseline diff = **exactly 13 deletions, zero additions**, and they are exactly the 13 in-scope keys (`documents.type`, `instrument_events.{event_type,from_status,to_status}`, `journal_entries.{journal_code,status}`, `payments.{origin,payment_type,status}`, `vouchers.{redemption_mode,source,status,voucher_kind}`), all `::MISSING`. 188 → **175**.
- `enum-check-parity-central-baseline.json` and `enum-check-parity-acknowledgements.json` are **not in the diff at all** — byte-identical by construction.
- **Reproduced with the writer.** I created a detached scratch worktree at `3535d55b1` with its own regenerated autoloader (a symlinked `vendor` silently poisons the run — the registry's enum-FILE-PATH boundary resolves to the *other* worktree and the population collapses from 245 to 2; worth 3 lines in the writer's docblock for the remaining Slice D batches) and ran `tests/Architecture/Support/write-enum-check-parity-baseline.php` against my migrated DB:
  ```
  [enum-check-parity] 245 tenant columns, 175 tenant baseline entries; 20 central columns, 11 central baseline entries;
  verdicts: {"COVERED":68,"COVERED_BY_COMPOSITE":1,"INTENDED_NARROWER":1,"MISSING":175}
  ```
  `git status --porcelain` / `git diff` over `tests/Architecture/baselines/` in that scratch worktree afterwards: **empty**. All three artifacts — including the register — regenerate **byte-identically** from the committed writer. The register was not hand-edited. 68 + 1 + 1 + 175 = 245 ✅.
- **Parity gate armed:** `ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf` → **`OK (11 tests, 790 assertions)`**, matching the report exactly.
- **Fails closed unset:** same command with the variable unset → `1) …::the_parity_artifacts_never_grow_against_the_owner_pinned_seed … FAILS CLOSED` at `EnumCheckParityTest.php:593`; `Tests: 11, Failures: 1`.

## (6) The FROZEN-AT-RUN-TIME obligation — **NOT enforced.** See I-1 below.

## (7) CI reachability — the report's claim is CORRECT and I verified it

`.github/workflows/ci.yml:1128` `treasury-spine-pgsql`, `if:` at `:1148` includes `github.base_ref == 'dev'` (not gated on `vars.SELF_HOSTED_RUNNER_READY`), Postgres 16 service at `:1152-1165`, and the step **`PG-only invariants — Treasury Feature suite` runs `./vendor/bin/phpunit tests/Feature/Treasury`** — the whole directory, no `--filter`. `AUTOERP_QUARANTINE` is **not** set on this job (it is set only on the POS job at `:1346`). So `SliceDBatch1CheckConstraintsTest` executes in CI on a real PG with no `.github/**` edit. The B-3 `--filter` append is correctly declined.

**But the ratchet this lane just moved is still CI-dead:** `grep -rn "EnumCheckParity\|ENUM_CHECK_PARITY" .github/workflows/` returns **nothing**. The 188→175 shrink, the anti-growth ceiling and the acknowledgement rot guard are honoured by review only. That is LEDGER C-26(i), unchanged and correctly out of scope for a lane forbidden to touch `.github/**` — but it means the *only* CI-live guard produced by this batch is the new liveness pin, which (see I-1) is blind to the one failure mode the docblocks call out.

---

## Findings

### [Important] I-1 — the "obligation is enforced, not merely documented" claim is false; nothing goes red when an enum gains a case. **Proven live.**
`apps/api/database/migrations/tenant/2026_08_25_130300_add_enum_check_constraints_to_payments.php:76-84` (and the identical paragraph in the other four migrations) states:

> *"…adding a case later leaves every already-migrated tenant with the OLD constraint, which will then reject the new value at INSERT time, per tenant, at runtime … **`EnumCheckParityTest` fails the moment the two disagree, so the obligation is enforced, not merely documented.**"*

The second sentence is false. `EnumCheckParityTest` and `SliceDBatch1CheckConstraintsTest` both run under `RefreshDatabase`, i.e. against a **freshly migrated** schema — and the migration derives its value list from `Enum::cases()` **at migrate time**. So on the test DB the CHECK and the enum agree *by construction*, forever.

**Experiment.** In a scratch copy (never the branch) I added `case Escheated = 'escheated';` to `VoucherStatus` and shipped **no** widening migration. On a fresh `migrate`:
```
chk_vouchers_status_enum = CHECK (status IN ('issued','partially_redeemed','fully_redeemed','expired','voided','escheated'))
```
— the constraint silently widened. Then:
```
tests/Architecture/EnumCheckParityTest.php            OK (11 tests, 790 assertions)   [seed armed]
tests/Feature/Treasury/SliceDBatch1CheckConstraintsTest.php  OK (19 tests, 70 assertions)
```
**Both gates fully green** while every tenant already migrated on 2026-08-25 carries the five-value CHECK and will raise SQLSTATE 23514 on the first `escheated` voucher. That is a per-tenant, runtime, money-table write bomb with zero CI signal.

The N-6 precedent has the same hole: `tests/Feature/Document/DocumentStatusCheckConstraintParityTest.php:29-64` also runs on a fresh `RefreshDatabase` schema against a migration that derives from `DocumentStatus::cases()` (`2026_08_24_100100_add_status_check_constraint_to_documents.php:124-126`), so it too is tautological with respect to enum growth. This batch inherited the sentence without inheriting an actual guard — and shipped no equivalent test at all.

**Answer to the gate question:** `EnumCheckParityTest` does *not* cover the 13 in the sense that matters. On a fresh schema it can never read NARROWER for a `cases()`-derived CHECK. **A per-batch freeze test IS owed.** The only shape that works is a **pinned case list committed in the test** (the same idea as `enum_cases_at_acknowledgement` in `enum-check-parity-acknowledgements.json`): assert `Enum::cases()` still equals the frozen list for all 8 enums, so adding a case turns the test red until the author ships a widening migration *and* moves the pin.

**Fix — C1 (pre-merge, one line ×5):** delete or correct the "so the obligation is enforced, not merely documented" sentence in all five docblocks. It is the template for batches 2..N and a reviewer of batch 4 will read it and stop looking.
**Fix — L1 (LEDGER, before batch 2):** add the frozen-case-list test (8 enums, 13 columns) to `SliceDBatch1CheckConstraintsTest` or a sibling, and make it the batch template.

### [Important] I-2 — `NOT VALID` + `VALIDATE` buys nothing: Laravel wraps the migration in a transaction, so the ACCESS EXCLUSIVE lock is held through the scan. **Proven live.**
`…_payments.php:35-38` promises:
> *"`ADD CONSTRAINT … NOT VALID` — takes only a brief ACCESS EXCLUSIVE lock and does not scan the table; … a SEPARATE `VALIDATE CONSTRAINT` — SHARE UPDATE EXCLUSIVE, does not block readers or writers while it scans."*

`Illuminate\Database\Migrations\Migration::$withinTransaction` defaults to `true` (`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migration.php:19`) and `Migrator.php:449` honours it on PostgreSQL, which supports schema transactions. None of the five migrations opts out. So `ADD CONSTRAINT`, the census, and `VALIDATE CONSTRAINT` all run in **one** transaction, and PostgreSQL holds every ALTER TABLE lock until COMMIT.

**Proof.** Session A: `BEGIN; ALTER TABLE payments DROP CONSTRAINT …; ALTER TABLE payments ADD CONSTRAINT … NOT VALID; SELECT pg_sleep(6); ALTER TABLE payments VALIDATE CONSTRAINT …; COMMIT;`. Session B, 2 s later: `SET statement_timeout='2s'; SELECT count(*) FROM payments;` →
```
ERROR:  canceling statement due to statement timeout
```
A plain reader is blocked. On the fleet this means `documents`, `payments` and `journal_entries` are unavailable for reads **and** writes for the duration of a full-table validation scan on every tenant — precisely what the split was chosen to avoid.

This is the same defect class the D-1 r2 gate filed as R2-1 (docblock asserts a property the code does not have), and it is inherited by the whole of Slice D.

**Fix — C2 (pre-merge, pick one and say which):**
- (a) add `public $withinTransaction = false;` to the five migration classes — the split then actually works. The cost is the atomic-abort property I proved in §3; it is recoverable because the census still runs before any DDL, `DROP CONSTRAINT IF EXISTS` makes `up()` idempotent, and a half-applied table simply gets the rest on the re-run. Note the abort message then names only the failing column while earlier columns on the same table stay applied — say so in the docblock.
- (b) keep the transaction and **delete both lock sentences**, replacing them with the honest statement: *"both statements run inside Laravel's migration transaction, so the ACCESS EXCLUSIVE lock is held through the validation scan; schedule this in a maintenance window on large tenants."*

Either is fine. Shipping the current text is not: it will be pasted into batches 2..N and used to justify running the fleet migration hot.

### [Minor] M-1 — the census reports only the first offending value of the first offending column
`…_payments.php:124-135` throws on `$violations[0]` of the first column that has any. Combined with the transactional rollback (§3), a tenant with dirt in three columns needs three fix-and-rerun cycles, each surfacing one value. The 13 stand-alone census queries in `REPORT-D2-implementer.md:74-101` mitigate this **if the promoter actually runs them first**. Cheap improvement: aggregate all violations for the table and throw once with the full list.

### [Minor] M-2 — no assertion that each NOT NULL column's PG default is inside its value set
Four of the 13 carry defaults (`journal_entries.status='draft'`, `payments.payment_type='document_payment'`, `payments.status='pending'`, `vouchers.voucher_kind='MPV'`), all currently inside their sets. Nothing pins that. A future enum value rename that moves the enum case but not the column default converts every default-relying INSERT into a 23514 — invisible to the census (existing rows only) and to the parity gate (CHECK vs enum only). The implementer noted this in `REPORT-D2-implementer.md:138` as an observation; it should be an assertion. Two lines in `SliceDBatch1CheckConstraintsTest`: read `column_default` from `information_schema`, strip the `::character varying` cast, assert membership in `Enum::cases()`.

### [Minor] M-3 — the census SQL is hand-written prose that duplicates a `cases()`-derived predicate
The 13 census queries live as free text in the docblocks (e.g. `…_payments.php:58-71`) and are copied verbatim into `REPORT-D2-implementer.md:74-101`, which is what the promoter pastes into the `tenants:run` loop. I machine-checked all 13 today: value lists and nullability arms are **correct** and match the enums exactly. But they are a second, unguarded copy of the value domain — the same freeze class as I-1, one level further from any test. Consider emitting the census from the migration (`--pretend`, or an `artisan` census command) rather than transcribing it.

### [Residual, not this lane] R-1 — the parity gate is still in NO CI workflow (LEDGER C-26(i))
`grep -rn "EnumCheckParity\|ENUM_CHECK_PARITY" .github/workflows/` = nothing. When the S-14 leg lands it must also export `ENUM_CHECK_PARITY_PROTECTED_SEED` in the job env or the gate fails closed (proven in §5) and the job goes red on arrival. Correctly untouched here — the lane may not edit `.github/**`.

### [Residual, not this lane] R-2 — C-37(ii) F-4 and C-37(iv) R2-3 unchanged
Acknowledgements are still compared by `kind` + `predicate` only, and both pin tests still sit behind the class-wide pgsql skip. This batch left `enum-check-parity-acknowledgements.json` byte-identical, so neither is exercised by it.

### [Info] N-1 — `instrument_events_action_digest_chk` does not interact
The pre-existing cross-column CHECK on `instrument_events` (`action_key IS NULL OR semantic_digest IS NOT NULL`) is correctly unparseable as a value set, was not touched, and coexists with the three new constraints — confirmed in the `pg_constraint` dump and by `OutboundInstrumentServiceTest` passing.

---

## Gate verified — per file, per driver, all run BY PATH (never the suite)

| Check | Driver / DB | Result |
|---|---|---|
| 13 CHECK sets vs `Enum::cases()` (parser-independent) | pgsql `autoerp_treasurygate_test` | **ALL 13 EXACT** |
| nullability + defaults vs `information_schema` | pgsql | 4 nullable / 9 NOT NULL, forms correct, 4 defaults all in-set |
| `pg_constraint.convalidated` ×13 | pgsql | **all `t`** |
| census abort before DDL (NOT NULL arm) | pgsql, planted dirt | aborts, **0** constraints created |
| census abort (nullable arm, bad non-NULL) | pgsql, planted dirt | aborts, **0** constraints created (atomic rollback) |
| `down()` exactness | pgsql | 3 dropped, 13 others untouched |
| `up()` re-runnability | pgsql | idempotent, `convalidated = t` |
| sqlite no-op + self-skip | sqlite | `Tests: 19, Skipped: 19` |
| `SliceDBatch1CheckConstraintsTest` | pgsql | **OK (19, 70)** |
| `MultiPaymentTest` | pgsql | **OK (17, 65)** |
| `CreateJournalEntryTest` | pgsql | **OK (11, 29)** |
| `OutboundInstrumentServiceTest` | pgsql | **OK (14, 77)** |
| `VoucherIssuanceServiceTest` | pgsql | 12 / 155 / 1 incomplete (pre-existing) |
| `PosBridgeSpineTest` | pgsql | **OK (7, 41)** |
| `TreasuryReceiptBridgeTest` (projection, no CompanyContext) | pgsql | **OK (16, 54)** |
| `EnumCheckParityTest` armed with the seed | pgsql | **OK (11, 790)** |
| `EnumCheckParityTest` seed unset | pgsql | **FAILS CLOSED**, 11 tests / 1 failure |
| writer reproduces all three artifacts | pgsql, scratch worktree | **byte-identical**, `git diff` empty |
| baseline diff shape | — | **13 deletions, 0 additions**; central baseline + acknowledgements not in the diff |
| CI reachability | `ci.yml:1128,1148` + Treasury suite step | **whole-directory run on PG, gated on `base_ref == 'dev'`** — claim confirmed |
| freeze obligation (6th `VoucherStatus` case, no migration) | pgsql, scratch | **both gates GREEN** — obligation NOT enforced (I-1) |
| lock behaviour under Laravel's migration transaction | pgsql, 2 sessions | reader **blocked → statement timeout** (I-2) |
| diff scope | — | 9 files, **0** under `.github/` or `apps/api/app/`; lane worktree clean |

---

## Conditions

**Pre-merge (both are docblock text, ×5 files, no behaviour change):**
- **C1 — I-1.** Remove/correct *"`EnumCheckParityTest` fails the moment the two disagree, so the obligation is enforced, not merely documented."* It is demonstrably false and it is the template for the rest of Slice D.
- **C2 — I-2.** Either set `public $withinTransaction = false;` on the five migrations, or delete the two lock-benefit sentences and state that the ACCESS EXCLUSIVE lock is held through the validation scan. Record which was chosen.

**LEDGER rows (before batch 2, not merge-blocking):**
- **L1 — I-1.** Ship the frozen-case-list test (8 enums / 13 columns) and make it the Slice D batch template. Until it exists, every `cases()`-derived CHECK in the fleet is unguarded against enum growth — including N-6's `documents.status`.
- **L2 — M-1 / M-2 / M-3.** Aggregate the census throw; assert defaults ∈ set; stop transcribing the census by hand.
- **L3 — R-1.** C-26(i) CI wiring, plus the `ENUM_CHECK_PARITY_PROTECTED_SEED` job env, or the parity gate goes red the day it lands.
- **L4.** Correct `BRIEF-D2-slice-d-batch1.md` before batch 2 reuses it: 13 columns / baseline 175, `PaymentType` = 7, `to_status` nullable.
- **L5 (5 lines, writer docblock).** Record that a *symlinked* `vendor` silently collapses the population from 245 to 2 — the enum-FILE-PATH boundary resolves through the symlink to the other worktree. Any future gate reproducing the baseline will hit this.

## What to fix before merge

Correct the two false docblock paragraphs (C1: the enum-growth obligation is **not** enforced — I proved both gates stay green; C2: the `NOT VALID`/`VALIDATE` split gives no lock benefit inside Laravel's migration transaction — I proved a reader blocks). The 13 constraints themselves, their value sets, nullability, census, `down()`, re-runnability and the ratchet artifacts are all correct and reproduced independently.

---

*Evidence DB `autoerp_treasurygate_test` (PG 5433) left in place for reproduction; `autoerp_treasurygate2_test` and the scratch worktree were removed. No branch file was modified; no merge, no push, no stash.*
