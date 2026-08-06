# Ticket: remaining `balance_due ?? total` / stale-cache consumers (D2 sweep gap)

**Filed:** 2026-08-06, by the L2 AR-integrity fix lane's dual-gate round, from
`docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md` ("The D2 consumer
sweep stops short").
**Status:** OPEN.

## Background

W-6 D2 is: `documents.balance_due` is a PostgreSQL trigger cache that only
fires on `payment_allocations` / `credit_note_allocations` DML, so it is NULL
on any posted document that has never been allocated against. `?? total`
fallbacks then offer the document's FULL total instead of what allocations
already exist elsewhere (e.g. rows inserted outside the trigger's DML shape,
or a manual/legacy write path). This round fixed `Document::outstandingBalance()`
(the canonical read) and swept several call sites onto it — see
`docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md` CRITICAL 2 and this
lane's commits. The sites below were named explicitly by the gate as **not**
swept, and are out of scope for this round (merge-gate blast-radius
discipline) but must not be forgotten.

## Remaining sites

1. **`PaymentController::storeMultiple()`** — the multi-line payment path:
   - `:1394` — `$documentBalance = $primaryDocument->balance_due ?? $primaryDocument->total;`
     (the PRIMARY document's cap for the whole multi-line batch).
   - `:1699` and `:1766` — `$targetBalance = $targetDoc->balance_due ?? $targetDoc->total;`
     inside the excess-allocation loop (both the FIFO and due-date-priority
     branches use the identical line, hence two hits).
   This is the *exact* over-allocation shape already fixed at the single-payment
   path (`PaymentController.php:505`, this round's CRITICAL 2 changes) — a
   150-allocated-elsewhere, cache-blind 200 invoice would still accept another
   200 through the multi-line path.
2. **`PaymentAllocationService::getOpenInvoices()`** (around `:483`, FIFO/due-date
   auto-allocation candidate query) — decides "still open" with
   `whereRaw('total > COALESCE((SELECT SUM(amount) FROM payment_allocations
   WHERE document_id = documents.id), 0)')`. This ignores
   `credit_note_allocations` entirely: a fully credit-noted invoice (zero
   `payment_allocations`, full `credit_note_allocations`) is still offered to
   automatic FIFO/due-date allocation as if nothing had reduced its balance.
3. **`PaymentAllocationService::getInvoiceBalance()`** (around `:696-705`) — the
   cap for the manual smart-payment path — has the identical
   `credit_note_allocations`-blind omission: `$total - Σpayment_allocations`
   only, no credit subtraction.
4. **`SmartPaymentController::previewAllocation()`** (around `:183`) —
   `->whereRaw('COALESCE(balance_due, total) > 0')` when listing a partner's
   open invoices. `COALESCE` handles the NULL-cache case correctly (falls to
   `total`, matching an unallocated document), but still ignores
   `credit_note_allocations` — the same blindness as (2)/(3): a fully
   credit-noted invoice with a stale/NULL cache is still listed as open.

## Why not fixed in this round

The round's explicit scope was the F-6 resurrection paths, the D2
opening-balance regression, and the items the two dual-gate reviews marked
CRITICAL/IMPORTANT for merge. These four sites are a real defect class but a
DIFFERENT set of call sites already reviewed once (the multi-line payment path
and the smart-payment auto-allocation path) — sweeping them here would restart
review scope on files not otherwise touched by this round, which the gate
explicitly declined to require before merge.

## Suggested fix

Route all four through `Document::outstandingBalance($scale)` (or a shared cap
helper built on it), exactly as done for the single-payment path in this round:

- `PaymentController::storeMultiple()` — replace both `?? total` reads (primary
  and both excess-cap sites) with `outstandingBalance()`.
- `PaymentAllocationService::getOpenInvoices()` — the `whereRaw` pre-filter can
  stay coarse (matching `Document::scopeWhereOutstanding()`'s own doctrine —
  a SQL bound is fine as a pre-filter as long as callers still take the exact
  amount from `outstandingBalance()`), but must additionally subtract
  `credit_note_allocations` in the `whereRaw`, mirroring
  `Document::OUTSTANDING_BALANCE_SQL`.
- `PaymentAllocationService::getInvoiceBalance()` — replace the hand-rolled
  `total - Σpayment_allocations` with `$invoice->outstandingBalance($scale)`
  directly; it already computes the exact same formula plus the missing
  credit-note term.
- `SmartPaymentController::previewAllocation()` — replace the `whereRaw` with
  `Document::scopeWhereOutstanding()`, and audit whether the `open_invoices`
  response payload derives its displayed balance from `getInvoiceBalance()`
  (fixed by the item above) or its own separate read.

All four are read paths only (no schema/migration involved) and each is a
small, independently testable change — good candidates for a fast follow-up
lane, but each needs its own red→green test per CLAUDE.md TDD discipline
rather than a single blanket sweep commit.

## Related

- `docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md` ("The D2 consumer
  sweep stops short")
- `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md` (D2, origin)
