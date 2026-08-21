# VAT period last-day boundary: the POS arm silently drops the final day of every period

- **Found**: 2026-08-21, by the adversarial gate on the G-4 lane (`fix/g4-vat-pos-refund-netting`).
- **Status**: OPEN — **pre-existing**, NOT introduced by G-4, and deliberately NOT fixed in that lane (rule 4, scope).
- **Severity**: P2 for any VAT-filing tenant that sells through the POS. Under-declares output VAT.
- **Needs its own lane**: the fix changes a repository predicate whose *callers* pass date-only strings, so the contract between caller and repository has to move together.

## Defect

`apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:105`

```php
->whereBetween('r.posted_at', [$dateFrom, $dateTo])
```

`pos_receipts.posted_at` is `timestamp without time zone`. `$dateTo` arrives as a
date-only string (`'2026-02-28'`), which PostgreSQL widens to the *instant*
`2026-02-28 00:00:00`. `BETWEEN` is inclusive of that instant and nothing after
it, so **every POS receipt posted after midnight on the last day of the period is
excluded from the declaration.**

Confirmed against the live schema and engine (PG 16, `autoerp_g4_vat_test`):

```
SELECT '2026-02-28 09:00:00'::timestamp BETWEEN '2026-02-01' AND '2026-02-28';  -- f
SELECT '2026-02-28 00:00:00'::timestamp BETWEEN '2026-02-01' AND '2026-02-28';  -- t
SELECT '2026-02-28'::date            BETWEEN '2026-02-01' AND '2026-02-28';     -- t
```

```
 table_name   |  column_name  |          data_type
--------------+---------------+-----------------------------
 documents    | document_date | date
 pos_receipts | posted_at     | timestamp without time zone
```

### The document arm is immune

`EloquentVatDataRepository.php:41` applies the same `whereBetween` to
`d.document_date`, which is a `DATE` column (`2025_11_30_080000_create_documents_table.php:21`).
Date-to-date comparison has no time component, so the last day is included.
**This asymmetry is what makes the bug invisible**: invoices on the 28th are
declared, POS receipts on the 28th are not, and the two arms are `UNION ALL`ed
into one number that looks plausible.

### The dropped day is declared in NO period

TN periods are generated as first-of-month → `endOfMonth()`
(`TunisiaVatStrategy.php:34-42`), so February is `2026-02-01 .. 2026-02-28` and
March starts `2026-03-01`. The excluded window (`2026-02-28 00:00:01` through
`2026-02-28 23:59:59`) is above February's upper bound and below March's lower
bound. It is not deferred to the next filing — it is **lost**. For a monthly
filer that is roughly 1/28–1/31 of POS output VAT, permanently under-declared.
France and UK strategies build periods the same way (`FranceVatStrategy.php:58`,
`UkVatStrategy.php:49`), so this is not TN-specific.

## Callers that pass date-only bounds

Both callers hand the repository a `Y-m-d` string, which is why the fix cannot
live in the repository alone without deciding the contract:

- `apps/api/app/Modules/Taxation/Application/Services/VatPeriodManagementService.php:92-93`
  — `$period->period_start->toDateString()` / `$period->period_end->toDateString()`.
  `period_end` is cast `'date'` (`VatPeriod.php:82`), so `toDateString()` yields
  the bare last day. This is the **statutory filing path** — the one that
  produces the number sent to the tax authority.
- `apps/api/app/Modules/Taxation/Presentation/Controllers/VatReportController.php:38-39`
  — `$request->validated('date_from')` / `date_to`, the ad-hoc summary endpoint.
  Same shape, user-supplied.

## Correct shape

Half-open interval on the timestamp side; do **not** try to fix it by appending
`' 23:59:59'` (that drops sub-second rows and is wrong for `timestamptz` should
the column ever migrate):

```php
->where('r.posted_at', '>=', $dateFrom)
->where('r.posted_at', '<', Carbon::parse($dateTo)->addDay()->toDateString())
```

The document arm should be left on `whereBetween` against the DATE column, or
converted deliberately — but the two arms must not be assumed interchangeable.

## Suggested lane scope

1. Red-first test: a POS receipt at `23:59` on `period_end` must appear in that
   period's declaration and in no other. Add it to
   `apps/api/tests/Feature/Taxation/VatDataRepositoryTest.php` (already named in
   the pgsql `--filter` allowlist as of the G-4 lane, so the evidence runs in a
   gate). Must run on **PG**, since SQLite's typeless comparison does not
   reproduce the widening faithfully.
2. Fix the POS arm predicate.
3. Check whether the same date-only-vs-timestamp mismatch exists in the other
   Taxation consumers (TEJ export, withholding) before closing.
4. Decide whether previously filed periods need a restatement, or whether the
   correction simply lands in the next filing — an owner/accounting call, not an
   engineering one.

## Related

- G-4 (POS refunds must net out of the declaration) fixed the *sign* of the POS
  arm at `EloquentVatDataRepository.php:110-111`. This ticket is the *window* of
  the same arm. They are independent; G-4 shipped without touching the boundary.
