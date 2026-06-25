# H-7.3 COGS Posting — Codex Adversarial Review

## Scope

Reviewed the H-7.3 diff for event-driven COGS journal lifecycle hardening:

- `GeneralLedgerService::createCOGSEntry()`
- `PostCOGSOnInvoice`
- `StockMovementGLIntegrationTest`
- H-7 progress notes in the work-list and coordination file

Review lens: posting lifecycle correctness, immutable event compatibility, currency-scale safety, and regression risk for existing COGS calculations.

## Findings

No BLOCKER/HIGH/MEDIUM findings.

## Checks

- The red test exercises the production `InvoicePosted` listener path, not only the lower-level GL helper.
- `InvoicePosted` has no actor and is documented immutable, so the implementation does not mutate the event contract to add one.
- COGS posting reuses the same balance validation, hash calculation, `posted_at`, and `JournalEntryPosted` dispatch path as `postEntry()`.
- `posted_by` remains nullable only through a private system-generated path; the public `postEntry(JournalEntry, User, ?string)` API still requires a real user.
- `PostCOGSOnInvoice` passes event currency explicitly, avoiding ambient company-context scale resolution for queued/listener execution.
- Existing COGS rounding and balancing tests still cover the high-precision WAC boundary.

## Residual Risk

`posted_by` is intentionally null for event-derived COGS entries because the immutable invoice event carries no actor. A future explicit actor-context event version could improve attribution, but changing `InvoicePosted` is out of scope here.
