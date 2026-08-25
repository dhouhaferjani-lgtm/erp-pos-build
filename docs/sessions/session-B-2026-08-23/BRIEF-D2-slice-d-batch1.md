# BRIEF — Lane B2-4 / Slice D batch 1: money/fiscal enum CHECKs (baseline 188 → 175 — ERRATUM: 13 columns, `from_status`/`to_status` are two)

Follow `LANE-PROTOCOL.md`. Worktree (parent creates): `.worktrees/sb2-d2-slice-d-batch1`, branch
`fix/sb2-d2-slice-d-batch1`, base = dev tip at dispatch (C-26 `fix/sb2-c26-parity-preconditions` MUST be on dev —
verify `git log --oneline -1 -- apps/api/tests/Architecture/Support/PgValueSetCheckReader.php` mentions sb2-c26).
PG 5433 `autoerp`/`autoerp_secret`, own DB `autoerp_d2_test`. Tool calls < 90 s, one test file per run, by path,
never the suite, no stash, never push. **MIGRATION-BEARING lane.**

## Scope — exactly these 13 columns (5 tables; ERRATUM: the table has 12 rows, the last row is two columns), all `MISSING` in `enum-check-parity-register.md`
| table | column | enum | nullable? (check the migration) |
|---|---|---|---|
| `vouchers` | `status` | `Voucher\Domain\Enums\VoucherStatus` (5) | |
| `vouchers` | `source` | `VoucherSource` | |
| `vouchers` | `voucher_kind` | `VoucherKind` | |
| `vouchers` | `redemption_mode` | `RedemptionMode` | |
| `journal_entries` | `status` | `Accounting\Domain\Enums\JournalEntryStatus` (3) | |
| `journal_entries` | `journal_code` | `JournalCode` (6) | likely nullable |
| `payments` | `status` | `Treasury\Domain\Enums\PaymentStatus` (4) | |
| `payments` | `origin` | `PaymentOrigin` (6) | |
| `payments` | `payment_type` | `PaymentType` (7) | |
| `documents` | `type` | `Document\Domain\Enums\DocumentType` (13) | |
| `instrument_events` | `event_type` | `Treasury\Domain\Enums\InstrumentEventType` (9) | |
| `instrument_events` | `from_status` / `to_status` | `InstrumentStatus` (9) | BOTH nullable (information_schema) |

Nothing else. Do NOT touch `fiscal_periods.status`, `pos_*`, `fiscal_*` (fiscal-pos lane later), or any column
already COVERED/INTENDED/COMPOSITE. Do NOT widen or alter any existing CHECK.

## Migration shape — ONE migration per table (5 files), copy N-6 `2026_08_24_100100_add_status_check_constraint_to_documents.php` and add the NOT VALID/VALIDATE idiom
Per column, in `up()`, pgsql-guarded, `Schema::hasTable` guarded:
1. **Pre-flight census INSIDE the migration** (N-6 pattern): `SELECT COALESCE(col,'<NULL>'), COUNT(*) … WHERE col NOT IN (values)` — for a NULLABLE column exclude NULL from the violation set (`col IS NOT NULL AND col NOT IN (…)`) and write the CHECK as `(col IS NULL OR col IN (…))`; for NOT NULL columns keep `col IS NULL OR col NOT IN` as the violation predicate. Abort the tenant with a `RuntimeException` naming table/column/value/count (never let PG fail the DDL blind).
2. `ALTER TABLE t DROP CONSTRAINT IF EXISTS chk_t_col_enum;`
3. `ALTER TABLE t ADD CONSTRAINT chk_t_col_enum CHECK (…) NOT VALID;` — brief lock only.
4. `ALTER TABLE t VALIDATE CONSTRAINT chk_t_col_enum;` — separate statement (SHARE UPDATE EXCLUSIVE, does not block
   writers). Both in the same migration; the census in (1) is what guarantees (4) cannot fail on a row.
Constraint name pattern `chk_{table}_{column}_enum` (N-6 precedent). Values derived from `Enum::cases()`, never
hand-listed; docblock carries BOTH N-6 paragraphs (FROZEN AT MIGRATION-RUN TIME → a new enum case needs its own
widening migration) and the per-tenant census SQL for the promoter. `down()` drops the constraints.
Single-column value sets ONLY — no compound/AND CHECKs (the parser refuses them by design after C-26).

## Ratchet + register (the point of the lane)
- Run the gate on your throwaway DB: `EnumCheckParityTest` will report the 12 keys STALE → delete exactly those 12
  keys from `enum-check-parity-baseline.json` (188 → **175**); regenerate the register with
  `tests/Architecture/Support/write-enum-check-parity-baseline.php` (usage in its header) and confirm the 12 rows
  read COVERED (not NARROWER/WIDER — if any reads NARROWER/WIDER you got the enum or nullability wrong; fix the
  migration, never the baseline). Central baseline untouched (11). Acknowledgements untouched.
- Export `ENUM_CHECK_PARITY_PROTECTED_SEED=$(git rev-parse da5ae1379)` locally so the anti-growth ceiling runs
  (it fails closed otherwise); shrink is allowed against the seed. Expected armed result ~`OK (11 tests, ≥800 assertions)`.
- NEW liveness test `tests/Feature/Architecture/… ` NO — put it at `tests/Feature/Treasury/SliceDBatch1CheckConstraintsTest.php`
  (PG-only, self-skip on sqlite): for each of the 5 tables, a raw `DB::table()->insert/update` with an out-of-set
  value MUST raise SQLSTATE 23514 naming `chk_{table}_{column}_enum`; a NULL on the nullable columns MUST pass;
  and `pg_constraint` shows `convalidated = true` for all 12 (proves VALIDATE ran). Red first: on the untouched
  base the bad insert succeeds.
- Regression by path: `tests/Architecture/EnumCheckParityTest.php` (armed) and `EnumCheckParityDetectorLivenessTest.php`
  on PG; plus one Feature file per touched table that already writes those columns on PG (pick from
  `tests/Feature/Treasury/*Payment*`, `*Instrument*`, `tests/Feature/Voucher/*`, `tests/Feature/Accounting/CreateJournalEntryTest.php`,
  `tests/Feature/Document/*`) — a CHECK that rejects a legitimate enum write shows up here.
- `php tools/feature-lane-manifest-check.php`; update the manifest as instructed. ci.yml allowlist: REPORT the
  append line for the new PG-only class (B-3 precedent) — do not edit `.github/**`.

## Deliverable
LANE-PROTOCOL §Deliverable + MIGRATION-BEARING flag with the 12 per-tenant census queries in one block (the
promoter pastes them into a `tenants:run` loop), the baseline diff (exactly 12 deletions), the register rows
before/after, and the armed gate output. Commit `feat(sb2-d2): …`. Do NOT merge.
