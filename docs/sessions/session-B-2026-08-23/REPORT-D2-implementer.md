Lane B2-4 / Slice D batch 1 is complete and committed. Everything below is measured, not inferred.

---

## 1. Branch / worktree / commit

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-d2-slice-d-batch1`
- **Branch:** `fix/sb2-d2-slice-d-batch1` (base `68de40b44`; C-26 confirmed on dev — `git log -1 -- .../PgValueSetCheckReader.php` = `cf411e1a5 fix(sb2-c26)`)
- **Commit:** `3535d55b1` — `feat(sb2-d2): Slice D batch 1 — 13 money/fiscal enum CHECKs in the DATABASE (baseline 188 → 175)`
- Working tree clean. **Nothing merged, nothing pushed.** Throwaway DB `autoerp_d2_test` (PG 5433) left in place for reviewer reproduction.

## 2. ⚠ The brief's arithmetic is wrong — the batch is 13 columns, not 12; the baseline lands at 175, not 176

The scope table has **12 rows**, but row 12 (`instrument_events` `from_status` / `to_status`) is **two columns**, two register rows (`enum-check-parity-register.md:138,139`) and two baseline keys. `EnumCheckParityTest` reported **13 STALE**, so `188 − 13 = 175`. I implemented all 13 (dropping one would leave `to_status` unconstrained while `from_status` is constrained — incoherent) and flagged it in the commit message and in the `instrument_events` migration docblock. **Shrink is always allowed against the seed** (mirror YAML re-pin trigger #1 — no owner re-pin needed).

Two more brief/reality mismatches, both resolved in favour of the code:
- Brief says `PaymentType` (9). It has **7** cases. Values come from `PaymentType::cases()`, so the SQL is right by construction.
- Brief says `from_status` "likely nullable". `information_schema` says **`to_status` is nullable too**; both got the `IS NULL OR` form. Nullable set (verified live): `journal_entries.journal_code`, `payments.origin`, `instrument_events.from_status`, `instrument_events.to_status`.

## 3. Red → green evidence (by path, one file per invocation)

**NEW liveness pin — RED (untouched base, no migrations):**
```
FFFFFFFFFFFFF.....F                        19 / 19 (100%)
Tests: 19, Assertions: 32, Failures: 14.
 1) …"vouchers.status"   vouchers.status accepted the out-of-set value 'ZZ'; chk_vouchers_status_enum is absent or not enforcing.
 …
