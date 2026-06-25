# M-9 Opus Adversarial Review — Aged Receivables Decimal Strings

- Item: M-9 (Aged receivables uses float math)
- Commit: `de44b4f0f` ("Phase 0.1.26: Keep aged receivables money as decimal strings")
- Reviewer: Opus adversarial pass (the prior `opus-fallback-review` recorded `opus-review: PENDING`; this satisfies that gate)
- Checkout: `apps/erp.dev-consolidation` on `dev` (merged code + vendor)

## Summary

The code change is small and correct: the two `(float)` casts in the post-sort running-balance
recalculation loop of `AgedReceivablesService::generateCustomerStatement()` are removed, and the
loop now operates on `numeric-string` debit/credit values with bcmath at the resolved currency
scale. The model casts (`decimal:3`) return strings, so the `numeric-string` annotations are
accurate and the fix genuinely keeps money off floats. The audited failure mode (line ~288) is the
exact site fixed; no other float casts remain in the file; PHPStan level 8 and Pint pass; the file
touches no schema, no fiscal event, no GL, no migration.

The defect is in the **test evidence**, not the production code. The one behavioral regression test
provides zero protection — I empirically reverted the fix to the original float code and the
behavioral test still PASSED. The real guard is a brittle source-text grep. This undercuts the
"red observed first / regression coverage" claim in the work-list.

## BLOCKER

None. No money is computed on floats in the merged code; no migration, fiscal event, GL posting, or
data-integrity surface is touched.

## HIGH

### H-1 — Behavioral regression test is false-confidence; it passes against the buggy code

`apps/api/tests/Feature/Document/AgedReceivablesScalingTest.php:124-140`
(`customer_statement_running_balance_preserves_third_decimal_for_tnd`) creates a single invoice
`total='1234.567'`, no payments, no credit notes, and asserts:

```php
$this->assertSame('1234.567', $statement['transactions'][0]['balance']);
$this->assertSame('1234.567', $statement['closing_balance']);
```

`1234.567` is far below the IEEE-754 double precision threshold where float drifts. The OLD code did
`$debit = (float) '1234.567'` then `(string) $debit` → `'1234.567'` losslessly. I verified:
`php -r 'echo (string)(float)"1234.567";'` → `1234.567`, whereas the large value the work-list
abandoned drifts: `(string)(float)"100000000000.123"` → `100000000000.12`.

I then empirically reverted the production loop back to the original `(float)` casts and ran:
`php artisan test ... --filter customer_statement_running_balance_preserves_third_decimal_for_tnd`
→ **PASS (1 passed, 2 assertions)**. The test cannot distinguish the fix from the bug, so it does
not cover the audited failure mode. The work-list's "Red observed first … confirming the audited
float precision failure mode" describes a throwaway value that was then *replaced* by this
non-discriminating permanent test — the committed suite has no red-first behavioral guard.

Recommended fix: make the behavioral test discriminating without relying on SQLite's large-decimal
storage. Drive the running balance to a magnitude where double loses the 3rd decimal by summing
many sub-cent transactions in-array (e.g. a long alternating debit/credit ledger whose cumulative
running balance exceeds ~2^33 with a non-zero 3rd decimal), or unit-test the recalculation in
isolation with injected `$transactions`. The assertion must fail under `(float)` and pass under
bcmath.

### H-2 — The only real regression guard is a brittle source-grep

`AgedReceivablesScalingTest.php:113-121`
(`aged_receivables_service_does_not_cast_money_through_float`) is the sole test that actually fails
if the fix is reverted, and it does so by string-matching the source:

```php
$this->assertStringNotContainsString('(float)', $source);
```

This is fragile: it does not catch `floatval(...)`, `(double)`, `(float )` with interior
whitespace, `+0.0`, `settype($x,'float')`, or a float introduced in a helper this service calls. It
also couples a test to a literal source token rather than behavior. It is acceptable as a
belt-and-suspenders lint, but it must not be the primary defense for a money-precision claim. Pair
it with the discriminating behavioral test from H-1 (or a PHPStan `ForbidFloatCastOnDecimalProperty`
rule extended to this path, which would be the stronger, project-consistent guard).

## MEDIUM

### M-1 — `getScale()` no-arg call is a latent throw outside request context (pre-existing, now in a fixed path)

`AgedReceivablesService.php:217` `$scale = $this->scaleResolver->getScale();` uses the bare no-arg
form, which `CurrencyScaleResolver::getScale()` throws `UnboundCompanyContextException` for when no
`CompanyContext` is bound (confirmed in `app/Shared/Infrastructure/CurrencyScaleResolver.php:44-51`,
and the CLAUDE.md precision contract explicitly warns about this in queued/console/transition
contexts). `generateCustomerStatement` is currently only reached from
`ReportsController.php:118` (request context, context bound), so it is safe today. But the method
takes an explicit `$companyId`/`$partnerId` and could plausibly be called from a scheduled
statement-export or queued job, where it would throw. This commit did not introduce the call, and
M-9's scope is strictly float removal, so this is out-of-scope to fix here — but it should be
tracked: prefer resolving scale from the statement's currency (`getScale($currency)` /
`getScaleSafe($currency, 3)`). Note also the statement assumes a single company-context scale for
all rows even though documents carry a per-row `currency` — multi-currency partners would be
mis-scaled. Flag, do not fix in M-9.

### M-2 — Work-list "Verification" narrative overstates the committed evidence

`docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md` (M-9 Outcome/Verification)
claims "Added regression coverage that … verifies TND scale-3 customer-statement balances." Given
H-1, the committed test verifies nothing the old code failed. The narrative should be corrected to
state plainly that the permanent guard is a source-grep (H-2) plus a non-discriminating
sanity-check, and that a true large-magnitude behavioral guard was *not* landed (SQLite storage
rounding was cited as the blocker, but an in-array large-ledger test avoids the DB entirely).

## LOW

### L-1 — Inconsistent display scale in statement rows (pre-existing)

Invoice rows emit `'credit' => '0.00'` and payment/credit-note rows emit `'debit' => '0.00'`
(`AgedReceivablesService.php:228,250,278`) — scale-2 literals while the company scale is 3, so a TND
statement shows `0.00` next to `1234.567`. Cosmetic, pre-existing, not in M-9 scope. Worth a
follow-up to format the zero side with `CurrencyScale::bcformat('0', $scale)`.

## Verdict

APPROVE-WITH-MINOR-EDITS.

The production fix is correct, complete, scoped, and verified by PHPStan/Pint — money no longer
touches a float in the audited path. M-9's substantive goal is met. However the test evidence does
not support the work-list's regression claim: the behavioral test passes against the reverted buggy
code (proven empirically), leaving only a brittle source-grep as the real guard. Before this item
is considered fully closed, land a discriminating behavioral regression test (H-1) and correct the
work-list verification narrative (M-2). These are test/doc edits, not production-code changes, hence
APPROVE-WITH-MINOR-EDITS rather than NEEDS-REVISION.
