# M-5 Opus Adversarial Review — Partner Non-Negative Balance Constraints

Date: 2026-06-23
Reviewer: Opus (cross-model adversarial pass; this item was merged to `dev` with Codex-only review)
Commit: `0bc6b331d` — "Phase 0.1.22: Enforce non-negative partner liability caches"
Item: M-5 (`docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md`)

## Summary

The implementation is fundamentally sound and matches the locked non-negative-magnitude
convention. The migration is in the correct `migrations/tenant/` directory, is PG-guarded,
normalizes stale negatives before adding constraints, is idempotent (`DROP CONSTRAINT IF EXISTS`
before `ADD`), and is correctly ordered after the scale-3 alignment migration (`130000` < `140000`).
The domain `saving` guard does fire through the `decimal:3` cast and would reject a negative write.
The PG-only constraint tests are meaningful and assert both metadata and direct-DB rejection.

However, the central claim "Real-PG verification is documented" rests entirely on a **manual local
run** that is **not reproducible in CI**: none of the M-5 tests execute in any CI job. The
constraint and guard can silently regress without any pipeline catching it. That is the principal
finding. Two convention/robustness issues and one stale-doc nit round it out. No fiscal/data-integrity
defect found; the migration itself is safe.

## BLOCKER

None.

## HIGH

### H-1 — None of the M-5 tests run in CI; the "verified" claim is manual-only and non-regression-proof
`PartnerMoneyPrecisionTest` lives at `apps/api/tests/Feature/Partner/PartnerMoneyPrecisionTest.php`
(the `Feature` suite). CI coverage:
- SQLite job runs `php artisan test --testsuite=Unit` only (`.github/workflows/ci.yml:274`) — the
  `Feature` suite is excluded (`phpunit.xml:8-13` defines `Unit` = `tests/Unit`, `Feature` =
  `tests/Feature`). So the two domain-guard tests never run in CI.
- The two PG jobs use hard-coded `--filter` allowlists that do **not** include
  `PartnerMoneyPrecisionTest`: `backend-test-pgsql` filter (`.github/workflows/ci.yml:548`) and
  `t6-phase0b-pgsql` filter (`.github/workflows/ci.yml:645`). So the four PG constraint tests never
  run in CI either.

Net effect: the entire M-5 test surface (domain guard + PG metadata + direct-write rejection) has
**zero CI coverage**. The "Real PostgreSQL verification passed against isolated `autoerp_m5_test`"
line in the work-list is a one-time manual run on the author's machine. A future migration squash,
a dropped constraint, or a removed `saving` hook would not be caught. Per project MEMORY, this is
the recurring "tests/Feature NEVER runs in CI" gap and the "PG CHECK constraints cannot be caught
by SQLite" gap combined. Recommendation: add `PartnerMoneyPrecisionTest` to the
`backend-test-pgsql` filter so the constraint and direct-write rejection run on real PG in CI.
Without it, the acceptance criterion "Real-PG verification is documented" is satisfied only as a
historical artifact, not as an enforced invariant.

## MEDIUM

### M-1 — Domain guard uses fragile string-prefix sign detection instead of the codebase's `bccomp` convention
`Partner.php:189`:
```php
if (str_starts_with(ltrim($value), '-')) {
```
The established sign-check pattern in this exact model and the balance service is `bccomp` at the
column scale (e.g. `Partner::hasActiveCreditLimit()` uses `bccomp($this->credit_limit, '0', 3) > 0`;
`PartnerBalanceService::liabilityMagnitude()` uses `bccomp($magnitude, '0', 3) >= 0`). A
string-prefix check is brittle: it depends on the exact textual form delivered by the `decimal:3`
cast (Laravel `asDecimal` → `BigDecimal::toScale(3, HALF_UP)`). It would, for example, treat a raw
unparsed `"-0.0001"` (negative below the column scale, before truncation) as a violation even though
the persisted scaled value rounds to `0.000` — and conversely a value like `"-0"` produced by some
path would be flagged while `bccomp` would treat it as zero. Using
`bccomp($value, '0', 3) < 0` is both more correct and consistent with the module's own convention.
Functionally correct for the values the production refresh path produces (which are already clamped
non-negative), so this is MEDIUM, not HIGH.

