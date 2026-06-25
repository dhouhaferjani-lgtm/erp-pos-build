# Fallback Second Review — H-7.1 Expense Posting

Date: 2026-06-22

True Opus review: PENDING. This is the required independent fallback pass with a different lens.

Lens:
- transaction safety
- actor availability
- scope containment
- compatibility with existing GL tests

Verdict: CLEAN for this slice.

Findings:
- No blocker/high issues found.
- `createFromExpense()` receives a required `User`, so posting does not need a nullable actor contract.
- The change leaves expense account/category selection untouched; only lifecycle state changes from draft-only to posted.
- Full `GLIntegrationTest` passed, covering invoice, credit-note, customer advance, supplier AP, and manual posting paths that share the same service.

Out of scope / residual:
- H-7 still needs separate handling for voucher ledger, COGS, and inventory write-off writers, because they do not all have an obvious human actor.
