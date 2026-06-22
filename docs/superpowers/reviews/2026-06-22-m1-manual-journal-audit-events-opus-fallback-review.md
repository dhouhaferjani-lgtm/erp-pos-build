# M-1 Manual Journal Audit Events — Opus Fallback Review

Date: 2026-06-22
Reviewer: Codex, second independent pass
Lens: Event immutability, subscriber durability, and route behavior.

## Verdict

No BLOCKER/HIGH findings.

## Findings

- None.

## Adversarial Checks

- No event contracts were renamed or restructured; the patch reuses `JournalEntryCreated` and `JournalEntryPosted`.
- The post endpoint now delegates to the canonical GL posting service rather than duplicating hash-chain and status mutation logic in the controller.
- Compliance subscriber coverage is explicit: both journal events are subscribed and persisted with `aggregateType=JournalEntry`.
- Tests exercise both levels: HTTP route dispatch (`Event::fake`) and actual audit-row persistence through `DomainEventSubscriber`.

## Residual Notes

- Manual journal create emits `JournalEntryCreated` after the DB transaction commits. If a future outer transaction wraps the HTTP action, this may need `DB::afterCommit()` treatment, but the current route-level transaction is already closed before dispatch.
- True cross-model Opus review remains pending for owner spot-check.
