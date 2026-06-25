# M-4 Opus Adversarial Review — Posted-Event Partner Balance Refresh

- Item: M-4 — Balance refresh is synchronous and often outside posting transaction
- Commit: `5d30776ac`
- Reviewer: Opus adversarial (cross-model), 2026-06-23
- Branch/checkout: `apps/erp.dev-consolidation` (dev, merged)

## Summary

The change is mostly sound and well-tested for the paths it targets. The new
`RefreshPartnerBalanceOnJournalEntryPosted` listener correctly derives partner
IDs from posted journal lines and refreshes idempotently from the GL (which only
counts `status='posted'` lines), the `JournalEntryPosted` event was NOT mutated
(immutability preserved), draft-only GL builders no longer refresh, and the
production invoice/credit-note GL writers in `AccountingService` are now wrapped
in a single `DB::transaction` so a refresh failure rolls back the journal
header+lines. Tests are genuinely red-first and assert the claim. PHPStan L8 and
Pint pass on touched files; the focused and adjacent accounting suites pass.

However two issues weaken the claim:

1. The transaction wrap changes the failure mode of a sealed fiscal document
   from "GL present + stale cache (self-healing)" to "GL entirely absent + no
   automatic recovery" when `refreshPartnerBalance` throws. The `InvoicePosted`
   listener is synchronous (not `ShouldQueue`), there is no GL backfill command,
   and `createInvoiceGLEntries` has no idempotency guard. This is a real
   durability/operability regression that needs verification before it can be
   considered safe. (HIGH)

2. The POS account charge path was explicitly "kept" with a direct refresh, but
   that refresh runs on a **Draft** entry whose lines do not yet count toward the
   posted-GL balance — i.e. it is precisely the "stale/meaningless balance
   refresh on draft" that M-4's own acceptance criterion #2 set out to remove.
   It is pre-existing and outside the diff, but the claim's framing
   ("transactionally equivalent") overstates correctness. (MEDIUM)

## BLOCKER

None. No money/precision violation (no float casts introduced; balance math uses
`bcsub`/`bccomp` at scale 3 in pre-existing code; the diff adds no arithmetic).
No sign-convention change. No fiscal/domain event renamed, restructured, or
deleted — `JournalEntryPosted` is byte-for-byte unchanged. No migration in this
commit. Double-entry GL lines unchanged (only reflowed into the transaction
closure). Idempotent listener (recompute-from-GL).

## HIGH

### H1 — Refresh failure now escalates a sealed invoice into a missing-GL condition with no automated recovery

`DocumentPostingService::postWithFiscalChain()` seals + commits the document,
then dispatches `InvoicePosted` via `DB::afterCommit`
(`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:193`).
`InvoicePostedListener` is a plain synchronous listener (no `ShouldQueue`,
`apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php:12`) calling
`AccountingService::createInvoiceGLEntries()`. M-4 wraps that builder in a fresh
top-level `DB::transaction`
(`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:107`)
ending with `partnerBalanceService->refreshPartnerBalance(...)`
(`AccountingService.php:205`).

Consequence: if `refreshPartnerBalance` throws (it does
`Partner::...->firstOrFail()` and dispatches `PartnerBalanceUpdated` to its own
listeners — `PartnerBalanceService.php:314,362`), the entire GL entry rolls back.
The document is already committed and fiscally sealed. The new rollback test
proves exactly this (`assertDatabaseMissing('journal_entries', ...)`,
`InvoiceAndCreditNoteGLIntegrationTest.php:735`).

There is no `ShouldQueue` retry, no GL backfill console command
(`grep` of `app/Console` finds only `BackfillFiscalYears`), and
`createInvoiceGLEntries` has no "GL already exists for this source_id" guard
(`AccountingService.php:105-125`). So a transient refresh failure leaves a
sealed tax invoice with **zero** ledger representation (no AR, no revenue, no
VAT) and no self-healing path. Before M-4, the same failure left a complete
posted GL entry with only a stale cache, which the very next refresh would
correct.

This is a deliberate durability trade documented in the claim, but it has NOT
been verified that (a) `refreshPartnerBalance` cannot realistically fail in
production, or (b) there is an operator path to recreate the missing GL. The
audit item was severity MEDIUM and framed as a draft-pollution cleanup; turning
balance-cache durability into fiscal-ledger completeness durability for sealed
documents is a scope/behaviour change that deserves an explicit owner decision.
Recommend: either keep the AR/revenue/VAT write outside the refresh's rollback
scope (refresh is a cache, the ledger is the source of truth), or add an
idempotent GL backfill + a queued/retried listener.

## MEDIUM

### M1 — POS account charge still does a meaningless refresh on a Draft entry (violates M-4 acceptance criterion #2)

`GeneralLedgerService::createPOSChargeEntry()` creates the entry with
`status => JournalEntryStatus::Draft` (`GeneralLedgerService.php:1475`) and then
calls `partnerBalanceService->refreshPartnerBalance(...)` inside the same
transaction (`GeneralLedgerService.php:1526`), returning a Draft entry.
`getPartnerBalance()` only sums lines where `journal_entries.status='posted'`
(`PartnerBalanceService.php:46`), so this refresh computes a balance that
EXCLUDES the charge just written. The dedicated test confirms the entry stays
Draft (`POSAccountChargeJournalEntryTest.php:71`) and only asserts the method was
called once / bubbles failure (`:247`, `:254`) — it never asserts the refreshed
value reflects the charge.

