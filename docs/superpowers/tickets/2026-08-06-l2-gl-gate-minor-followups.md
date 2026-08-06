# Ticket: L2 dual-gate MINOR findings worth a follow-up (bundle)

**Filed:** 2026-08-06, by the L2 AR-integrity fix lane's dual-gate round, from
the MINOR-severity findings in
`docs/superpowers/reviews/2026-08-06-l2-gl-gate.md` and
`docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md` that were judged
ticket-worthy rather than fix-in-lane (small, no test/behaviour risk today, but
real debt).

**Status:** OPEN. Each item below is independent — land them individually,
smallest first.

## 1. `journal_code` left NULL on the cancellation reversal (M-2)

`AccountingService::reverseDocumentGl()`'s entry-creation block
(`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php`,
around the `JournalEntry::create([...])` call inside the `DB::transaction`)
stamps no `journal_code`, so the FEC `JournalCode` column is empty for every
`DocumentCancellation`-sourced entry.
`JournalCode::fromSourceType('DocumentCancellation')` would yield `OD`
(`app/Modules/Accounting/Domain/Enums/JournalCode.php:32-42`), which is the
correct default value already used elsewhere in this file (e.g. the supplier
invoice writer stamps `journal_code` via the same helper —
`GeneralLedgerService.php:650`). Pre-existing pattern gap (the invoice/credit-note
posting paths in `AccountingService` don't stamp it either), but this was a
brand-new entry type and the cheapest possible moment to have gotten it right.

**Fix:** add `'journal_code' => JournalCode::fromSourceType(self::DOCUMENT_CANCELLATION_SOURCE_TYPE)->value,`
to the entry-creation array. One line, no migration, needs a test asserting
the stamped value on `DocumentCancellationGlReversalTest`'s happy-path fixture.

## 2. `reversed_at` / `reversal_entry_id` audit columns are permanently unused (M-3)

`journal_entries` carries a reversal audit trail
(`database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:32-35`;
fillable on `JournalEntry` at `Domain/JournalEntry.php:67-69`) that the new
cancellation reversal never populates — and structurally CANNOT, because
`JournalEntryObserver::updating` forbids touching a chained (sealed) entry
(`Observers/JournalEntryObserver.php:29-35`), and the ORIGINAL entry is exactly
where `reversed_at`/`reversal_entry_id` would need to be written. Any future
consumer asking "is this entry reversed?" via those two columns will always get
NO, even for an entry this lane's mirror has fully reversed.

**Fix (needs a ruling, not just code):** either (a) document plainly that
`source_id` linkage (`journal_entries.source_type = 'DocumentCancellation' AND
source_id = <original document id>`) is the ONLY supported way to answer "is
this reversed", and add that as a code comment / small query helper so future
callers don't reach for the unusable columns, or (b) if a future migration adds
an allow-list exception to the immutability trigger/observer for exactly these
two columns on an already-SEALED row, wire them up properly. (a) is the cheap
near-term fix; (b) is a bigger, riskier change to immutability semantics that
needs its own review.

## 3. `line_order` not copied onto the mirror legs (M-4)

`AccountingService::reverseDocumentGl()`'s `JournalLine::create([...])` loop
omits `line_order`, so every mirror leg defaults to `0` and leg ordering is
non-deterministic in ledger/FEC output. The house pattern for exactly this
operation (reversing a set of legs into a new entry) already preserves it:
`GeneralLedgerService::reverseInventoryWriteOffEntry`
(`GeneralLedgerService.php:4418-4429`).

**Fix:** carry `$line->line_order` (or its index in the loop) into each mirror
leg's `line_order`. One line, needs a test asserting mirror legs come back in
the same order as the original when eager-loaded.

## 4. `entryType = 'document_cancellation'` — magic string, not yet a problem (ruling 6b, no action required now)

Swept and explicitly approved by the GL gate (ruling 6b): `JournalEntryCreated`
has exactly one consumer (`Compliance\Listeners\DomainEventSubscriber::handleJournalEntryCreated`),
which writes `entry_type` into an opaque audit payload and branches on nothing.
No other consumer anywhere reads `entryType`/`source_type = 'Document'`
specifically. **No action required** unless `entryType` ever becomes an actual
discriminator — if that day comes, promote `'invoice'` / `'credit_note'` /
`'document_cancellation'` to a proper PHP enum together (CLAUDE.md rule 9), not
piecemeal. Included here only so the "already looked at this" context isn't
lost.

## 5. Aged payables still reports Purchase Orders, not Supplier Invoices (treasury gate MINOR)

`AgedPayablesService.php:155` and `:191` filter `DocumentType::PurchaseOrder`
only. `DocumentType::SupplierInvoice` — the document type `PaymentController`
actually pays, that carries the Cr-401 payable leg, and that
`UpcomingPaymentsService.php:49` uses for the outgoing cash-flow forecast —
**never appears in the aged-payables report at all**. This lane's D2/AP mirror
fix (opening-balance authority, NULL-cache blindness) is therefore correct but
scoped to POs; genuine unpaid supplier invoices are invisible to "aged
payables" regardless of this round's changes. Pre-existing, confirmed
unaffected by this round (path disjointness — this round touched
`AgedPayablesService`'s `openBalance()`/filter logic, not its `DocumentType`
predicate).

**Fix:** extend both `:155` and `:191`'s `where('type', ...)` to
`whereIn('type', [DocumentType::PurchaseOrder, DocumentType::SupplierInvoice])`,
and decide how the two types' amounts combine per partner (a supplier could
have both an un-invoiced PO accrual and a posted, unpaid SupplierInvoice
simultaneously — they should sum, not overwrite). Needs its own red→green test
fixture with both document types against one supplier.

