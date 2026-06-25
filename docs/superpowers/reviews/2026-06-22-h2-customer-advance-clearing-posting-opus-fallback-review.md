# Fallback Second Review — H-2.5 Customer Advance Clearing Posting

Date: 2026-06-22

True Opus review: PENDING. This is the required independent fallback pass with a different lens.

Lens:
- production actor propagation
- conversion transaction behavior
- account-configuration fallback
- compatibility with actorless service tests

Verdict: CLEAN for this slice.

Findings:
- No blocker/high issues found.
- Posting uses `postEntryAndDispatchPostedEventAfterCommit()`, so conversion transaction rollback still prevents posted/balance events from leaking.
- The existing account-missing behavior is preserved: `SalesOrderToInvoiceConverter` still catches runtime account-configuration failures and records skipped GL metadata.
- Existing direct GL tests without an actor still expect Draft and remain compatible.

Out of scope / residual:
- Direct service conversions that do not supply `actor_user_id` remain draft-only.
- A final H-2 scan should still classify remaining `JournalEntryStatus::Draft` creators by source type and production caller.
