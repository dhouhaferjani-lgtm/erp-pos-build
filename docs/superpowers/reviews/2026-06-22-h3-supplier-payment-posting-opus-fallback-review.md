# H-3.1 Supplier Payment Posting — Opus Fallback Review

Date: 2026-06-22  
True Opus review: PENDING; not reachable from this runtime.  
Fallback mode: independent second adversarial pass focused on transaction timing, idempotency, event semantics, and unwired AP surfaces.

## Verdict

PASS for the narrow H-3.1 slice.

## Findings

### No BLOCKER/HIGH findings after remediation

The supplier-payment branch posts through `postEntryAndRefreshPartnerBalanceAfterCommit()`, so posted-entry events and partner-balance refresh are deferred correctly when the call happens inside the outer payment transaction. The repository balance event is also registered with `DB::afterCommit()`, so rollback leakage is consistent with the surrounding payment controller behavior.

The new supplier-payment GL lines keep the expected AP sign convention: SupplierPayable is debited with the supplier partner, the repository account is credited without a partner, and the journal entry is posted before balance refresh affects `partners.payable_balance`.

### MEDIUM — Supplier withholding behavior remains policy-ambiguous

Status: DOCUMENTED, not remediated in this slice.

`PaymentController::store()` still runs the generic withholding-certificate block for any non-empty allocation set. This patch does not change that behavior. If purchase-order supplier payments should support withholding, that needs a deliberate tax/AP policy test. If they should not, it should be blocked explicitly in a later item. No current test asserts either behavior.

### MEDIUM — H-3 production surface remains incomplete

Status: DOCUMENTED.

This slice wires only `PaymentController::store()` for purchase-order supplier payments. It does not wire supplier invoice/AP recognition, `PaymentController::storeMultiple()`, or `PaymentAllocationService`. Those remain explicit H-3 follow-up work and should not be inferred as fixed by this commit.

## Verification Reviewed

- `php artisan test --filter 'purchase_order_payment'` passed 2 tests, 15 assertions.
- `php artisan test tests/Feature/Treasury/PaymentTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/TreasuryEventsTest.php` passed 42 tests, 129 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- PHPStan L8 passed on touched production files.
- Pint `--test` passed on touched files.
