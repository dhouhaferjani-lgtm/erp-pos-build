# M-8 Codex Review — Customer Advance Clearing Cap

Date: 2026-06-22
Scope:
- `GeneralLedgerService::clearCustomerAdvanceToReceivable()`
- customer-advance available-balance helper
- `PartnerBalanceService` numeric-string return docs
- GL integration regressions for over-clearing, zero clearing, allowed clearing, and duplicate draft clearings

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Checks

- The clearing path now rejects non-positive amounts before creating journal entries.
- The available advance is derived from the posted customer-advance liability balance using the existing subledger convention, where credit-normal customer advance balances are negative and available magnitude is `0 - balance`.
- Existing draft `prepayment_application` debits against the customer-advance account are subtracted, preventing duplicate draft clearings from reserving the same advance.
- The validation and journal creation run inside one transaction after locking the partner row with `lockForUpdate()`, serializing concurrent clearings for the same partner on PostgreSQL.
- Regression coverage exercises the old over-clear failure, the zero-amount boundary, the allowed exact-available path, and the duplicate draft-clearing edge.

## Residual Risk

- The concurrency behavior relies on real database row locking; SQLite feature tests validate the deterministic logic but not PostgreSQL lock scheduling.
