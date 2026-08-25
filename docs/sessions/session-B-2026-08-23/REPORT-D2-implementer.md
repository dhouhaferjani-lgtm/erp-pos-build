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