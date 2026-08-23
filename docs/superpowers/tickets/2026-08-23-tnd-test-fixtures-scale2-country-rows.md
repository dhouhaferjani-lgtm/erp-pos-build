# Ticket — 8 fiscal test fixtures run TND at scale 2 (countries row inserted without `currency_decimal_places`)

> **Raised by:** B-6(ii) gate round 2, finding **r2-6**, 2026-08-23.
> **For the parent's queue — NOT the B-6(ii) lane's scope** (rule 4). Filed on the gate's own recommendation.
> **Test-integrity only. No production exposure** — see "Why production is safe", which the gate verified rather than assumed.

---

## Mechanism

`CurrencyScaleResolver::getScale()` (`apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:36-71`) uses the ISO currency map **only** when an explicit `$currencyCode` argument is passed. The no-arg path prefers the company country's `countries.currency_decimal_places`, and that column is:

```php
$table->tinyInteger('currency_decimal_places')->default(2)->after('currency_symbol');
// database/migrations/tenant/2026_03_11_100000_add_currency_decimal_places_to_countries.php:15
```

So a test that inserts its own `countries` row for `TN` **without** the column silently pins TND — a 3-decimal currency — at **scale 2**. Every service-emitted money string in that test then comes back a digit short, and because the tests are green, their assertions were **written against the wrong scale**.

This is a deliberate, documented preference order (`GeneralLedgerService.php:2689`, `:4879`, `PosPaymentPolicyResolver.php:94`), not a resolver bug. The defect is in the fixtures.

## Why production is safe (verified, not assumed)

1. The same migration backfills `['TND','LYD','BHD','IQD','JOD','KWD','OMR'] → 3` (`:19-21`), so every pre-existing row is correct.
2. The **only** non-test writer of `countries` is `CountriesSeeder.php:631` (`Country::updateOrCreate`), and all seven 3-decimal currencies carry `'currency_decimal_places' => 3` there. (40 of 41 entries set the key; none of the misses is a 3-decimal currency.)

**No live TND-at-scale-2 exposure. Not a P1.**

## The 8 affected files

| # | File | Note |
|---|---|---|
| 1 | `apps/api/tests/Feature/Taxation/VatDataRepositoryTest.php` | **VAT suite** — spot-verified by the gate at `:57-62` |
| 2 | `apps/api/tests/Feature/Taxation/VatPeriodControllerTest.php` | **VAT suite** |
| 3 | `apps/api/tests/Feature/Taxation/VatPeriodManagerCannotMutateTest.php` | VAT |
| 4 | `apps/api/tests/Feature/Taxation/VatReportControllerTest.php` | VAT |
| 5 | `apps/api/tests/Feature/Accounting/CorrectingEntryGlPostingTest.php` | spot-verified by the gate at `:613-618` |
| 6 | `apps/api/tests/Feature/Document/CancelRefusedOnNonOpenVatPeriodTest.php` | |
| 7 | `apps/api/tests/Feature/Tenant/TenantReferenceDataSeedingTest.php` | |
| 8 | `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php` | |

That two of them are **VAT** suites is the part that matters: TND VAT behaviour is currently pinned at the wrong scale.

## Suggested fix

Either:

- **(a) a shared fixture helper** — e.g. `Tests\Traits\SeedsCountries::seedTunisia()` that inserts the row with `currency_decimal_places => 3` — and route all 8 through it. Preferred: it stops the ninth occurrence; or
- **(b) per-file column addition**, adding `'currency_decimal_places' => 3` to each insert.

Either way the assertions in those files must be **re-derived at scale 3**, not merely re-pinned to whatever the new output is — the point is that the expected values were computed against the wrong scale in the first place.

## Precedent already in the tree

`apps/api/tests/Feature/POS/ZReportVatDeclarationReconciliationTest.php` hit this exact trap during B-6(ii) (the disclosure came back `38.00` instead of `38.000`) and fixed it at the fixture with the migration cited in an in-place comment. That is the shape the fix should take.
