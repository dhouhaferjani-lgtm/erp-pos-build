# H-2.2 Customer-Advance Posting — Opus Fallback Review

True Opus reviewer was not available in this runtime. This is the required second independent adversarial pass with a different lens; `opus-review: PENDING` remains in the progress log.

Diff reviewed: `.superpowers/review-packages/h2-customer-advance-posting.diff`

Acceptance slice:
- Customer advance journal entries should become posted GL entries in live production paths.
- Balance refresh should happen only after posting.
- A failed enclosing payment transaction must not emit durable posted-journal or partner-balance events for customer advance or customer payment-received postings.

Findings:

1. HIGH — The first implementation posted and refreshed immediately after the inner `GeneralLedgerService` transaction, even when called inside a broader production transaction. If the outer transaction rolled back, `JournalEntryPosted` and `PartnerBalanceUpdated` could leak for a journal entry that no longer existed.

2. MEDIUM — A compatibility test that omitted `currencyCode` could pass accidentally because test setup had a bound `CompanyContext`, leaving the no-context production path uncovered.

Resolution:
- Customer advance and customer payment-received posting now use a shared `DB::afterCommit()` helper when `DB::transactionLevel() > 0`, and post synchronously only outside an outer transaction.
- Added `GLIntegrationTest::test_customer_advance_outer_transaction_rollback_does_not_emit_posting_events`.
- Added `GLIntegrationTest::test_payment_received_outer_transaction_rollback_does_not_emit_posting_events` after confirming it failed on a leaked `JournalEntryPosted` event.
- Reworked the currency compatibility coverage to clear `CompanyContext` before omitting `currencyCode`.

Status: HIGH resolved; MEDIUM resolved.
