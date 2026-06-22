# H-7.3 COGS Posting — Independent Fallback Review

## Scope

Second adversarial pass because a true Opus reviewer was not available in this runtime. Review lens: transaction timing, rollback behavior, event-chain consistency, and compatibility with existing callers of `createCOGSEntry()`.

## Findings

No BLOCKER/HIGH/MEDIUM findings.

## Checks

- `createCOGSEntry()` still creates no journal entry for zero total COGS, so no empty posted entries are introduced.
- Posting occurs after the COGS header and both lines are created; if the caller is inside an outer transaction, the helper defers posting until after commit.
- The internal nullable-actor helper is private, so external callers cannot bypass user attribution for manual posting.
- Existing direct COGS helper tests now exercise posted COGS entries and continue to validate source linkage, company/tenant assignment, WAC rounding, and balanced legs.
- The adjacent `InvoicePostedListenerTest` balance check stayed green with the COGS listener active.

## Residual Risk

This change does not introduce duplicate COGS idempotency protection; repeated `InvoicePosted` delivery could still create repeated COGS entries. That was pre-existing for this listener and should be handled as a separate idempotency item if the event delivery semantics require it.
