# L-2 Liability Statement Running Balance — Opus Fallback Review

opus-review: PENDING

## Fallback Review Lens

Because a true Opus reviewer is not reachable from this runtime, this is a second independent adversarial pass focused on sign conventions, scope containment, precision/static-analysis risk, and statement compatibility.

## Findings

No BLOCKER, HIGH, MEDIUM, or LOW findings.

The liability sign convention is now explicit in code through the `SystemAccountPurpose::CustomerAdvance` and `SystemAccountPurpose::SupplierPayable` branch. The tests cover both liability purposes and verify positive credit-normal magnitudes after credit and debit movements. The existing customer receivable statement test remains in place, so a future accidental global sign flip would be caught.

The code does not alter cached partner balances, posting behavior, GL lines, or reconciliation logic. It also avoids adding extra hardcoded bcmath-scale call sites, which keeps the existing PHPStan baseline expectation stable.

## Residual Notes

The statement method still uses a hardcoded bcmath scale of 4 from pre-existing code. That is outside this item's presentation-sign scope and remains governed by the existing precision rule/baseline.

