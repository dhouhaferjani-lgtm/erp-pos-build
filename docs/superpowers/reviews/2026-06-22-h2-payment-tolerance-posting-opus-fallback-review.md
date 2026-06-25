# Fallback Second Review — H-2.3 Payment Tolerance Posting

Date: 2026-06-22

True Opus review: PENDING. This is the required independent fallback pass with a different lens.

Lens:
- rollback safety
- actor resolution boundaries
- event timing
- duplicate/idempotency risk
- remaining draft-producing surfaces

Verdict: CLEAN for this slice.

Findings:
- No blocker/high issues found after pre-create actor lookup remediation.
- `postEntryAndDispatchPostedEventAfterCommit()` keeps journal posting and `JournalEntryPosted` balance refresh deferred until the outer allocation/close transaction commits.
- The change does not introduce duplicate source entries; it changes lifecycle state for the entry already created by the existing tolerance writer.
- `PaymentAllocationService` only forwards actors that pass tenant and company-membership checks through `resolveCommandActor()`.
- `CloseInvoiceWithToleranceService` passes the authenticated route actor id through its existing `closedBy` contract; the endpoint coverage asserts the posted actor on the resulting journal entry.

Out of scope / residual:
- Actorless command/replay calls remain draft-only by design for this slice.
- Supplier advances and customer-advance clearing still need separate H-2 handling.
