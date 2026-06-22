# M-9 Fallback Review — Aged Receivables Float Removal

Date: 2026-06-22
Reviewer status: Opus unavailable in this runtime; this is a second independent adversarial pass. `opus-review: PENDING`.
Lens: money precision, statement ordering, test stability, and scope control.

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Adversarial Checks

- The old float cast occurred only in the post-sort statement recalculation; removing it preserves the existing sort/recalculation structure.
- Debit and credit values are produced by `CurrencyScale::bcformat()` before recalculation, so treating them as numeric strings is consistent with the local data flow.
- The behavioral statement regression uses a TND company context and asserts 3-decimal closing/running balances.
- The existing bucket-total regression remains in the same file, keeping report-path scale coverage intact.
- No database schema or fiscal event contracts are touched.

## Residual Risk

- True cross-model Opus review was not available. Owner should spot-check this item before considering the dual-review gate fully satisfied.