## 6. `DocumentAllocationStateGuard` now couples Document ↔ Treasury BOTH ways (Round-2 finding)

The guard already type-hinted `App\Modules\Document\Domain\Document`
(`apps/api/app/Modules/Treasury/Application/Services/DocumentAllocationStateGuard.php:7`)
— a Treasury class importing a concrete Document-module Eloquent model, flagged
MINOR at the original gate (M-6, "consistent with `PaymentController`'s
pre-existing usage"). This round's item-4 fix added the REVERSE direction:
`CreditNoteService` (Document module, `Application/Services`) now directly
imports and constructor-injects the concrete
`App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard`
(`apps/api/app/Modules/Document/Application/Services/CreditNoteService.php`) to
guard `allocateCreditNote()`. Document and Treasury are now coupled **both
ways** through this one class — worse than the pre-existing one-directional
debt. `deptrac.yaml`'s ruleset is glob-based per hexagonal tier WITHIN a
module, not across modules (confirmed by reading the config's own header
comment), so this is invisible to the ratchet CI already runs.

Doesn't break anything today (PHPStan/tests clean on both sides), and it
mirrors the SHAPE of a precedent this exact lane already established
correctly elsewhere — `DocumentGlReversalInterface`
(`apps/api/app/Shared/Contracts/Accounting/DocumentGlReversalInterface.php`)
decouples the equivalent Accounting↔Document dependency for the GL-reversal
call. **Fix:** add a `Shared/Contracts/Treasury/DocumentAllocationGuardInterface`
(single method, `assertAllocatable(Document $document): void`), have
`DocumentAllocationStateGuard` implement it, and have `CreditNoteService`
(and, for consistency, `CloseInvoiceWithToleranceService` and
`MultiPaymentController`/`PaymentAllocationService`/`PaymentController` —
everywhere this round injected the concrete class) depend on the interface
instead. Small, mechanical, no behavior change; closes both the new and the
pre-existing M-6 debt in one pass.

## Related

- `docs/superpowers/reviews/2026-08-06-l2-gl-gate.md` (M-2, M-3, M-4, ruling 6b,
  Round-2 cross-module coupling finding)
- `docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md` (AP report MINOR
  finding, Round-2 cross-module coupling finding)
