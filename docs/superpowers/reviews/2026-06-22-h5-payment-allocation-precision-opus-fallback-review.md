# H-5 Payment Allocation Precision — Opus Fallback Review

Date: 2026-06-22  
True Opus review: PENDING; not reachable from this runtime.  
Fallback mode: independent second adversarial pass focused on database migration behavior, validation bypasses, output precision, and tolerance separation.

## Verdict

PASS for H-5.

## Findings

### No BLOCKER/HIGH findings

The patch addresses all three original mismatch points: PostgreSQL schema, Eloquent cast, and ingress validation. It also fixes a nearby response-level mismatch by formatting allocation preview/result money to the currency scale, so callers do not see four-decimal allocation amounts while persistence reads as three decimals.

The migration is PostgreSQL-only and no-ops on SQLite, consistent with the repository's other precision migrations. The real PostgreSQL test exercised the full migration path and verified the final column shape via `information_schema.columns`.

### LOW — Tolerance writeoff intentionally remains a different precision class

Status: ACCEPTED.

`payment_allocations.tolerance_writeoff` remains scale 4 because payment tolerances use percentage/max-amount calculations and existing service/tests rely on four-decimal tolerance values. This is not part of the `payment_allocations.amount` money-scale mismatch and should not be collapsed into scale 3 without a separate tolerance contract change.

### LOW — Direct service callers can still pass 4-decimal manual allocations

Status: ACCEPTED for this slice.

The public HTTP ingress now rejects 4-decimal manual allocation money, and persisted/read/output allocation amounts normalize to scale 3. `PaymentAllocationService` itself remains an application service and still accepts numeric strings from trusted internal callers. A future DTO-level validation pass could harden that boundary if untrusted callers are added.

## Verification Reviewed

- Red tests were observed before implementation for model cast padding and smart-payment validation.
- SQLite-focused H-5 test passed with the PostgreSQL schema assertion skipped.
- Real PostgreSQL H-5 test passed 2 tests, 4 assertions against isolated `autoerp_h5_test`.
- Focused Treasury/Fiscal allocation set passed 67 tests, 1 PostgreSQL-only skip, 298 assertions.
- Adjacent payment/document allocation set passed 48 tests, 6 PostgreSQL-only skips, 147 assertions.
- `PartnerBalanceServiceTest`, PHPStan L8, Pint, and `git diff --check` passed.
