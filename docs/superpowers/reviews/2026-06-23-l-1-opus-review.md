# L-1 Opus Adversarial Review — Enum-Backed Status Literals

Item: L-1
Commit: fdad2e8131586a7391c8e4acdf7a3e2491c94ca3
Reviewer: Opus (independent cross-model adversarial pass; the prior "opus-fallback" review was written by Codex and self-labeled `opus-review: PENDING`)
Date: 2026-06-23

## Summary

The change swaps six audited magic-string status literals for backed-enum cases
in four files (`AgedReceivablesService`, `PaymentAllocationService`,
`OpeningBalanceBatch`, `FiscalPeriodResolverService`) and adds a static
architecture regression test. The behavioral claim holds: all three enums are
`: string`-backed, every replaced case's `->value` is byte-identical to the
literal it replaced, and Laravel 12 converts a `BackedEnum` to its scalar at the
query-builder binding boundary (`Builder::addBinding` → `castBinding` →
`enum_value()`, vendor `Query/Builder.php:4585-4612`), independent of model
casts. So the generated SQL bindings are unchanged. No money/precision, sign,
event-immutability, GL, or migration surface is touched. Red-first is genuine
(the pre-commit parent contains all six literals verbatim).

However, the work-list acceptance criterion is "Enum constants/values are used
**consistently**," and the commit leaves an enum-backed literal unconverted **in
one of the files it edited**, which the new regression test does not cover. That
is a real (low-impact) miss against the item's own stated bar, not a nit.

## BLOCKER

None. No fiscal/money/data-integrity defect, no migration.

## HIGH

None. The functional-equivalence claim is verified end-to-end (enum values match
literals; binding-layer conversion confirmed in vendor source; PHPStan L8 clean
on all four files; the regression test runs green: 6 passed / 12 assertions).

## MEDIUM

1. **Inconsistent conversion within a touched file — leftover `'PENDING'`
   magic string violates the "used consistently" acceptance criterion.**
   `app/Modules/Accounting/Domain/OpeningBalanceBatch.php:234`:
   ```php
   public function getPendingRowCount(): int
   {
       return $this->rows()->where('status', 'PENDING')->count();
   }
   ```
   This sits between `getValidRowCount()` (converted to
   `OpeningImportRowStatus::Valid`, line 218) and `getInvalidRowCount()`
   (converted to `::Invalid`, line 226), uses the same enum
   (`OpeningImportRowStatus::Pending = 'PENDING'`), in the same method cluster of
   the same file this commit edited. The work-list acceptance criteria
   (`work-list.md`, L-1) require "Enum constants/values are used consistently"
   and an "architecture/static test [that] covers the agreed rule." The new
   `EnumBackedStatusLiteralTest` provider omits `'PENDING'`, so the inconsistency
   is invisible and unguarded. Functionally harmless today (the string equals the
   enum value), but it contradicts both the commit message ("Replace
   enum-backed status literals") and CLAUDE.md Rule 9. Recommend converting line
   234 and adding the `'PENDING'` case to the test provider before considering
   L-1 fully met.

## LOW

1. **Regression test is a brittle substring guard; trivial cosmetic variants
   slip through.** `tests/Architecture/EnumBackedStatusLiteralTest.php` asserts
   `assertStringNotContainsString("->where('status', 'posted')", $source)`. A
   future reintroduction written as `->where("status", 'posted')`,
   `->where('status', "posted")`, or with extra whitespace would NOT be caught.
   The test also does not assert the enum is *used* — deleting a query line
   entirely would keep it green. This is acceptable for the item's narrow stated
   purpose ("prevent the exact audited literals from returning") but provides
   weaker protection than its name implies. Consider a regex/normalized scan or a
   broader Deptrac/PHPStan rule if durable enforcement is wanted.

2. **Untouched literals in the broader Document module remain.**
   `app/Modules/Document/Domain/Document.php:688` still uses
   `->whereIn('status', ['confirmed', 'posted'])`. This is outside the four
   audited files and the explicit scope boundary ("Enum-backed statuses only" /
   the four evidence files), so it is correctly deferred — noting it for a future
   item, not blocking L-1.

3. **Module-boundary note (clean).** `PaymentAllocationService` (Treasury) and
   `FiscalPeriodResolverService` (Accounting) import enums from the Document and
   Company modules respectively. These are shared value types (enums), not model
   imports, and `DocumentStatus` was already imported/used in
   `PaymentAllocationService` (line 229) pre-change. No boundary violation.

## Verification performed

- Confirmed all three enums are string-backed with values matching the replaced
  literals: `PeriodStatus::Open='open'`, `OpeningImportRowStatus::Valid='VALID'`,
  `Invalid='INVALID'`, `DocumentStatus::Posted='posted'`, `Confirmed='confirmed'`.
- Confirmed the three models cast `status` to their enum (`Document.php:163`,
  `FiscalPeriod.php:73`, `OpeningBalanceImportRow.php:66`).
- Confirmed Laravel 12.58 converts `BackedEnum` bindings to scalars at
  `addBinding`/`castBinding`/`enum_value` (vendor source), so SQL is unchanged
  regardless of casts — the swap is behavior-preserving.
- Confirmed red-first is real: parent commit `fdad2e813^` contains all six
  literals verbatim at the cited lines.
- Ran `EnumBackedStatusLiteralTest` → 6 passed, 12 assertions (green).
- Ran PHPStan L8 on all four changed files → no errors.
- Confirmed no `(float)` cast, `number_format`, or money/quantity math in the
  diff; no event class renamed/restructured; no migration.

## Verdict

APPROVE-WITH-MINOR-EDITS — The implementation is functionally correct and safe;
the binding-layer equivalence holds. But the item is marked DONE while leaving
the `'PENDING'` literal unconverted in a file it edited, which fails the
work-list's own "used consistently" acceptance criterion and is not covered by
the regression test. Convert `OpeningBalanceBatch::getPendingRowCount()` and
extend the test provider, then this is a clean APPROVE.