13) …"instrument_events.to_status"  … chk_instrument_events_to_status_enum is absent or not enforcing.
14) test_every_constraint_exists_and_was_validated  chk_vouchers_status_enum does not exist on vouchers.
```
**GREEN (post-migrations, post-Pint/PHPStan refactor):** `OK (19 tests, 70 assertions)` · **sqlite self-skip:** `Tests: 19, Skipped: 19`.

**Parity gate `tests/Architecture/EnumCheckParityTest.php` (armed, `ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf`):**
- untouched base: `OK (11 tests, 829 assertions)`
- with migrations, before baseline edit: **`STALE baseline entries (13 …)`** listing exactly the 13 keys
- after baseline edit: **`OK (11 tests, 790 assertions)`** (790 < the brief's "≥800" purely because the baseline shrank by 13)
- **arming proven** — same run with the seed unset: `the_parity_artifacts_never_grow_against_the_owner_pinned_seed … FAILS CLOSED`, `Tests: 11, Failures: 1`.

**`EnumCheckParityDetectorLivenessTest.php`:** `OK (47 tests, 102 assertions)` (matches the C-26 record).

**Regression, 5 Feature files, before/after — identical** (base counts taken on a reconstructed untouched base by moving the 5 migration files to `/tmp`; **no `git stash`**):

| file | base | after |
|---|---|---|
| `tests/Feature/Voucher/VoucherIssuanceServiceTest.php` | 12 tests, 155 assertions, 1 incomplete | identical |
| `tests/Feature/Accounting/CreateJournalEntryTest.php` | OK (11, 29) | identical |
| `tests/Feature/Treasury/MultiPaymentTest.php` | OK (17, 65) | identical |
| `tests/Feature/Document/CreateDocumentTest.php` | OK (18, 102) | identical |
| `tests/Feature/Treasury/InstrumentLifecycleReceiveTest.php` | OK (8, 29) | identical |

**Other gates:** `php tools/feature-lane-manifest-check.php` → `OK … EXIT=0`. Pint `{"result":"pass"}`. PHPStan (6 changed files, live-DB env) → `[OK] No errors` (first pass found 4 real `ternary.alwaysFalse` on the all-NOT-NULL tables; fixed by extracting `violationPredicate()` / `checkPredicate()` helpers, not by suppression).

## 4. Files changed (no `app/` production code, no `.github/**`)

```
A apps/api/database/migrations/tenant/2026_08_25_130100_add_enum_check_constraints_to_vouchers.php           (204)
A apps/api/database/migrations/tenant/2026_08_25_130200_add_enum_check_constraints_to_journal_entries.php    (196)
A apps/api/database/migrations/tenant/2026_08_25_130300_add_enum_check_constraints_to_payments.php           (204)
A apps/api/database/migrations/tenant/2026_08_25_130400_add_enum_check_constraints_to_documents.php          (192)
A apps/api/database/migrations/tenant/2026_08_25_130500_add_enum_check_constraints_to_instrument_events.php  (206)
A apps/api/tests/Feature/Treasury/SliceDBatch1CheckConstraintsTest.php                                       (290)
M apps/api/tests/Architecture/baselines/enum-check-parity-baseline.json     (-13, no additions)
M apps/api/tests/Architecture/baselines/enum-check-parity-register.md       (13 verdict rows + 4 denominators)
M apps/api/tests/feature-lane-manifest.json                                 (Treasury classes 120 → 121 + note)
```
Untouched: `enum-check-parity-central-baseline.json` (byte-identical, 11), `enum-check-parity-acknowledgements.json` (md5 `1162797ff6d8bf921bee8a476807a175` before **and** after), `docs/handoff/progress/slice-d-parity.progress.yaml`.

Constraint shape (per `pg_constraint`, all `convalidated = t`): `chk_{table}_{column}_enum`, NOT NULL columns `CHECK (col IN (…))`, nullable columns `CHECK ((col IS NULL) OR (col IN (…)))` — **C-37(iii) honoured, no `IS NOT NULL AND`, no compound CHECK.**

## 5. MIGRATION-BEARING — the 13 per-tenant census queries

Paste into a `tenants:run` loop **before** the fleet migration. Every one must return **zero rows**; a non-empty result names a raw writer that must be found first.

```sql
SELECT COALESCE(status,'<NULL>'), COUNT(*) FROM vouchers
 WHERE status IS NULL OR status NOT IN ('issued','partially_redeemed','fully_redeemed','expired','voided') GROUP BY status;
SELECT COALESCE(source,'<NULL>'), COUNT(*) FROM vouchers
 WHERE source IS NULL OR source NOT IN ('refund','exchange_surplus','goodwill','loyalty_credit','gift_card_purchase','promotional') GROUP BY source;
SELECT COALESCE(voucher_kind,'<NULL>'), COUNT(*) FROM vouchers
 WHERE voucher_kind IS NULL OR voucher_kind NOT IN ('MPV','SPV') GROUP BY voucher_kind;
SELECT COALESCE(redemption_mode,'<NULL>'), COUNT(*) FROM vouchers
 WHERE redemption_mode IS NULL OR redemption_mode NOT IN ('bearer','customer_bound') GROUP BY redemption_mode;
SELECT COALESCE(status,'<NULL>'), COUNT(*) FROM journal_entries
 WHERE status IS NULL OR status NOT IN ('draft','posted','reversed') GROUP BY status;
SELECT COALESCE(journal_code,'<NULL>'), COUNT(*) FROM journal_entries
 WHERE journal_code IS NOT NULL AND journal_code NOT IN ('VT','AC','BQ','CA','EF','OD') GROUP BY journal_code;
SELECT COALESCE(status,'<NULL>'), COUNT(*) FROM payments
 WHERE status IS NULL OR status NOT IN ('pending','completed','failed','reversed') GROUP BY status;
SELECT COALESCE(payment_type,'<NULL>'), COUNT(*) FROM payments
 WHERE payment_type IS NULL OR payment_type NOT IN ('document_payment','advance','refund','credit_application','supplier_payment','pos','reversal') GROUP BY payment_type;
SELECT COALESCE(origin,'<NULL>'), COUNT(*) FROM payments
 WHERE origin IS NOT NULL AND origin NOT IN ('pos','web_admin','mobile','api','unknown_legacy','back_office') GROUP BY origin;
SELECT COALESCE(type,'<NULL>'), COUNT(*) FROM documents
 WHERE type IS NULL OR type NOT IN ('quote','sales_order','purchase_order','invoice','credit_note','delivery_note','return_note','expense','supplier_invoice','supplier_credit_note','income','purchase_rfq','correcting_entry') GROUP BY type;
SELECT COALESCE(event_type,'<NULL>'), COUNT(*) FROM instrument_events
 WHERE event_type IS NULL OR event_type NOT IN ('created','issued','details_updated','custody_transferred','remitted','cleared','bounced','re_presented','cancelled') GROUP BY event_type;
SELECT COALESCE(from_status,'<NULL>'), COUNT(*) FROM instrument_events
 WHERE from_status IS NOT NULL AND from_status NOT IN ('received','in_transit','deposited','clearing','cleared','bounced','expired','cancelled','collected') GROUP BY from_status;
SELECT COALESCE(to_status,'<NULL>'), COUNT(*) FROM instrument_events
 WHERE to_status IS NOT NULL AND to_status NOT IN ('received','in_transit','deposited','clearing','cleared','bounced','expired','cancelled','collected') GROUP BY to_status;
```

**Fleet-abort risk analysis.** Each migration runs the same predicate **inside `up()`** and throws a `RuntimeException` naming table, column, offending value and row count before any DDL — so a dirty tenant aborts with a diagnosable message instead of a blind PG DDL failure, and `VALIDATE` cannot fail on a row the census already cleared. All four behaviours proven live on `autoerp_d2_test`:
- planted `journal_entries.status='abolished'` → `ABORTED: Cannot create chk_journal_entries_status_enum: journal_entries.status carries 1 row(s) with the unknown value "abolished"…`, **0 constraints created**;
- planted legacy `journal_code = NULL` → census tolerates it, both constraints created, `convalidated=true`, legacy row survives (this is the C-37(iii) form doing its job);
- `down()` drops exactly the 13 (16 → 3 pre-existing → 16);
- `up()` is re-runnable (ran twice, still 16, `not validated: 0`).

Residual fleet risk is **low**: a grep of `app/` for `DB::table('vouchers'|'journal_entries'|'payments'|'documents'|'instrument_events')` finds 7 real call sites, all reads except `BackfillLocationAttributionCommand.php:63-104`, which writes only `location_id`. No production raw writer touches any of the 13 columns; every write goes through an Eloquent enum cast.

## 6. Register rows — before → after

| table.column | before | after |
|---|---|---|
| `vouchers.status` / `.source` / `.voucher_kind` / `.redemption_mode` | MISSING (baselined) ×4 | **COVERED** ×4 |
| `journal_entries.status` | MISSING | **COVERED** |
| `journal_entries.journal_code` | MISSING | **COVERED · null-guarded** |
| `payments.status` / `.payment_type` | MISSING ×2 | **COVERED** ×2 |
| `payments.origin` | MISSING | **COVERED · null-guarded** |
| `documents.type` | MISSING | **COVERED** |
| `instrument_events.event_type` | MISSING | **COVERED** |
| `instrument_events.from_status` / `.to_status` | MISSING ×2 | **COVERED · null-guarded** ×2 |

Denominators: tenant baseline **188 → 175**; COVERED **55 → 68**; MISSING **188 → 175**; `*status`-suffixed covered **16 → 21 of 75**. None read NARROWER/WIDER; none read `NOT VALID`. Baseline diff is exactly **13 deletions, zero additions**.

## 7. ci.yml allowlist line to report — **none is required**

`SliceDBatch1CheckConstraintsTest` lives in `tests/Feature/Treasury`, which `treasury-spine-pgsql` runs as a **whole directory** on a real PostgreSQL service (`.github/workflows/ci.yml:1124` job, step `PG-only invariants — Treasury Feature suite` → `./vendor/bin/phpunit tests/Feature/Treasury`), and that job's `if:` gates on `base_ref == 'dev'` — it is **not** parked behind `vars.SELF_HOSTED_RUNNER_READY`. The B-3 precedent (appending to the `backend-test-pgsql` `--filter`) exists for classes whose group is parked; this one is not. No `.github/**` edit made or needed. If the gate nevertheless wants belt-and-braces coverage in `backend-test-pgsql`, the append is `|SliceDBatch1CheckConstraintsTest` immediately before `)::/` on `ci.yml:998` — but it would be redundant, and I recommend against it.

Manifest updated instead: `tests/feature-lane-manifest.json` Treasury `classes` 120 → 121 with the reason and the "no `--filter` append required" rationale in the note (informational for lane groups; checker EXIT=0 both before and after).

## 8. Residuals / adjacent defects seen but NOT touched

1. **Brief defects (above):** 12-rows-vs-13-columns arithmetic (`176` should read `175`), `PaymentType (9)` should read `(7)`, `to_status` nullability unflagged. Worth correcting in the brief before batch 2 reuses the template.
2. **C-37(ii) F-4 still open** — the anti-growth ceiling compares acknowledgements by `kind` + `predicate` only, so `intended_set` / `enum_cases_at_acknowledgement` / `pinned_set` / `constraint` remain rewritable. Out of lane scope; unaffected by this batch (acknowledgements byte-identical).
3. **C-37(iv) R2-3** — the two pin tests still sit behind `EnumCheckParityTest`'s class-wide pgsql skip; unchanged here.
4. `instrument_events` carries a pre-existing cross-column CHECK `instrument_events_action_digest_chk` (`action_key IS NULL OR semantic_digest IS NOT NULL`). Correctly unparseable as a value set, untouched, and it does not interact with the three new constraints.
5. `payments.payment_type` and `journal_entries.status` carry PG column **defaults** (`'document_payment'`, `'draft'`) that are inside their enums — no conflict, noted only because a future enum rename would have to move the default too.
6. `docs/handoff/progress/slice-d-parity.progress.yaml` `enum_check_parity_pin_tag: ci-pin/enum-check-parity-r1` is still marked **NOT YET CREATED** — an owner ops step (O-31), not blocked by this lane.

---

Fix round done and committed. Nothing merged, nothing pushed; working tree clean.

## SHA

`824ad7118` — `fix(sb2-d2): r1 fix round — retract two false docblock claims, ship the freeze pin that actually enforces them`, on top of `3535d55b1`, branch `fix/sb2-d2-slice-d-batch1`, worktree `.worktrees/sb2-d2-slice-d-batch1`. 7 files, +596/−57.

**Zero executable change to the five migrations.** `git diff -U0 3535d55b1..HEAD -- database/migrations/tenant/` filtered to non-comment lines returns **nothing** — every one of the 75 changed lines per file is a `*` docblock line. The 13 constraints, value sets, census, `down()` and re-runnability are byte-for-byte what both r1 gates verified.

## 1+2. Docblock edits — file:line (all five files, identical blocks)

| edit | vouchers | journal_entries | payments | documents | instrument_events |
|---|---|---|---|---|---|
| **C2/F-1** "NO LOCK RELIEF AS EXECUTED" (replaces the two deleted lock bullets) | `:36` | `:40` | `:42` | `:42` | `:44` |
| **C2** "THE TRANSACTION IS KEPT DELIBERATELY" (parent ruling + trade) | `:48` | `:52` | `:54` | `:54` | `:56` |
| **C1/F-2** "`EnumCheckParityTest` DOES NOT ENFORCE THAT OBLIGATION" | `:105` | `:99` | `:106` | `:96` | `:108` |
| **F-2** "REMOVING A CASE IS EQUALLY MIGRATION-BEARING" (→ LEDGER C-38) | `:121` | `:115` | `:122` | `:112` | `:124` |
| **F-3** "NOT-NULL-DRIFT DETECTOR" (census rationale) | `:219` | `:211` | `:219` | `:207` | `:221` |

`grep -n "brief ACCESS EXCLUSIVE\|does not block readers\|enforced, not merely documented\|does not scan the table\|SHARE UPDATE EXCLUSIVE"` over the five files → **no matches**. All four false-claim strings are gone.

Substance now stated: `Migration::$withinTransaction` defaults true and `Migrator.php:449` honours it, so census + `ADD … NOT VALID` + `VALIDATE` hold ACCESS EXCLUSIVE until COMMIT (r1 proof: a reader blocked to `statement_timeout`); net profile equals a plain `ADD CONSTRAINT`; use a maintenance window on non-small tenants; the transaction is **kept** because atomicity (failed census → zero constraints, proven) beats lock relief on green-field tenants, and the split is retained only as the idiom for a future `public $withinTransaction = false;` — at which point the abort stops being atomic. F-3 now says the NOT NULL arm deliberately over-rejects as a *drift detector*, and explicitly that `col IN (…)` yields NULL for NULL input so VALIDATE would **not** have failed.

## 3. Freeze test — RED → GREEN

`apps/api/tests/Feature/Treasury/SliceDBatch1EnumFreezeTest.php`, 274 lines, **driver-free** (no DB, no `RefreshDatabase`, no pgsql guard → it does **not** self-skip on SQLite, unlike its sibling). Pins the 13 value sets as **string literals** keyed by constraint name (12 distinct enums; `InstrumentStatus` governs two columns), transcribed from the migrations' census SQL, asserted `===` against `Enum::cases()` values.

**RED** — added `case Escheated = 'escheated';` to `VoucherStatus` in the worktree (backed up to `/tmp` and restored by `cp`; **no stash**; `git status -- apps/api/app/` clean afterwards, md5 `94e43072c65f970743e0168211aa4274` restored). Default SQLite suite:
```
F..............................                     31 / 31 (100%)
1) …::test_enum_still_matches_the_value_set_frozen_into_its_check with data set "chk_vouchers_status_enum"
App\Modules\Voucher\Domain\Enums\VoucherStatus changed after Slice D batch 1 froze it into `chk_vouchers_status_enum`.
  - a case you ADDED will be rejected there with SQLSTATE 23514 → ship a WIDENING migration;
  - a case you REMOVED leaves the DB WIDER than the enum → ship a NARROWING migration plus a
    per-tenant census (the O-31 ceiling will not let you baseline it away);
  - a pure REORDER needs no migration, only a deliberate re-pin.
Then re-pin the list in …::frozenValueSets(). Do NOT 'fix' this by regenerating the pin from the enum…
+    5 => 'escheated',
Tests: 31, Assertions: 36, Failures: 1.
```
That is exactly the failure both r1 gates proved nothing in the tree could catch.

**GREEN** after restore: `OK (31 tests, 36 assertions)` on sqlite **and** `OK (31 tests, 36 assertions)` on pgsql (no skips on either driver).

Also included (treasury M-2): the four PG column defaults asserted inside their frozen sets — `journal_entries.status='draft'`, `payments.payment_type='document_payment'`, `payments.status='pending'`, `vouchers.voucher_kind='MPV'` — driver-free, because a default outside its set is a 23514 bomb on every INSERT that omits the column and neither the census (existing rows) nor the parity gate (CHECK vs enum) can see it. Plus a duplicate-transcription guard and a "the pin covers all 13" completeness assertion.

Manifest: Treasury `classes` 121 → 122 with the rationale; `feature-lane-manifest-check.php` **EXIT=0** (1429 classes / 74 groups).

## 4. Promoter block (replaces §5 of the r1 report)

**Command — MANDATORY.** Deploy with **`php artisan tenants:migrate-rolling --force`**, never bare `tenants:migrate`. Verified in-tree: `apps/api/docker/entrypoint.sh:141` already uses it, and `RollingTenantMigrationCommand.php:91` is the continue-and-collect isolate (`// Isolate: never let one tenant's failure abort the fleet.`). Stancl's `tenants:migrate` is fail-fast — one dirty tenant's `RuntimeException` would leave the rest of the fleet unmigrated.

**Lock warning.** The migrations run inside Laravel's migration transaction, so each table is ACCESS EXCLUSIVE (readers **and** writers blocked) for the whole census + ADD + VALIDATE. Small on green-field tenants; schedule a maintenance window for any tenant with large `documents` / `payments` / `journal_entries`.

**PRE-flight:** the 13 census queries from the r1 report §5 (unchanged, still correct — machine-checked by the treasury gate).

**POST-flight — the only proof VALIDATE ran** (a `NOT VALID` constraint reads `COVERED` in the parity gate, fiscal F-4b):
```sql
SELECT conname, convalidated
  FROM pg_constraint
 WHERE conname IN ('chk_vouchers_status_enum','chk_vouchers_source_enum','chk_vouchers_voucher_kind_enum',
                   'chk_vouchers_redemption_mode_enum','chk_journal_entries_status_enum',
                   'chk_journal_entries_journal_code_enum','chk_payments_status_enum',
                   'chk_payments_payment_type_enum','chk_payments_origin_enum','chk_documents_type_enum',
                   'chk_instrument_events_event_type_enum','chk_instrument_events_from_status_enum',
                   'chk_instrument_events_to_status_enum')
 ORDER BY conname;
```
Expect **13 rows, `convalidated = t` on every one**. (The looser `WHERE conname LIKE 'chk_%_enum'` returns **16 | 16** on a fully-migrated tenant — the three pre-existing `documents` CHECKs match the pattern; use the explicit list if you want the batch-scoped 13.) Measured on `autoerp_d2_test`: 16/16 `t`, the 13 batch constraints among them.

**Complete defaults census — all 13 columns** (was 4 in the r1 report, fiscal F-5). From `information_schema` on the migrated schema:

| column | nullable | default |
|---|---|---|
| `documents.type` | NO | — |
| `instrument_events.event_type` | NO | — |
| `instrument_events.from_status` | YES | — |
| `instrument_events.to_status` | YES | — |
| `journal_entries.journal_code` | YES | — |
| `journal_entries.status` | NO | `'draft'` |
| `payments.origin` | YES | — |
| `payments.payment_type` | NO | `'document_payment'` |
| `payments.status` | NO | `'pending'` |
| `vouchers.redemption_mode` | NO | — |
| `vouchers.source` | NO | — |
| `vouchers.status` | NO | — |
| `vouchers.voucher_kind` | NO | `'MPV'` |

9 columns carry no default; the 4 that do are all in-set and are now **asserted** by the freeze test rather than merely observed.

## 5. Re-run results (by path, one file per invocation, never the suite)

| check | driver | result |
|---|---|---|
| `SliceDBatch1EnumFreezeTest` | sqlite | **OK (31, 36)** |
| `SliceDBatch1EnumFreezeTest` | pgsql | **OK (31, 36)** |
| `SliceDBatch1CheckConstraintsTest` | pgsql | **OK (19, 70)** |
| `EnumCheckParityTest` armed (`ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf`) | pgsql | **OK (11, 790)** |
| `pint --test` (7 touched files) | — | `{"result":"pass"}` |
| PHPStan (5 migrations + 2 tests, live-DB env) | — | `[OK] No errors` |
| `tools/feature-lane-manifest-check.php` | — | **EXIT=0** |

## ⚠ One thing the parent must resolve: the `C-38` id collides

You told me to reference **LEDGER C-38**, and I wrote that into all five docblocks. But at my base (`68de40b44`) the LEDGER ends at **C-37**, and the main checkout — Session A, already merged `623b6fab1` — has allocated **C-38 to the W2-1 POS `X-Company-Id` residual**. So `C-38` is taken by a different row. If the R-D2/R-D3/R-D8 coupling lands as C-39 (or anything else), it is a one-command fix on the branch:
`sed -i '' 's/LEDGER C-38 couples/LEDGER C-39 couples/' apps/api/database/migrations/tenant/2026_08_25_130[1-5]*.php` — plus the same string in `SliceDBatch1EnumFreezeTest.php:53`. Say the word and I'll do it; I did not renumber unilaterally.

## Residuals still open (not addressed here, by scope)

- **treasury M-1** — the census throws on the first offending value of the first offending column; a tenant dirty in three columns needs three fix-and-rerun cycles. Aggregating the throw is a code change; left for the L2 lane.
- **treasury M-3** — the 13 census queries are still hand-transcribed prose in the docblocks (machine-checked correct at r1, but a second unguarded copy of the value domain). The freeze test now pins the *sets*, which removes most of the drift risk, but not the transcription itself.
- **treasury L5** — the writer's docblock should record that a *symlinked* `vendor` collapses the registry population from 245 to 2. That file is outside this lane's scope.
- **fiscal F-6 / R3, C-26(i), C-37(ii)/(iv), F-7** — unchanged, all inherited and out of lane scope (`.github/**` forbidden).