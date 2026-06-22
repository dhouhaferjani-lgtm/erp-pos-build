# M-4 Codex Review — Posted Event Balance Refresh

Date: 2026-06-22
Scope:
- `RefreshPartnerBalanceOnJournalEntryPosted`
- `EventServiceProvider` registration
- `GeneralLedgerService` draft/post refresh changes
- `AccountingService` posted document GL transaction wrapping
- M-4 regression tests

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Checks

- `JournalEntryPosted` now drives cached partner refresh for normal `GeneralLedgerService::postEntry()` calls by deriving unique partner IDs from posted journal lines.
- Draft-only GL builders no longer refresh partner balances before entries become `Posted`.
- Existing immediate-post helpers still post after outer commit when invoked inside an open transaction; their balance refresh now comes from the posted event.
- Production invoice and credit-note GL creation remain direct posted-entry writers, but their journal writes, audit event dispatch, and balance refresh now run inside a single transaction. Refresh failure rolls back the posted journal entry and lines.
- POS account charge still uses direct in-transaction refresh. That path already has explicit rollback-on-refresh-failure coverage and is transactionally equivalent rather than `JournalEntryPosted`-driven.

## Residual Risk

- `AccountingService` still creates posted document journal entries directly instead of delegating to `GeneralLedgerService::postEntry()`. This is existing architecture and outside this M-4 change; the new transaction coverage narrows the balance-refresh durability risk without changing fiscal event contracts.
