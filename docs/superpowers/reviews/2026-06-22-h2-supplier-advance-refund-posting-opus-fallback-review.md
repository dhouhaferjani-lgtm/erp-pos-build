# Fallback Second Review — H-2.4 Supplier Advance Refund Posting

Date: 2026-06-22

True Opus review: PENDING. This is the required independent fallback pass with a different lens.

Lens:
- rollback and after-commit behavior
- actor validation
- source linkage
- no-account path compatibility

Verdict: CLEAN for this slice.

Findings:
- No blocker/high issues found.
- The new test explicitly enables the GL path by assigning `account_id` to the repository, while existing no-account refund tests continue to exercise the legacy path where no journal entry is created.
- Posting is deferred through `postEntryAndDispatchPostedEventAfterCommit()`, matching the rest of the event-driven balance refresh work.
- The entry uses existing `source_type=supplier_advance_refund` and `source_id=<refund payment id>`, so the change does not alter source linkage.

Out of scope / residual:
- Actorless service calls still produce draft entries by design for this H-2 slice.
- Customer-advance clearing remains unresolved and should be processed next.
