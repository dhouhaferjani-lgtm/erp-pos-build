# H-7.2 Voucher Ledger Posting — Independent Fallback Review

## Scope

Second adversarial pass because a true Opus reviewer was not available in this runtime. Review lens: idempotency, transaction boundaries, schema assumptions, and regression risk around voucher ledger accounting.

## Findings

No BLOCKER/HIGH/MEDIUM findings.

## Checks

- The actor lookup occurs before the journal-entry transaction. If a corrupted ledger row references a missing user, no new journal entry is created.
- `postEntryAndDispatchPostedEventAfterCommit()` is invoked only after both journal lines have been written and the loaded entry is available, preserving the existing balanced-entry guard in `postEntry()`.
- The method still throws for `PartiallyRedeemed` and unsupported voucher events before resolving accounts or posting, so projection-only events remain GL-silent.
- The adjacent voucher issuance/redemption filter exercises issued, redeemed, and rounding-adjustment GL paths after the change.
- The schema confirms `voucher_ledger.user_id` is non-null, so there is no valid actorless voucher-ledger compatibility path to preserve here.

## Residual Risk

The review did not run the full PHPUnit suite per session guardrails. Verification stayed scoped to voucher issuance/redemption plus PHPStan, Pint, and whitespace checks.
