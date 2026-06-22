# M-7 Fallback Review — Both Partner Net Exposure

Date: 2026-06-22
Reviewer status: Opus unavailable in this runtime; this is a second independent adversarial pass. `opus-review: PENDING`.
Lens: semantic consistency, sign convention, query/display drift, and regression coverage.

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Adversarial Checks

- The chosen sign convention is internally consistent: positive net balance means the partner owes the company; for `both` partners, supplier payable exposure reduces that amount and can make it negative.
- The Eloquent accessor and SQL sort expression now agree for customer, supplier, and both-type partners.
- The web helper no longer routes `both` partners through the customer branch, so payable balance is included in displayed net exposure.
- Regression tests cover a non-trivial both-type partner with receivable, credit, and payable amounts, which would have failed under the old `receivable - credit` formula.

## Residual Risk

- True cross-model Opus review was not available. Owner should spot-check this item before considering the dual-review gate fully satisfied.