### M-2 — `saving` guard throws a generic `\InvalidArgumentException` from the domain layer
`Partner.php:190` throws `\InvalidArgumentException`. The convention elsewhere in the domain is a
typed domain exception (the module has `Domain/Enums`, dedicated exceptions are used for invariant
violations). A bare `\InvalidArgumentException` thrown inside an Eloquent `saving` closure will
surface as an unhandled 500 if any controller path ever sets a negative magnitude, rather than a
validated 422. This is acceptable as a last-resort invariant trap (the real entry guard is the
`liabilityMagnitude` clamp + the PG CHECK), but a typed domain exception would be cleaner and would
let presentation map it deliberately.

## LOW

### L-1 — Stale seeder doc comment now contradicts the enforced convention
`DemoPharmacySeeder.php:522-528` documents `credit_balance` as stored `(debit - credit)` "which
yields a negative value for a normal store-credit advance ... a pre-existing production sign bug."
That description predates the magnitude fix; `PartnerBalanceService::liabilityMagnitude()` now stores
`credit - debit` clamped to non-negative, and M-5 enforces non-negativity at the DB and domain
layers. With M-5 merged, a seeder writing the negative value the comment describes would now throw /
violate the CHECK. The comment should be updated to avoid future confusion; it is not a live bug
because the seeder intentionally omits `CUST-CREDIT-01`.

### L-2 — `down()` is irreversible for normalized data (documented residual, acceptable)
The `up()` normalizes stale negatives to `0` and nulls `balance_updated_at`; `down()` only drops the
constraints and cannot restore the pre-normalization values. This is correct behavior (the GL is the
source of truth and a refresh recomputes the cache), and the Codex review notes it as residual risk.
No action required; noted for completeness.

## Verification performed by this reviewer
- Read full diff (`git show 0bc6b331d`): model guard, tenant migration, 6 added tests, doc updates.
- Confirmed migration directory/PG-guard pattern matches existing tenant migrations.
- Confirmed ordering: scale-3 alignment `2026_06_22_130000_align_partner_money_fields_to_scale_3.php`
  precedes constraint migration `..._140000_...`.
- Confirmed `decimal:3` cast (`HasAttributes::asDecimal` → Brick `BigDecimal::toScale(3, HALF_UP)`)
  preserves the `-` sign, so the `saving` guard does see and reject negatives (the SQLite domain test
  is genuinely red-first: without the guard, SQLite has no CHECK and the negative would persist).
- Confirmed `receivable_balance` is intentionally NOT constrained (it is legitimately bidirectional
  per `2025_12_06_100001_add_balance_fields_to_partners.php`: "negative = we owe them").
- Confirmed `PartnerBalanceService::refreshPartnerBalance()` writes clamped non-negative magnitudes
  via `liabilityMagnitude()`, so the new guard cannot false-positive the production refresh path.
- Confirmed `booted()` override does not clash with `HasUuids`/`SoftDeletes` (they hook via
  `boot{Trait}`, not `booted`).
- [NEEDS-REAL-PG] The PG CHECK enforcement and `pg_constraint` metadata assertions are only
  executable on Postgres; the author's manual `autoerp_m5_test` run is the sole evidence and is not
  reproduced in CI (see H-1).

## Verdict

APPROVE-WITH-MINOR-EDITS — The change is correct and safe on its own merits; the migration and guard
hold up under refutation and there is no fiscal/data-integrity defect. The downgrade from APPROVE is
driven by H-1: the verification claim is real but manual and entirely outside CI, so the invariant is
not protected against regression. Recommended edits before treating M-5 as fully "done": (1) add
`PartnerMoneyPrecisionTest` to the `backend-test-pgsql` CI filter; (2) switch the guard to `bccomp`;
optionally (3) typed domain exception and (4) fix the stale `DemoPharmacySeeder` comment.
