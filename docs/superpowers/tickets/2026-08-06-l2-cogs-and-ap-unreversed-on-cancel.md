# Ticket: cancelling an invoice/supplier-invoice leaves COGS and AP standing

**Filed:** 2026-08-06, by the L2 AR-integrity fix lane's dual-gate round, from
`docs/superpowers/reviews/2026-08-06-l2-gl-gate.md` finding I-4 and ruling 6c
(the second REQUIRED-before-merge ticket per that ruling).
**Status:** OPEN.

## Part 1 — a cancelled sales invoice's COGS entry is never reversed (I-4)

`AccountingService::reverseDocumentGl()` filters
`source_type = self::DOCUMENT_SOURCE_TYPE` (`'Document'`)
(`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:730-733`).
In production, a posted sales invoice with physical lines ALSO writes a
**separate** journal entry keyed on the **same** `source_id` but
`source_type = 'cogs'`, from
`PostCOGSOnInvoice::handle()` → `GeneralLedgerService::createCOGSEntry()`
(`apps/api/app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php:73-80`,
`GeneralLedgerService.php:1667-1677`). `reverseDocumentGl()`'s query never
matches that entry, so it is left standing.

Cancel does not restock either — no listener is registered for
`InvoiceCancelled` in `EventServiceProvider` besides the Compliance audit
subscriber (`DomainEventSubscriber.php:1073`), so leaving the inventory credit
untouched is at least *internally* consistent with "cancel does not reverse
stock movements" as a design choice. But the net GL effect of cancelling an
invoice with physical lines is: **COGS stays booked with ZERO revenue**
(revenue was reversed by the mirror; COGS was not) — a permanent understatement
of gross margin on every cancelled invoice with inventory lines.

The `DocumentGlReversalInterface` docblock promises to "reverse a document's
GL" with no qualification
(`apps/api/app/Shared/Contracts/Accounting/DocumentGlReversalInterface.php:10-24`)
— broader than what the implementation actually does.

## Part 2 — a cancelled supplier invoice's AP/expense/VAT is never reversed (6c)

Two independent guards keep supplier invoices out of the reversal path
entirely:

- `reverseDocumentGl()` returns `null` for any type other than
  `Invoice`/`CreditNote`
  (`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:717-719`).
- `DocumentPostingService::cancel()` never even calls it for a supplier
  invoice, because `requiresFiscalChain()` matches only `Invoice`/`CreditNote`
  (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:41-47,510-513`);
  the non-fiscal branch (`:189-191`) just flips `status` with no GL call.

A posted supplier invoice writes AP (Cr 401) + expense (Dr 6xx) + deductible
input VAT (Dr 4456) —
`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:645-655`,
`source_type = 'supplier_invoice'`. Cancelling it via
`DocumentPostingService::cancel()` leaves **all three legs standing**, and the
input VAT stays declared as deductible even though the underlying purchase was
withdrawn — the identical defect class F-6/(c) just fixed on the AR side,
mirrored on AP, confirmed as a real (not hypothetical) gap by the GL gate.

This is **explicitly out of scope for the L2 lane** (fixing it would double the
lane's blast radius under an active merge gate — a second reversal path,
a second set of tests, a second set of GL/VAT interactions), but the gate
ruling was clear: calling it "coherent" would be wrong. It is a real gap,
recorded here rather than left implied-intentional.

## Suggested fix

1. **COGS (Part 1):** extend `reverseDocumentGl()`'s original-entry query to
   also match `source_type = 'cogs'` (or a small allow-list of document-linked
   source types) for the same `source_id`, and mirror those legs too. Needs a
   decision on whether the inventory RESTOCK should also fire on cancel — if
   yes, that is a separate, larger change (a listener on `InvoiceCancelled` that
   reverses the stock movement, not just its GL leg); if no (cancel treats
   inventory as already consumed/shipped and only a credit note restocks), then
   reversing COGS without restocking is itself a booking with no inventory
   counterpart and needs its own ruling on where the offsetting leg goes.
2. **Supplier invoice (Part 2):** add the AP-side mirror — either extend
   `reverseDocumentGl()` to also accept `DocumentType::SupplierInvoice` (source
   type `'supplier_invoice'`, mirroring 401/expense/4456), or a parallel
   `reverseSupplierInvoiceGl()` — and wire it into
   `DocumentPostingService::cancel()`'s non-fiscal branch (`:189-191`) or extend
   `requiresFiscalChain()`'s definition. Needs the same period-lock
   consideration as `2026-08-06-l2-gl-vat-declaration-desync.md` — input VAT
   reversal on a CLOSED/FILED period has the identical retroactive-declaration
   problem as the output-VAT case.

## Related

- `docs/superpowers/reviews/2026-08-06-l2-gl-gate.md` (I-4, ruling 6c)
- `docs/superpowers/tickets/2026-08-06-l2-gl-vat-declaration-desync.md` (the
  sibling ticket for the AR-side VAT desync + period-lock question)
- `docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md` (F-6)
