# Ticket — `TreasuryAccountPaymentBridgeTest` is red on `dev` (17 tests)

**Raised by:** DPA `DPA-REV2-A` code gate, finding m-D (2026-08-10)
**Class:** pre-existing on `dev`. **Not** a `DPA-REV2-A` defect.
**Status:** OPEN — and it matters more than a normal red, see below.

## Symptom

`tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php` — **17 tests fail**:

```
ArgumentCountError: Too few arguments to function
  App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge::__construct(),
  3 passed ... 4 expected
at TreasuryAccountPaymentBridgeTest.php:459
```

## Verified pre-existing

At base `ed6fe896f` the production class already declares **4** constructor
parameters while the test constructs it with **3**. The gate verified both sides at
base; `DPA-REV2-A` does not touch either file.

## Why it is worth prioritising

This is the test file for the writer that **mints shape Z** —
`TreasuryAccountPaymentBridge` creates the POS ACCOUNT_PAYMENT row that is
simultaneously `origin = Pos`, `payment_type = DocumentPayment` and instrument-linked
(`:161-182`). That shape is exactly the one `DPA-REV2-A` A9 had to fix, and:

* the bridge's own test suite has been **red on `dev`**, so nobody was told when its
  behaviour drifted;
* `DPA-REV2-A` had to **hand-build its A1g fixture** rather than reuse the bridge's,
  precisely because the bridge suite could not be relied on.

A red suite over a money-minting projector is how shape Z reached production
unnoticed in the first place.

## Fix

Update the test's construction of `TreasuryAccountPaymentBridge` to pass the 4th
constructor argument. Check whether other bridge tests
(`TreasuryDepositBridgeTest`, `TreasuryAccountChargeBridgeTest`,
`TreasuryReceiptBridge` tests) carry the same drift:

```bash
grep -rn "new TreasuryAccountPaymentBridge\|new TreasuryDepositBridge" apps/api/tests/
```

Then consider whether these projector suites belong in the CI-blocking set — a
silently red projector test is worse than no test, because it reads as coverage.
