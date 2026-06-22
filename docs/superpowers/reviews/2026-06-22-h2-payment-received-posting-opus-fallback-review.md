# H-2.1 Payment-Received Posting — Opus Fallback Review

True Opus reviewer was not available in this runtime. This is the required second independent adversarial pass with a different lens; `opus-review: PENDING` remains in the progress log.

Diff reviewed: `.superpowers/review-packages/h2-payment-received-posting.diff`

Acceptance slice:
- Live customer payment-received GL creation should post when a user/actor exists.
- Balance refresh should happen only after posting.
- Unresolved draft-only flows should stay documented outside this slice.

Findings:

1. HIGH — `PaymentController::storeMultiple()` handled manual and automatic excess invoice allocations by creating `PaymentAllocation` rows and updating documents without creating customer-payment GL entries. Those live invoice settlements could bypass posted GL and partner-balance refresh.

2. MEDIUM — Fiscal account-payment bridge tests use fakes or assert projection rows without asserting posted `JournalEntry` status/hash or partner balance, so they could miss production GL posting regressions.

Resolution:
- Added `createPostedExcessAllocationJournalEntry()` for multi-payment excess invoice allocations.
- Covered manual excess allocation with `MultiPaymentTest::test_multi_payment_manual_excess_allocation_posts_customer_payment_gl`.
- Kept the fiscal bridge coverage gap noted for a later event-sourcing coverage item rather than expanding this H-2 slice.

Status: HIGH resolved; MEDIUM documented as adjacent test coverage risk.
