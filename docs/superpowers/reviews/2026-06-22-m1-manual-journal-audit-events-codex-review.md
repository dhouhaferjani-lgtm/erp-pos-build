# M-1 Manual Journal Audit Events — Codex Review

Date: 2026-06-22
Reviewer: Codex
Scope: Manual journal create/post event dispatch and compliance audit subscriber wiring.

## Verdict

No BLOCKER/HIGH findings.

## Findings

- None.

## Checks Performed

- Confirmed manual journal creation now dispatches the existing immutable `JournalEntryCreated` event with `entryType=manual`, `sourceType=manual`, and self-linked `sourceId`.
- Confirmed manual journal posting now routes through `GeneralLedgerService::postEntry()`, reusing the same balanced-entry guard, company hash-chain lookup, fiscal hash update, actor stamping, and `JournalEntryPosted` dispatch as service posting.
- Confirmed `DomainEventSubscriber` explicitly subscribes to `JournalEntryCreated` and `JournalEntryPosted`, persisting both as `JournalEntry` aggregate audit rows.
- Confirmed tests cover controller event dispatch and subscriber audit persistence.

## Residual Notes

- `JournalEntryController` still scopes some read/post queries by tenant rather than company. That appears pre-existing and is best handled as a separate access-control hardening item, not bundled into this event/audit patch.
- True Opus review was not available in this runtime; fallback review is recorded separately and the progress log marks `opus-review: PENDING`.