This is exactly the "Draft creation … stale/meaningless balance refresh" that
M-4 acceptance criterion #2 set out to eliminate. The claim's justification
("transactionally equivalent and covered by rollback-on-refresh-failure tests")
is true for rollback but does not address that the refresh value is meaningless
until the entry is posted. I could not find where this `pos_account_charge`
draft entry is subsequently posted via `postEntry` (so the corrective
event-driven refresh may never fire). Pre-existing and outside the diff, but the
claim should not present it as already-correct. Recommend a follow-up to either
post-then-refresh, or drop the draft refresh and rely on the post event.

### M2 — Stale comment now misleads in the "posting independent of GL" test

`InvoiceAndCreditNoteGLIntegrationTest.php:616-618` still states "The
AccountingService creates the JournalEntry header before looking up accounts, so
a partial (orphaned) entry may exist." After M-4 the header is inside the
transaction, so a missing-account failure rolls the header back — no orphan. The
test still passes (it only asserts the document is Posted), but the comment is
now false and could mislead future maintainers about partial-write behaviour.

### M3 — Two divergent refresh mechanisms for posted partner GL

Posted entries created through `GeneralLedgerService::postEntry()` refresh via
the `JournalEntryPosted` listener; posted entries created directly by
`AccountingService::createInvoice/CreditNoteGLEntries()` refresh via an inline
call and do NOT emit `JournalEntryPosted` (they emit only `JournalEntryCreated`,
`AccountingService.php:202,321`). The Codex review itself notes this as residual.
It is correct today (no double refresh, since AccountingService never fires the
posted event), but it is a latent foot-gun: if anyone later makes AccountingService
delegate to `postEntry()` or emit `JournalEntryPosted`, the inline refresh plus
the listener will double-refresh. Worth an inline note in AccountingService.

## LOW

### L1 — `array_keys($partnerIds)` ordering is non-deterministic-ish but harmless

`RefreshPartnerBalanceOnJournalEntryPosted::handle()` dedups partner IDs via an
associative array keyed by `partner_id`
(`RefreshPartnerBalanceOnJournalEntryPosted.php:588-599`). Correct and idempotent;
refresh order across multiple partners on one entry is irrelevant since each
partner is recomputed independently from the GL. No action needed.

### L2 — Empty-string partner_id guard is defensive but unreachable

The listener guards both `null` and `''` for `partner_id`
(`RefreshPartnerBalanceOnJournalEntryPosted.php:590`). The column is
`string|null` (`JournalLine.php:18`); `''` is not a value any builder writes.
Harmless.

## Verification performed

- `git show 5d30776ac` full diff read.
- Read surrounding code: `GeneralLedgerService::postEntry`/post helpers/draft
  builders, `AccountingService::createInvoice/CreditNoteGLEntries`,
  `PartnerBalanceService::refreshPartnerBalance`/`getPartnerBalance`,
  `JournalEntryPosted` event, `InvoicePostedListener`,
  `DocumentPostingService::postWithFiscalChain`, `createPOSChargeEntry` +
  `TreasuryAccountChargeBridge`, `EventServiceProvider` registration.
- Confirmed `JournalEntryPosted` unchanged (event immutability OK).
- Confirmed only `RefreshPartnerBalanceOnJournalEntryPosted` + the existing
  audit `DomainEventSubscriber::handleJournalEntryPosted` listen to the event;
  no prior balance listener → no double-refresh from registration.
- Confirmed production invoice posting path is AccountingService (kept inline
  refresh in a transaction); `GeneralLedgerService::createFromInvoice/CreditNote`
  have no production callers (test-only), so removing their refresh is safe.
- Ran: M-4 regression + rollback filter (6 passed, 22 assertions);
  `POSAccountChargeJournalEntryTest` + `TreasuryAccountChargeBridgeTest`
  (20 passed, 119 assertions); `InvoiceGLIntegrationTest` +
  `CreditNoteGLIntegrationTest` + `DocumentGLIntegrationTest`
  (27 passed, 129 assertions).
- PHPStan L8 on the listener + `GeneralLedgerService` + `AccountingService`:
  no errors. Pint `--test` on all touched files: pass.

## Verdict

APPROVE-WITH-MINOR-EDITS — with a flagged HIGH (H1) that should be owner-reviewed
before relying on this in production. The tests and typing hold up under
refutation, the targeted behaviour matches the acceptance criteria, and no
fiscal/money/event-immutability invariant is violated. But H1 (sealed invoice →
no GL on transient refresh failure, no recovery) is a genuine durability
trade-off that was not part of the MEDIUM audit item's intent and is unverified;
M1 shows acceptance criterion #2 is only partially met (POS charge still refreshes
on a draft). Neither is a data-corruption blocker, but H1 in particular warrants
an explicit decision or a follow-up ticket.
